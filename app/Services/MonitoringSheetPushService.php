<?php

namespace App\Services;

use App\Contracts\GoogleSheetGateway;
use App\Models\AuditLog;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class MonitoringSheetPushService
{
    public const HEADERS = ['No WO/TQ-SC-ID', 'SID', 'Regional', 'Branch', 'STO', 'Layanan', 'Jenis Pekerjaan', 'Status Bima', 'Status Final Bima', 'Status Operasional', 'Status Verifikasi', 'Kategori Kendala Aktif', 'Detail Kendala', 'PIC', 'Target Selesai', 'Terakhir Update', 'Selesai Lapangan', 'Completed Tervalidasi', 'Aging', 'Last Push At'];

    public function __construct(private GoogleSheetGateway $gateway) {}

    public function push(SyncJob $job): void
    {
        if (! $job->snapshot) {
            $rows = DB::transaction(function () {
                $orders = WorkOrder::where('source_system', 'BIMA')->with(['branch', 'issues' => fn ($q) => $q->where('status', 'OPEN')])->orderBy('external_order_id')->get();
                if ($orders->isEmpty()) {
                    throw new \InvalidArgumentException('Tidak ada WO untuk dipublikasikan. Snapshot kosong tidak dikirim.');
                }
                $stamp = now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                $format = fn ($date) => $date?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') ?? '';
                $rows = [self::HEADERS];
                foreach ($orders as $wo) {
                    $issue = $wo->issues->first();
                    $rows[] = [$wo->external_order_id, $wo->source_sid ?? '', $wo->regional ?? '', $wo->branch?->name ?? '', $wo->sto_code ?? '', $wo->service_name ?? '', $wo->job_type ?? '',
                        $wo->bima_status ?? '', $wo->bima_final_status ?? '', $wo->progress_category ?? $wo->operational_status, $wo->verification_status,
                        $wo->issues->pluck('category')->unique()->implode(', '), $wo->issues->pluck('description')->implode('; '), $issue?->pic ?? '', $issue?->eta?->format('Y-m-d') ?? '',
                        $format($wo->last_progress_at), $format($wo->field_done_at), $format($wo->verified_at),
                        $wo->order_date ? (string) max(0, (int) $wo->order_date->diffInDays(now()->timezone('Asia/Jakarta')->startOfDay(), false)) : '', $stamp];
                }

                return $rows;
            });
            $job->update(['snapshot' => $rows]);
        }
        $rows = $job->snapshot;
        $this->gateway->publishMonitoring($rows);
        DB::transaction(function () use ($job, $rows) {
            $stats = ['read' => count($rows) - 1, 'published' => count($rows) - 1];
            $job->update(['state' => 'success', 'stats' => $stats, 'finished_at' => now(), 'error' => null, 'snapshot' => null]);
            AuditLog::create(['id_users' => $job->id_users, 'action' => 'monitoring.pushed', 'entity_type' => 'sync_job', 'id_entity' => $job->getKey(),
                'metadata' => $stats + ['checksum' => hash('sha256', json_encode($rows))]]);
        });
    }
}
