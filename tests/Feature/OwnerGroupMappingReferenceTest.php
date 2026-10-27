<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MappingOwnergrup;
use App\Models\WorkOrder;
use App\Services\OwnerGroupAnalyticsService;
use App\Services\OwnerGroupMappingReferenceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class OwnerGroupMappingReferenceTest extends OloTestCase
{
    public function test_approved_24_rules_apply_exactly_and_idempotently_without_changing_any_order_values(): void
    {
        $service = app(OwnerGroupMappingReferenceService::class);
        $reference = $service->reference();
        $this->assertCount(24, $reference['rules']);
        $this->assertSame(['DCS' => 1, 'PMD' => 4, 'SDI REGIONAL' => 3, 'ED REGIONAL' => 2, 'FF SO 3' => 1, 'FF SO 4' => 1, 'DISTRICT' => 12], collect($reference['rules'])->countBy('kelompok_ownergrup')->all());
        $this->assertSame(['rules_inserted' => 24, 'rules_updated' => 0, 'rules_unchanged' => 0], $service->preview($reference));
        $this->assertDatabaseCount('mapping_ownergrup', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        foreach ($reference['rules'] as $i => $rule) {
            WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => 'OWNER-REF-'.$i, 'id_branches' => $this->branch->getKey(),
                'owner_grup' => $rule['owner_grup'], 'jenis_pekerjaan' => 'NEW INSTALL', 'progress_category' => 'SURVEY', 'revision' => 4,
                'odp' => 'ODP-LOCAL', 'device_overrides' => ['odp'], 'source_bima_fields' => ['OWNER GROUP' => $rule['owner_grup']], 'source_snapshot_hash' => hash('sha256', (string) $i)]);
        }
        $before = DB::table('work_orders')->orderBy('id_work_orders')->get()->toArray();
        $this->assertSame(24, $service->apply($reference, $this->area)['rules_inserted']);
        $master = MappingOwnergrup::orderBy('id_mapping_ownergrup')->get()->toArray();
        $matrix = app(OwnerGroupAnalyticsService::class)->summary(WorkOrder::query());
        $this->assertSame(['DISTRICT' => 12, 'PMD' => 4, 'ED REGIONAL' => 2, 'SDI REGIONAL' => 3, 'FF SO 3' => 1, 'FF SO 4' => 1, 'DCS' => 1, 'CSO' => 0, 'MSO' => 0, 'unmapped' => 0], $matrix['totals']);
        $this->assertSame(24, $matrix['total']);
        $this->actingAs($this->area)->get(route('work-orders.index', ['owner_group' => 'DCS']))->assertOk()->assertViewHas('total', 1)->assertSee('DCS DCEC');
        $this->travel(1)->minutes();
        $this->assertSame(['rules_inserted' => 0, 'rules_updated' => 0, 'rules_unchanged' => 24], $service->apply($reference, $this->area));
        $this->assertSame($master, MappingOwnergrup::orderBy('id_mapping_ownergrup')->get()->toArray());
        $this->assertEquals($before, DB::table('work_orders')->orderBy('id_work_orders')->get()->toArray());
        $this->assertDatabaseCount('bima_task_logs', 0);
        $this->assertDatabaseCount('audit_logs', 2);
        $audit = AuditLog::where('action', 'owner_group_mapping.applied')->firstOrFail();
        $this->assertSame($reference['sha256'], $audit->metadata['sha256']);
        $this->assertSame($this->area->getKey(), $audit->id_users);
    }

    public function test_invalid_duplicate_or_unauthorized_reference_and_inbound_lock_prevent_writes(): void
    {
        $service = app(OwnerGroupMappingReferenceService::class);
        foreach ([[], [['owner_grup' => 'TEST', 'kelompok_ownergrup' => 'UNKNOWN']],
            [['owner_grup' => 'TEST', 'kelompok_ownergrup' => 'PMD'], ['owner_grup' => 'TEST', 'kelompok_ownergrup' => 'DISTRICT']],
            [['owner_grup' => ' TEST ', 'kelompok_ownergrup' => 'PMD']], [['owner_grup' => str_repeat('X', 256), 'kelompok_ownergrup' => 'PMD']]] as $rules) {
            try {
                $service->apply(['rules' => $rules], $this->area);
                $this->fail('Invalid owner mapping accepted');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('mapping_ownergrup', 0);
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
        foreach ([$this->user(Role::BranchHd), $this->user(Role::AreaHd, false)] as $actor) {
            try {
                $service->apply($service->reference(), $actor);
                $this->fail('Unauthorized actor accepted');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('mapping_ownergrup', 0);
            }
            $this->artisan('olo:apply-owner-group-mapping', ['--actor' => $actor->username])->assertFailed();
        }
        $this->artisan('olo:apply-owner-group-mapping')->assertFailed();
        $lock = Cache::lock('olo:work-order-inbound', 240);
        $lock->get();
        try {
            $service->apply($service->reference(), $this->area);
            $this->fail('Concurrent inbound accepted');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('mapping_ownergrup', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_command_preview_is_read_only_and_rule_updates_keep_ids_and_unlisted_rules_with_audit(): void
    {
        $service = app(OwnerGroupMappingReferenceService::class);
        $mapping = MappingOwnergrup::create(['owner_grup' => 'DCS DCEC', 'kelompok_ownergrup' => 'PMD', 'active' => false]);
        $unlisted = MappingOwnergrup::create(['owner_grup' => 'OTHER-OWNER', 'kelompok_ownergrup' => 'FF SO 3']);
        $this->area->forceFill(['username' => 'owner-mapping-area'])->save();
        $this->artisan('olo:apply-owner-group-mapping', ['--actor' => $this->area->username, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('mapping_ownergrup', 2);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertFalse($mapping->fresh()->active);
        $this->artisan('olo:apply-owner-group-mapping', ['--actor' => $this->area->username])->assertSuccessful();
        $this->assertDatabaseCount('mapping_ownergrup', 25);
        $this->assertSame($mapping->getKey(), $mapping->fresh()->getKey());
        $this->assertTrue($mapping->fresh()->active);
        $this->assertSame('DCS', $mapping->fresh()->kelompok_ownergrup);
        $this->assertSame('FF SO 3', $unlisted->fresh()->kelompok_ownergrup);
        $audit = AuditLog::where('action', 'owner_group_mapping.applied')->sole();
        $this->assertSame('PMD', $audit->metadata['rule_changes'][0]['before']['kelompok_ownergrup']);
        $this->assertSame('DCS', $audit->metadata['rule_changes'][0]['after']['kelompok_ownergrup']);
    }
}
