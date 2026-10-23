<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MappingOrder;
use App\Models\WorkOrder;
use App\Services\OrderMappingReferenceService;
use App\Services\WorkOrderMappingResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OrderMappingReferenceTest extends OloTestCase
{
    public function test_all_178_owner_rules_including_open_and_empty_task_keys_are_applied_exactly_and_idempotently(): void
    {
        $service = app(OrderMappingReferenceService::class);
        $reference = $service->reference();
        $this->assertCount(178, $reference['rules']);
        $this->assertSame(['OPEN' => 106, 'CANCLWORK' => 28, 'STUCK' => 30, 'PS' => 14], collect($reference['rules'])->countBy('mapping_order')->all());
        $stats = $service->apply($reference, $this->area);
        $this->assertSame(178, $stats['rules_inserted']);
        $ids = MappingOrder::orderBy('id_mapping_order')->pluck('id_mapping_order')->all();
        foreach ($reference['rules'] as $index => $rule) {
            $split = strrpos($rule['status_order'], '-');
            $task = substr($rule['status_order'], 0, $split);
            $status = substr($rule['status_order'], $split + 1);
            $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => '000REF-'.$index, 'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', (string) $index), 'task_bima' => $task, 'status_bima' => $status]);
            $this->assertSame($rule['mapping_order'], $order->mapping_order, $rule['status_order']);
            $this->assertNotNull($order->id_mapping_order);
        }
        $stats = $service->apply($reference, $this->area);
        $this->assertSame(178, $stats['rules_unchanged']);
        $this->assertSame(0, $stats['rules_updated']);
        $this->assertSame(0, $stats['orders_updated']);
        $this->assertSame(178, $stats['orders_matched']);
        $this->assertSame($ids, MappingOrder::orderBy('id_mapping_order')->pluck('id_mapping_order')->all());
        $this->assertSame('PS', app(WorkOrderMappingResolver::class)->resolve(null, 'COMPWORK')->mapping_order);
        $this->assertSame('OPEN', app(WorkOrderMappingResolver::class)->resolve('', 'STARTWORK')->mapping_order);
        $this->assertSame('STUCK', app(WorkOrderMappingResolver::class)->resolve('Pickup NTE from SCM Manual', 'CANCLWORK')->mapping_order);
        $this->assertNull(app(WorkOrderMappingResolver::class)->resolve('Unknown', 'COMPLETE'));
        $this->assertNull(app(WorkOrderMappingResolver::class)->resolve('approval e2e testing manual', 'COMPLETE'));
        $this->assertDatabaseCount('bima_task_logs', 0);
    }

    public function test_backfill_updates_only_classification_and_preserves_unlisted_rules_progress_devices_source_hash_and_timestamps(): void
    {
        $this->pull([$this->row('MAPPED', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE']), $this->row('UNKNOWN', [14 => 'Unknown', 13 => 'OPEN'])]);
        $order = WorkOrder::where('no_tq_sc_id', 'MAPPED')->firstOrFail();
        $order->update(['progress_category' => 'SURVEY', 'revision' => 9, 'odp' => 'ODP-LOCAL', 'device_overrides' => ['odp']]);
        $unlisted = MappingOrder::create(['status_order' => 'Other-OPEN', 'mapping_order' => 'STUCK']);
        $before = (array) DB::table('work_orders')->where('id_work_orders', $order->getKey())->first();
        $this->area->forceFill(['username' => 'area-mapping'])->save();
        $this->artisan('olo:apply-order-mapping', ['--actor' => $this->area->username])->assertSuccessful();
        $after = (array) DB::table('work_orders')->where('id_work_orders', $order->getKey())->first();
        foreach ($before as $field => $value) {
            if (! in_array($field, ['id_mapping_order', 'mapping_order'], true)) {
                $this->assertSame($value, $after[$field], $field);
            }
        }
        $this->assertSame('PS', $order->fresh()->mapping_order);
        $this->assertFalse($order->fresh()->isPsEligible());
        $this->assertSame('STUCK', $unlisted->fresh()->mapping_order);
        $this->assertNull(WorkOrder::where('no_tq_sc_id', 'UNKNOWN')->first()->mapping_order);
        $audit = AuditLog::where('action', 'order_mapping.applied')->sole();
        $this->assertSame(1, $audit->metadata['stats']['orders_matched']);
        $this->assertSame(1, $audit->metadata['stats']['orders_unmapped']);
        $this->assertSame($this->area->getKey(), $audit->id_users);
        // A loaded form/order may hold the old classification across a refresh.
        $order->update(['progress_category' => 'OGP BIMA']);
        $this->assertSame('PS', $order->fresh()->mapping_order);
    }

    public function test_inbound_updates_reclassify_without_duplicating_logs_and_unknown_statuses_clear_old_mapping(): void
    {
        app(OrderMappingReferenceService::class)->apply(app(OrderMappingReferenceService::class)->reference());
        $this->pull([$this->row('FLOW', [14 => 'Approval E2E Testing Manual', 13 => 'STARTWORK'])]);
        $order = WorkOrder::sole();
        $this->assertSame('OPEN', $order->mapping_order);
        $this->pull([$this->row('FLOW', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE'])]);
        $this->assertSame('PS', $order->fresh()->mapping_order);
        $this->pull([$this->row('FLOW', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE'])]);
        $this->assertDatabaseCount('bima_task_logs', 1);
        $this->pull([$this->row('FLOW', [14 => 'Unknown', 13 => 'COMPLETE'])]);
        $this->assertNull($order->fresh()->mapping_order);
        $this->assertNull($order->fresh()->id_mapping_order);
        $this->assertDatabaseCount('work_orders', 1);
    }

    public function test_conflicting_or_invalid_reference_is_rejected_before_writes_and_running_import_is_locked_out(): void
    {
        $service = app(OrderMappingReferenceService::class);
        foreach ([[], [['status_order' => 'Test-OPEN', 'mapping_order' => 'WRONG']], [['status_order' => 'Test-OPEN', 'mapping_order' => 'OPEN'], ['status_order' => 'Test-OPEN', 'mapping_order' => 'PS']]] as $rules) {
            try {
                $service->apply(['rules' => $rules]);
                $this->fail('Invalid reference accepted');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('mapping_order', 0);
            }
        }
        $lock = Cache::lock('olo:work-order-inbound', 240);
        $lock->get();
        try {
            $service->apply($service->reference());
            $this->fail('Concurrent inbound not blocked');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sedang berjalan', $exception->getMessage());
            $this->assertDatabaseCount('mapping_order', 0);
        } finally {
            $lock->release();
        }
        $branch = $this->user(Role::BranchHd);
        $branch->forceFill(['username' => 'branch-mapping'])->save();
        $this->artisan('olo:apply-order-mapping', ['--actor' => $branch->username])->assertFailed();
        $this->assertDatabaseCount('mapping_order', 0);
    }

    public function test_existing_rule_ids_are_preserved_changes_audited_and_inactive_rules_reactivated(): void
    {
        $mapping = MappingOrder::create(['status_order' => 'Approval E2E Testing Manual-COMPLETE', 'mapping_order' => 'STUCK', 'active' => false]);
        $service = app(OrderMappingReferenceService::class);
        $stats = $service->apply($service->reference(), $this->area);
        $this->assertSame(1, $stats['rules_updated']);
        $this->assertSame($mapping->getKey(), $mapping->fresh()->getKey());
        $this->assertTrue($mapping->fresh()->active);
        $this->assertSame('PS', $mapping->fresh()->mapping_order);
        $this->assertSame('STUCK', AuditLog::where('action', 'order_mapping.applied')->sole()->metadata['rule_changes'][0]['before']['mapping_order']);
    }
}
