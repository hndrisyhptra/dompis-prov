<?php

namespace App\Console\Commands;

use App\Models\BimaTaskLog;
use App\Services\BimaTaskLogPresentation;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class CheckBimaLogEncryption extends Command
{
    protected $signature = 'olo:check-bima-log-encryption {--work-order= : ID numerik dari URL Detail Order; kosong untuk seluruh log}';

    protected $description = 'Periksa keterbacaan Log Task BIMA tanpa mengubah data atau menampilkan kunci dan isi log';

    public function handle(BimaTaskLogPresentation $presentation): int
    {
        $id = $this->option('work-order');
        if ($id !== null && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Gunakan ID Work Order numerik positif dari URL detail.');

            return self::INVALID;
        }
        $counts = ['read' => 0, 'unreadable' => 0, 'decrypt_failed' => 0, 'invalid_format' => 0];
        $failedIds = [];
        try {
            $query = BimaTaskLog::select(['id_bima_task_logs', 'id_work_orders', 'field_changes'])->when($id !== null, fn ($q) => $q->where('id_work_orders', (int) $id));
            foreach ($query->lazyById(100, 'id_bima_task_logs') as $log) {
                $result = $presentation->read($log);
                $counts['read']++;
                if (! $result['readable']) {
                    $counts['unreadable']++;
                    $counts[$result['reason']]++;
                    if (count($failedIds) < 20) {
                        $failedIds[] = $log->getKey();
                    }
                }
            }
        } catch (QueryException) {
            $this->error('Pemeriksaan database belum selesai. Periksa koneksi dan migrasi Log Task BIMA.');

            return self::FAILURE;
        }
        $this->line('Diperiksa: '.$counts['read'].'; Dapat dibaca: '.($counts['read'] - $counts['unreadable']).'; Tidak dapat dibaca: '.$counts['unreadable']);
        $this->line('Masalah enkripsi: '.$counts['decrypt_failed'].'; Format tidak sesuai: '.$counts['invalid_format']);
        if ($failedIds) {
            $this->warn('ID Log Task BIMA yang perlu diperiksa (maksimal 20): '.implode(', ', $failedIds));
            $this->warn('Periksa APP_KEY/APP_PREVIOUS_KEYS melalui pengelola server dan backup tepercaya. Jangan kirim kunci di chat atau membuat kunci baru untuk memperbaiki log lama.');
        }
        if (! $counts['read']) {
            $this->line('Tidak ada log dalam cakupan ini; hasil ini belum memverifikasi kunci terhadap data lama.');
        }
        $this->line('Pemeriksaan hanya membaca. Tidak ada data/kunci yang diubah dan tidak ada isi log yang ditampilkan.');

        return $counts['unreadable'] ? self::FAILURE : self::SUCCESS;
    }
}
