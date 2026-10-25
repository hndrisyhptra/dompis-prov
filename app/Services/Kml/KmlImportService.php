<?php

namespace App\Services\Kml;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderKml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class KmlImportService
{
    public static function available(): bool
    {
        return Schema::hasTable('work_order_kmls');
    }

    public function import(User $actor, WorkOrder $workOrder, UploadedFile $file, array $data): array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $workOrder, $file, $data, &$path) {
                $order = WorkOrder::lockForUpdate()->findOrFail($workOrder->getKey());
                $actor = $actor->fresh();
                Gate::forUser($actor)->authorize('uploadKml', $order);
                abort_unless($order->revision === (int) $data['order_revision'], 409, 'Order berubah. Muat ulang sebelum upload KML.');
                $latest = $order->kmls()->latest('revision')->first();
                $hash = hash_file('sha256', $file->getRealPath());
                if ($latest && hash_equals($latest->file_hash, $hash)) {
                    return ['kml' => $latest, 'changed' => false];
                }
                abort_unless(($latest?->revision ?? 0) === (int) $data['kml_revision'], 409, 'KML sudah diperbarui. Muat ulang sebelum mengganti.');
                $parsed = app(KmlReader::class)->read(file_get_contents($file->getRealPath()));
                $path = 'kml/'.Str::uuid().'.kml';
                if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
                    throw new \RuntimeException('File KML belum dapat disimpan.');
                }
                $kml = WorkOrderKml::create($parsed + ['id_work_orders' => $order->getKey(), 'revision' => ($latest?->revision ?? 0) + 1,
                    'file_hash' => $hash, 'file_path' => $path, 'id_users' => $actor->getKey(),
                    'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())), 0, 255)]);
                AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'work_order.kml_uploaded', 'entity_type' => 'work_order_kml', 'id_entity' => $kml->getKey(),
                    'metadata' => ['id_work_orders' => $order->getKey(), 'revision' => $kml->revision, 'previous_id' => $latest?->getKey(), 'file_hash' => $hash, 'feature_count' => $kml->feature_count]]);

                return ['kml' => $kml, 'changed' => true];
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}
