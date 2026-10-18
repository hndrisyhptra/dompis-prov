<?php

namespace App\Console\Commands;

use App\Contracts\GoogleSheetGateway;
use App\Services\BimaRowMapper;
use App\Services\Sheets\SheetException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class CheckGoogleSheets extends Command
{
    protected $signature = 'olo:sheets:check';

    protected $description = 'Read and validate BIMA_RAW without writing Sheets or importing work orders';

    public function handle(GoogleSheetGateway $gateway, BimaRowMapper $mapper): int
    {
        if (config('olo.sheets.driver') === 'fake') {
            $this->warn('SIMULASI: hasil ini tidak memverifikasi koneksi Google nyata.');
        }
        try {
            $rows = $gateway->readBima();
            if (count($rows) > config('olo.sheets.max_rows') + 1) {
                throw new InvalidArgumentException('BIMA_RAW melebihi batas 10.000 baris.');
            }
            $mapper->validateHeader(array_shift($rows) ?? []);
            $valid = 0;
            $invalid = 0;
            $counts = [];
            foreach ($rows as $row) {
                if (collect(array_slice($row, 0, 28))->every(fn ($value) => $value === null || $value === '')) {
                    continue;
                }
                try {
                    $data = $mapper->map($row);
                    $valid++;
                    $id = $data['external_order_id'];
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                } catch (InvalidArgumentException) {
                    $invalid++;
                }
            }
            $duplicates = count(array_filter($counts, fn ($count) => $count > 1));
            $this->info('BIMA_RAW terbaca dan header 32 kolom sesuai kontrak.');
            $this->line("Baris valid format: {$valid}; baris ditolak format: {$invalid}; ID duplikat: {$duplicates}.");
            $this->line('Tidak ada perubahan Google Sheets atau impor MySQL. Lanjutkan melalui preview di Sync Center untuk memeriksa mapping branch.');

            return ($invalid || $duplicates) ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof SheetException || $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Pemeriksaan Google gagal. Periksa koneksi jaringan dan konfigurasi service account.');

            return self::FAILURE;
        }
    }
}
