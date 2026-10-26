<?php

namespace App\Services\Exports;

use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use App\Services\AreaAnalyticsService;
use App\Services\BranchAnalyticsService;
use App\Services\BranchOrderScope;
use App\Support\ProgressSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DashboardExportService
{
    public function __construct(private BranchOrderScope $scope) {}

    public function table(User $user, array $filters, string $table, bool $area): array
    {
        if ($table === 'attention') {
            $query = $this->scope->orders($user, $filters)->outstanding()->with('assignedBranch')
                ->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', 'OPEN')])
                ->orderByDesc('open_issues_count')->orderByRaw('CASE WHEN last_progress_at IS NULL THEN 0 ELSE 1 END')->orderBy('tgl_order')->orderBy('id_work_orders');

            return $this->orders($query, 'Order untuk ditindaklanjuti', 'Seluruh outstanding sesuai filter. Dashboard menampilkan enam order teratas. Waktu pembaruan dalam WIB.');
        }
        $data = $area ? app(AreaAnalyticsService::class)->summary($user, $filters) : app(BranchAnalyticsService::class)->summary($user, $filters);
        if ($table === 'progress') {
            $rows = [];
            foreach ($data['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = [$group['name'], $row['name'].($row['inactive'] ? ' · nonaktif' : ''), ...array_values($row['counts'])];
                }
            }
            $rows[] = ['values' => ['Grand Total', '', ...array_values($data['grand'])], 'total' => true];

            return ['title' => 'Progress Region dan Branch', 'columns' => [$this->column('Region', width: 28), $this->column('Branch', width: 32), ...array_map(fn ($label) => $this->column($label, 'number'), array_values(ProgressSummary::LABELS))],
                'rows' => $rows, 'note' => 'Rekap akumulatif. Complete mencakup Cancel/CLOSE; PS tetap CLOSE + 14 Status BIMA. New Order hari ini: TGL ORDER hari ini WIB. Seluruh Branch dalam Region terlipat ikut diunduh.'];
        }
        if ($table === 'owners') {
            $owners = $data['ownerGroups'];
            $rows = array_map(fn ($row) => [$row['region_name'], $row['name'], ...array_values($row['counts']), $row['total']], $owners['rows']);
            $rows[] = ['values' => ['Grand Total', '', ...array_values($owners['totals']), $owners['total']], 'total' => true];

            return ['title' => 'Distribusi Owner Group', 'columns' => [$this->column('Region', width: 28), $this->column('Branch', width: 32), ...array_map(fn ($label) => $this->column($label, 'number'), [...$owners['groups'], 'Belum dipetakan', 'Grand Total'])],
                'rows' => $rows, 'note' => 'Rekap akumulatif berdasarkan mapping aktif. Kosong/tidak dikenal/nonaktif: Belum dipetakan. Seluruh Branch dalam Region terlipat ikut diunduh.'];
        }
        if ($table === 'months') {
            return ['title' => 'Penyelesaian bulan order', 'columns' => [$this->column('Bulan order', width: 28), ...array_map(fn ($label) => $this->column($label, 'number'), ['Total WO', 'Progress CLOSE', 'Sudah PS', 'Outstanding'])],
                'rows' => array_map(fn ($row) => [$row['label'], $row['total'], $row['closed'], $row['ps'], $row['outstanding']], $data['orderMonths']['rows']),
                'note' => 'Bulan berdasarkan TGL ORDER, status terkini. PS bagian dari Progress CLOSE. '.$data['orderMonths']['undated'].' order tanpa TGL ORDER tidak masuk rekap ini.'];
        }
        if ($table === 'types') {
            $metrics = ['total' => 'WO total', 'incoming_today' => 'WO hari ini', 'ps_total' => 'Completed total', 'ps_today' => 'Completed hari ini', 'outstanding' => 'Order open'];
            $rows = [];
            foreach ($metrics as $key => $label) {
                $rows[] = [$label, ...array_column(array_values($data['recap']), $key), $data['summary'][$key]];
            }

            return ['title' => 'Rekap jenis pekerjaan', 'columns' => [$this->column('Keterangan', width: 28), ...array_map(fn ($label) => $this->column($label, 'number'), [...array_keys($data['recap']), 'Total'])], 'rows' => $rows,
                'note' => 'Rekap akumulatif. Completed/PS = Progress CLOSE + salah satu 14 Status BIMA. Perolehan hari ini menggunakan TGL PS hari ini WIB.'];
        }
        $period = $area ? $data['month']->format('m/Y') : count($data['series']).' hari terakhir';

        return ['title' => $area ? 'Perolehan PS harian' : 'Perolehan complete harian', 'columns' => [$this->column('Tanggal PS · WIB', 'date', 22), $this->column('Jumlah PS', 'number'), $this->column('Keterangan', width: 32)],
            'rows' => array_map(fn ($point) => [$point['date'], ($point['future'] ?? false) ? null : $point['count'], ($point['future'] ?? false) ? 'Belum berjalan' : ''], $data['series']),
            'note' => 'Periode '.$period.'. Berdasarkan TGL PS BIMA dan definisi PS. Hari berjalan tanpa PS = 0; tanggal mendatang kosong (belum berjalan).'];
    }

    public function orders(Builder $query, string $title = 'Detail order dashboard', string $note = 'Seluruh hasil pilihan sesuai filter, tidak dibatasi halaman modal. Waktu pembaruan dalam WIB.'): array
    {
        return ['title' => $title, 'note' => $note, 'columns' => [$this->column('No WO / TQ-SC-ID', width: 28), $this->column('Branch', width: 30), $this->column('STO', width: 16), $this->column('Nama OLO', width: 38),
            $this->column('Status Progress', width: 24), $this->column('Status BIMA', width: 62), $this->column('Kendala aktif', 'number'), $this->column('TGL ORDER', 'date', 20), $this->column('Update terakhir · WIB', 'datetime', 26)],
            'rows' => $this->orderRows($query)];
    }

    private function orderRows(Builder $query): \Generator
    {
        foreach ($query->lazy(250) as $order) {
            yield [$order->no_tq_sc_id, $order->assignedBranch?->name ?? 'Belum terpetakan', $order->sto, $order->nama_olo, $order->progressLabel(), $order->status_order,
                $order->open_issues_count, $order->tgl_order, $order->last_progress_at?->timezone('Asia/Jakarta')];
        }
    }

    public function context(User $user, array $filters, bool $area): array
    {
        $labels = [];
        if (! empty($filters['region'])) {
            $labels[] = 'Region: '.Region::withTrashed()->find($filters['region'])?->name;
        }
        $labels[] = 'Branch: '.(! empty($filters['branch']) ? Branch::withTrashed()->find($filters['branch'])?->name : ($user->role->areaScope() ? 'Semua Branch' : 'Penugasan akun'));
        $type = $filters['jenis_pekerjaan'] ?? ($area ? 'all' : 'NEW INSTALL');
        $labels[] = 'Jenis pekerjaan: '.($type === 'all' ? 'Semua jenis' : $type);
        foreach (['service' => 'Layanan', 'month' => 'Bulan grafik PS', 'days' => 'Hari grafik', 'q' => 'Pencarian', 'queue' => 'Antrean', 'owner_group' => 'Owner Group', 'bucket' => 'Kategori progress', 'order_month' => 'Bulan order', 'ps_date' => 'Tanggal PS', 'status' => 'Status', 'without_region' => 'Tanpa Region', 'unassigned' => 'Belum ditugaskan'] as $key => $label) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $labels[] = $label.': '.$filters[$key];
            }
        }

        return ['scope' => $area ? 'Dashboard Area' : 'Dashboard Branch', 'filters' => implode(' · ', $labels), 'at' => CarbonImmutable::now('Asia/Jakarta')->format('d M Y H:i:s')];
    }

    private function column(string $label, string $type = 'text', int $width = 18): array
    {
        return compact('label', 'type', 'width');
    }
}
