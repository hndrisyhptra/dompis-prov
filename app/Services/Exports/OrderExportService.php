<?php

namespace App\Services\Exports;

use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class OrderExportService
{
    public function document(Builder $query, bool $priority = false): array
    {
        $model = $query->getModel();
        $definitions = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        $columns = array_map(fn ($column) => $this->databaseColumn($column), $definitions);

        return ['title' => $priority ? 'Order Prioritas' : 'Work Order', 'columns' => $columns,
            'rows' => $this->rows($query, array_column($definitions, 'name')),
            'note' => 'Seluruh kolom fisik work_orders, nama dan urutan sesuai database. Nilai tersimpan termasuk JSON sumber/override/hash; timestamp mengikuti UTC database. Filter/urutan dan akses tetap berlaku, seluruh halaman diunduh.'];
    }

    private function rows(Builder $query, array $names): \Generator
    {
        foreach ($query->lazy(250) as $order) {
            $row = [];
            foreach ($names as $name) {
                // Read storage values, not accessors such as live aging or display progress labels.
                $value = $order->getRawOriginal($name);
                if (is_string($value) && strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > 65534) {
                    throw ValidationException::withMessages(['export' => 'Kolom '.$name.' pada order '.$order->getRawOriginal('no_tq_sc_id').' melebihi batas 32.767 karakter Excel. Persempit filter atau periksa data order; file tidak dibuat agar nilai tidak terpotong.']);
                }
                $row[] = $value;
            }
            yield $row;
        }
    }

    public function context(User $user, array $filters, bool $priority): array
    {
        $labels = [];
        if (! empty($filters['region'])) {
            $labels[] = 'Region: '.Region::withTrashed()->find($filters['region'])?->name;
        }
        $labels[] = 'Branch: '.(! empty($filters['branch']) ? Branch::withTrashed()->find($filters['branch'])?->name : ($user->role->areaScope() ? 'Semua Branch' : 'Penugasan akun'));
        $type = $priority ? 'all' : ($filters['jenis_pekerjaan'] ?? 'NEW INSTALL');
        $labels[] = 'Jenis pekerjaan: '.($type === 'all' ? 'Semua jenis' : $type);
        foreach (['service' => 'Layanan', 'q' => 'Pencarian', 'queue' => 'Antrean', 'owner_group' => 'Owner Group', 'bucket' => 'Kategori progress', 'order_month' => 'Bulan order', 'ps_date' => 'Tanggal PS', 'status' => 'Status', 'without_region' => 'Tanpa Region', 'unassigned' => 'Belum ditugaskan',
            'source' => 'Sumber prioritas', 'state' => 'Status monitoring', 'sort' => 'Urutan'] as $key => $label) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $labels[] = $label.': '.$filters[$key];
            }
        }

        return ['scope' => $priority ? 'Order Prioritas' : 'Work Order', 'filters' => implode(' · ', $labels), 'at' => CarbonImmutable::now('Asia/Jakarta')->format('d M Y H:i:s')];
    }

    private function databaseColumn(array $column): array
    {
        $label = $column['name'];
        $databaseType = strtolower($column['type_name']);
        $type = match (true) {
            $databaseType === 'date' => 'date',
            in_array($databaseType, ['datetime', 'timestamp', 'timestamptz', 'timestamp without time zone'], true) => 'datetime',
            ! str_starts_with($label, 'id_') && in_array($databaseType, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bool', 'boolean'], true) => 'number',
            default => 'text',
        };
        $width = match (true) {
            in_array($label, ['alamat', 'keterangan', 'source_bima_fields', 'device_overrides'], true) => 60,
            in_array($label, ['status_order', 'source_snapshot_hash', 'task_bima'], true) => 48,
            $type === 'datetime' => 26,
            default => 24,
        };

        return compact('label', 'type', 'width');
    }
}
