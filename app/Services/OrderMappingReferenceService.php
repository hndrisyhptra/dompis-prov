<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MappingOrder;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OrderMappingReferenceService
{
    public function reference(): array
    {
        return json_decode(file_get_contents(database_path('data/order_mapping_20261020.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function apply(array $reference, ?User $actor = null): array
    {
        $rules = $reference['rules'] ?? [];
        $seen = [];
        if (! $rules) {
            throw new InvalidArgumentException('Referensi mapping kosong.');
        }
        foreach ($rules as $rule) {
            $key = $rule['status_order'] ?? null;
            if (! is_string($key) || $key === '' || trim($key) !== $key || mb_strlen($key) > 511 || isset($seen[$key])
                || ! in_array($rule['mapping_order'] ?? null, ['OPEN', 'STUCK', 'PS', 'CANCLWORK'], true)) {
                throw new InvalidArgumentException('Referensi mapping tidak valid atau memiliki kunci duplikat. Tidak ada perubahan disimpan.');
            }
            $seen[$key] = true;
        }
        $lock = Cache::lock('olo:work-order-inbound', 240);
        if (! $lock->get()) {
            throw new RuntimeException('Import work order sedang berjalan. Coba terapkan mapping setelah import selesai.');
        }
        try {
            return DB::transaction(function () use ($reference, $rules, $actor) {
                $stats = ['rules_inserted' => 0, 'rules_updated' => 0, 'rules_unchanged' => 0, 'orders_updated' => 0, 'orders_matched' => 0, 'orders_unmapped' => 0];
                $changes = [];
                foreach ($rules as $rule) {
                    $mapping = MappingOrder::where('status_order', $rule['status_order'])->lockForUpdate()->first();
                    if (! $mapping) {
                        MappingOrder::create($rule + ['active' => true]);
                        $stats['rules_inserted']++;
                    } elseif ($mapping->mapping_order !== $rule['mapping_order'] || ! $mapping->active) {
                        $changes[] = ['id_mapping_order' => $mapping->getKey(), 'before' => $mapping->only(['mapping_order', 'active']), 'after' => ['mapping_order' => $rule['mapping_order'], 'active' => true]];
                        $mapping->update(['mapping_order' => $rule['mapping_order'], 'active' => true]);
                        $stats['rules_updated']++;
                    } else {
                        $stats['rules_unchanged']++;
                    }
                }
                WorkOrder::where('source_system', 'BIMA')->orderBy('id_work_orders')->lockForUpdate()->chunkById(200, function ($orders) use (&$stats) {
                    foreach ($orders as $order) {
                        $mapping = app(WorkOrderMappingResolver::class)->resolve($order->task_bima, $order->status_bima);
                        $stats[$mapping ? 'orders_matched' : 'orders_unmapped']++;
                        if ($order->id_mapping_order !== $mapping?->getKey() || $order->mapping_order !== $mapping?->mapping_order) {
                            // Only derived classification is backfilled: no model save, timestamp, source hash or progress changes.
                            DB::table('work_orders')->where('id_work_orders', $order->getKey())->update(['id_mapping_order' => $mapping?->getKey(), 'mapping_order' => $mapping?->mapping_order]);
                            $stats['orders_updated']++;
                        }
                    }
                }, 'id_work_orders');
                AuditLog::create(['id_users' => $actor?->getKey(), 'action' => 'order_mapping.applied', 'entity_type' => 'mapping_order_reference', 'id_entity' => 0,
                    'metadata' => ['source' => $reference['source'] ?? null, 'sha256' => $reference['sha256'] ?? null, 'stats' => $stats, 'rule_changes' => $changes]]);

                return $stats;
            }, 3);
        } finally {
            $lock->release();
        }
    }
}
