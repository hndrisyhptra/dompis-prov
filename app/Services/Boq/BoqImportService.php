<?php

namespace App\Services\Boq;

use App\Models\AuditLog;
use App\Models\BoqJt;
use App\Models\Designator;
use App\Models\DesignatorPackagePrice;
use App\Models\Package;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderXlsxReader;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class BoqImportService
{
    public static function available(): bool
    {
        return Schema::hasColumns('boq_jt', ['material_total', 'labor_total']);
    }

    public function eligible(WorkOrder $order): bool
    {
        return $order->progress_category === 'JT' || $order->events()->where(fn ($q) => $q->where('new_progress_category', 'JT')->orWhere('old_progress_category', 'JT'))->exists();
    }

    public function parse(UploadedFile $file, Package $package, ?string $sheet = null, int $headerRow = 1): array
    {
        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            $book = $this->csv($file, $headerRow);
        } else {
            $reader = app(WorkOrderXlsxReader::class);
            $book = $reader->read($file->getRealPath(), $sheet, 1, true);
            $template = app(DompisBoqTemplate::class)->adapt($book, $package);
            $book = $template ?? ($headerRow === 1 ? $book : $reader->read($file->getRealPath(), $sheet, $headerRow));
        }
        $aliases = ['DESIGNATOR' => 'code', 'KODE DESIGNATOR' => 'code', 'DESIGNATOR CODE' => 'code', 'QTY' => 'qty', 'VOLUME' => 'qty', 'TYPE' => 'type', 'TIPE' => 'type'];
        $columns = [];
        foreach ($book['headers'] as $column => $header) {
            $key = $aliases[mb_strtoupper(trim($header))] ?? null;
            if ($key) {
                if (isset($columns[$key])) {
                    throw new InvalidArgumentException('Kolom '.$header.' ganda. Gunakan satu kolom untuk setiap atribut.');
                }
                $columns[$key] = $column;
            }
        }
        if (! isset($columns['code'], $columns['qty'])) {
            throw new InvalidArgumentException('Header wajib: DESIGNATOR, QTY. TYPE opsional dan mengikuti master designator. Gunakan template RAB/BOQ.');
        }
        if (count($book['rows']) > 2000) {
            throw new InvalidArgumentException('Maksimal 2.000 item per BOQ.');
        }
        $designators = Designator::with('type')->get()->groupBy(fn ($row) => mb_strtoupper(trim($row->code)));
        $prices = DesignatorPackagePrice::where('package_id', $package->getKey())->get()->keyBy('designator_id');
        $items = [];
        $seen = [];
        $errors = [];
        $total = BigDecimal::of('0.00');
        foreach ($book['rows'] as $index => $row) {
            try {
                $values = [];
                foreach ($columns as $key => $column) {
                    $cell = $row[$column] ?? ['value' => '', 'formula' => false, 'type' => 'inlineStr'];
                    if ($cell['formula'] || $cell['type'] === 'e') {
                        throw new InvalidArgumentException('Kolom '.$key.' harus nilai langsung tanpa formula/error.');
                    }
                    $values[$key] = trim($cell['value']);
                }
                $matches = $designators->get(mb_strtoupper($values['code']));
                if (! $matches || $matches->count() !== 1) {
                    throw new InvalidArgumentException('Designator tidak dikenal, diarsipkan, atau ambigu.');
                }
                $designator = $matches->sole();
                if (isset($seen[$designator->getKey()])) {
                    throw new InvalidArgumentException('Designator duplikat dalam file.');
                }
                $seen[$designator->getKey()] = true;
                $type = $designator->type?->code;
                if (! $type || ! $designator->type->is_active || strlen($type) > 30) {
                    throw new InvalidArgumentException('Tipe designator belum tersedia, tidak aktif, atau tidak valid.');
                }
                BoqBreakdown::bucket($type);
                if (($values['type'] ?? '') !== '' && strtoupper($values['type']) !== strtoupper($type)) {
                    throw new InvalidArgumentException('TYPE tidak sesuai master designator.');
                }
                if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $values['qty']) || ! BigDecimal::of($values['qty'])->isGreaterThan(0)) {
                    throw new InvalidArgumentException('QTY harus positif, maksimal 12 digit bulat dan 3 desimal; gunakan titik desimal tanpa pemisah ribuan.');
                }
                $price = $prices->get($designator->getKey());
                if (! $price || BigDecimal::of($price->price)->isLessThan(0)) {
                    throw new InvalidArgumentException('Harga untuk designator dan paket ini belum tersedia.');
                }
                $qty = (string) BigDecimal::of($values['qty'])->toScale(3);
                $unit = (string) BigDecimal::of($price->price)->toScale(2);
                $amount = BigDecimal::of($qty)->multipliedBy($unit)->toScale(2, RoundingMode::HalfUp);
                $total = $total->plus($amount);
                if ($total->isGreaterThan('9999999999999999.99')) {
                    throw new InvalidArgumentException('Total BOQ melebihi batas nilai yang dapat disimpan.');
                }
                $items[] = ['designator_id' => $designator->getKey(), 'designator_code' => $designator->code, 'item_name' => $designator->item_name,
                    'unit' => $designator->unit, 'type' => $type, 'qty' => $qty, 'unit_price' => $unit, 'total_price' => (string) $amount];
            } catch (InvalidArgumentException $exception) {
                $errors[] = 'Baris '.$index.': '.$exception->getMessage();
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 30)]);
        }
        if (! $items) {
            throw new InvalidArgumentException('BOQ harus memiliki setidaknya satu item dengan volume lebih dari nol. Isi kolom G (VOL) pada template DOMPIS.');
        }
        usort($items, fn ($a, $b) => $a['designator_id'] <=> $b['designator_id']);

        $breakdown = BoqBreakdown::totals($items);

        return ['items' => $items, 'grand_total' => (string) $total, 'package_id' => $package->getKey(),
            'material_total' => $breakdown['material_total'], 'labor_total' => $breakdown['labor_total']];
    }

    public function import(User $actor, WorkOrder $workOrder, UploadedFile $file, array $data): array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $workOrder, $file, $data, &$path) {
                $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
                $actor = $actor->fresh();
                Gate::forUser($actor)->authorize('importBoq', $order);
                abort_unless($this->eligible($order), 422, 'RAB/BOQ tersedia setelah order pernah diperbarui ke JT.');
                abort_unless($order->revision === (int) $data['order_revision'], 409, 'Order berubah. Muat ulang sebelum mengunggah BOQ.');
                $boq = BoqJt::where('id_work_orders', $order->getKey())->lockForUpdate()->first();
                if ($boq && $boq->revision !== (int) $data['boq_revision'] && $boq->package_id === (int) $data['package_id']
                    && hash_equals($boq->file_hash, hash_file('sha256', $file->getRealPath()))) {
                    return ['boq' => $boq, 'changed' => false];
                }
                abort_unless(($boq?->revision ?? 0) === (int) $data['boq_revision'], 409, 'BOQ sudah diperbarui. Muat ulang sebelum mengganti.');
                $package = Package::whereKey($data['package_id'])->lockForUpdate()->first();
                if (! $package) {
                    throw ValidationException::withMessages(['package_id' => 'Paket tidak tersedia atau telah diarsipkan.']);
                }
                $parsed = $this->parse($file, $package, $data['sheet'] ?? null, (int) ($data['header_row'] ?? 1));
                // Preserve the original semantic hash contract; breakdown is derived from immutable item snapshots.
                $hash = hash('sha256', json_encode(array_intersect_key($parsed, array_flip(['items', 'grand_total', 'package_id'])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if ($boq && hash_equals($boq->content_hash, $hash)) {
                    return ['boq' => $boq, 'changed' => false];
                }
                $before = $boq ? $this->snapshot($boq) : null;
                $fileHash = hash_file('sha256', $file->getRealPath());
                $path = 'boq/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
                if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
                    throw new \RuntimeException('File BOQ belum dapat disimpan.');
                }
                $boq ??= new BoqJt(['id_work_orders' => $order->getKey(), 'created_by' => $actor->getKey()]);
                $existing = $boq->exists;
                $boq->fill(['package_id' => $package->getKey(), 'source' => 'import', 'status' => 'ready', 'item_count' => count($parsed['items']),
                    'grand_total' => $parsed['grand_total'], 'material_total' => $parsed['material_total'], 'labor_total' => $parsed['labor_total'], 'revision' => $existing ? $boq->revision + 1 : 1, 'updated_by' => $actor->getKey(),
                    'file_path' => $path, 'file_hash' => $fileHash, 'content_hash' => $hash, 'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())), 0, 255)])->save();
                $boq->items()->delete(); // Soft-delete the previous item version; history remains append-only.
                $boq->items()->createMany($parsed['items']);
                $boq->histories()->create(['id_users' => $actor->getKey(), 'event_type' => $existing ? 'updated' : 'created', 'before_data' => $before,
                    'after_data' => $this->snapshot($boq), 'note' => 'BOQ diimpor; harga paket disimpan sebagai snapshot, belum merupakan pengakuan Revenue.']);
                AuditLog::create(['id_users' => $actor->getKey(), 'action' => $existing ? 'boq.updated' : 'boq.created', 'entity_type' => 'boq_jt', 'id_entity' => $boq->getKey(),
                    'metadata' => ['id_work_orders' => $order->getKey(), 'revision' => $boq->revision, 'item_count' => $boq->item_count, 'grand_total' => $boq->grand_total, 'file_hash' => $fileHash]]);

                return ['boq' => $boq, 'changed' => true];
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function csv(UploadedFile $file, int $headerRow): array
    {
        $stream = fopen($file->getRealPath(), 'r');
        $headers = [];
        $rows = [];
        $index = 0;
        try {
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $index++;
                if ($index < $headerRow) {
                    continue;
                }
                $values = array_map(fn ($value) => (string) $value, $values);
                if ($index === $headerRow) {
                    $headers = $values;
                    $headers[0] = ltrim($headers[0] ?? '', "\xEF\xBB\xBF");

                    continue;
                }
                if (! array_filter($values, fn ($value) => trim($value) !== '')) {
                    continue;
                }
                $rows[$index] = array_map(fn ($value) => ['value' => $value, 'formula' => str_starts_with(ltrim($value), '='), 'type' => 'inlineStr'], $values);
                if (count($rows) > 2000) {
                    throw new InvalidArgumentException('Maksimal 2.000 item per BOQ.');
                }
            }
        } finally {
            fclose($stream);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function snapshot(BoqJt $boq): array
    {
        return $boq->only(['package_id', 'status', 'item_count', 'grand_total', 'material_total', 'labor_total', 'revision', 'file_path', 'file_hash', 'original_name'])
            + ['item_ids' => $boq->items()->orderBy('id_boq_items')->pluck('id_boq_items')->all()];
    }
}
