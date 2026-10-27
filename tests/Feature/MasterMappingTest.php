<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MappingOrder;
use App\Models\MappingOwnergrup;
use App\Models\WorkOrder;
use App\Services\MasterDataService;
use App\Services\OwnerGroupAnalyticsService;
use App\Services\PriorityOrderService;
use Illuminate\Support\Facades\Cache;

class MasterMappingTest extends OloTestCase
{
    private function version($record): string
    {
        return app(MasterDataService::class)->version($record->fresh());
    }

    private function order(string $id, array $values = []): WorkOrder
    {
        return WorkOrder::create($values + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'source_snapshot_hash' => str_repeat('a', 64), 'source_bima_fields' => [], 'progress_category' => 'SURVEY'])->refresh();
    }

    public function test_sidebar_has_five_separate_submenus_and_icon_only_actions_without_tabs(): void
    {
        $rule = MappingOrder::create(['status_order' => 'Task-STARTWORK', 'mapping_order' => 'OPEN']);
        $this->actingAs($this->area)->get('/master-data')->assertRedirect(route('master-data.index', 'regions'));
        foreach (MasterDataService::TYPES as $type => $definition) {
            $page = $this->get('/master-data/'.$type)->assertOk()->assertDontSee('Jenis master data')->assertDontSee('class="master-tabs"', false);
            foreach (MasterDataService::TYPES as $key => $entry) {
                $page->assertSee(route('master-data.index', $key));
            }
            $page->assertSee('nav-submenu')->assertSee('aria-current="page"', false);
        }
        $page = $this->get('/master-data/mapping-orders')->assertOk()->assertSee('data-master-tooltip="Edit"', false)->assertSee('data-master-tooltip="Delete"', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//div[@class="master-row-actions"]/a') as $action) {
            $this->assertSame('', trim($action->textContent));
            $this->assertNotSame('', $action->getAttribute('aria-label'));
            $this->assertSame(1, $action->getElementsByTagName('svg')->length);
        }
    }

    public function test_all_four_area_roles_can_crud_both_mapping_types_with_audit_and_validation(): void
    {
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $index => $role) {
            $actor = $this->user($role);
            $this->actingAs($actor);
            foreach (['mapping-orders', 'mapping-owner-groups'] as $type) {
                $definition = MasterDataService::TYPES[$type];
                $code = $definition['code'];
                $value = $definition['value'];
                $data = [$code => '  Mixed Case '.$index.'  ', $value => $type === 'mapping-orders' ? 'OPEN' : 'DISTRICT', 'active' => 1];
                $this->get('/master-data/'.$type.'/create')->assertOk();
                $this->post('/master-data/'.$type, $data + ['id_users_created_by' => 999, 'name' => 'untrusted'])->assertSessionHasNoErrors()->assertRedirect();
                $record = $definition['model']::where($code, 'Mixed Case '.$index)->firstOrFail();
                $this->assertNull($record->name);
                $this->get('/master-data/'.$type.'/'.$record->getKey().'/edit')->assertOk()->assertSee('Simpan perubahan');
                $data[$value] = $type === 'mapping-orders' ? 'STUCK' : 'PMD';
                $this->put('/master-data/'.$type.'/'.$record->getKey(), $data + ['version' => $this->version($record)])->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame($data[$value], $record->fresh()->$value);
                $this->post('/master-data/'.$type, $data)->assertSessionHasErrors($code);
                $this->post('/master-data/'.$type, [$code => 'Invalid category', $value => 'GUESS', 'active' => 1])->assertSessionHasErrors($value);
                $this->get('/master-data/'.$type.'/'.$record->getKey().'/delete')->assertOk()->assertSee('belum digunakan');
                $this->delete('/master-data/'.$type.'/'.$record->getKey(), ['version' => $this->version($record), 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
                $this->assertDatabaseMissing($record->getTable(), [$record->getKeyName() => $record->getKey()]);
                $this->assertSame(3, AuditLog::where('id_users', $actor->getKey())->where('entity_type', $type)->where('action', 'like', 'master_data.%')->count());
            }
        }
    }

    public function test_order_mapping_reconciles_only_derived_classification_and_handles_empty_task(): void
    {
        $order = $this->order('MAPPING-PS', ['task_bima' => 'Task Uji', 'status_bima' => 'COMPLETE', 'revision' => 7, 'odp' => 'ODP-LOCAL']);
        $before = $order->getRawOriginal();
        $this->actingAs($this->area)->post('/master-data/mapping-orders', ['status_order' => 'Task Uji-COMPLETE', 'mapping_order' => 'PS', 'active' => 1])->assertSessionHasNoErrors();
        $rule = MappingOrder::where('status_order', 'Task Uji-COMPLETE')->firstOrFail();
        $this->assertSame('PS', $order->fresh()->mapping_order);
        $this->assertFalse($order->fresh()->isPsEligible());
        foreach ($before as $key => $value) {
            if (! in_array($key, ['mapping_order', 'id_mapping_order'])) {
                $this->assertSame($value, $order->fresh()->getRawOriginal($key), $key);
            }
        }
        $this->put('/master-data/mapping-orders/'.$rule->getKey(), ['status_order' => $rule->status_order, 'mapping_order' => 'STUCK', 'active' => 1, 'version' => $this->version($rule)])->assertSessionHasNoErrors();
        $this->assertSame('STUCK', $order->fresh()->mapping_order);
        $this->put('/master-data/mapping-orders/'.$rule->getKey(), ['status_order' => $rule->status_order, 'mapping_order' => 'STUCK', 'active' => 0, 'version' => $this->version($rule)])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->mapping_order);
        $this->assertNull($order->fresh()->id_mapping_order);
        $empty = $this->order('EMPTY-TASK', ['status_bima' => 'COMPWORK']);
        $this->post('/master-data/mapping-orders', ['status_order' => '-COMPWORK', 'mapping_order' => 'PS', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('PS', $empty->fresh()->mapping_order);
        $this->assertSame('COMPWORK', $empty->fresh()->status_order);
        $audit = AuditLog::where('action', 'master_data.created')->where('entity_type', 'mapping-orders')->latest('id_audit_logs')->first();
        $this->assertSame([$empty->getKey()], $audit->metadata['reconciled_work_order_ids']);
        $this->assertDatabaseCount('bima_task_logs', 0);
    }

    public function test_owner_mapping_immediately_changes_recap_without_mutating_source_and_keys_are_case_sensitive(): void
    {
        $order = $this->order('OWNER-MAP', ['owner_grup' => 'Owner Mixed', 'id_branches' => $this->branch->getKey()]);
        $before = $order->getRawOriginal();
        $this->actingAs($this->area)->post('/master-data/mapping-owner-groups', ['owner_grup' => 'Owner Mixed', 'kelompok_ownergrup' => 'DISTRICT', 'active' => 1])->assertSessionHasNoErrors();
        $rule = MappingOwnergrup::where('owner_grup', 'Owner Mixed')->firstOrFail();
        $this->assertSame(1, app(OwnerGroupAnalyticsService::class)->summary(WorkOrder::query())['totals']['DISTRICT']);
        $this->put('/master-data/mapping-owner-groups/'.$rule->getKey(), ['owner_grup' => $rule->owner_grup, 'kelompok_ownergrup' => 'DCS', 'active' => 1, 'version' => $this->version($rule)])->assertSessionHasNoErrors();
        $this->assertSame(1, app(OwnerGroupAnalyticsService::class)->summary(WorkOrder::query())['totals']['DCS']);
        $this->post('/master-data/mapping-owner-groups', ['owner_grup' => 'owner mixed', 'kelompok_ownergrup' => 'PMD', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('mapping_ownergrup', 2);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->get('/master-data/mapping-owner-groups?q=Owner&category=DCS&status=active')->assertOk()->assertSee('1 data sesuai filter');
        $this->get('/master-data/mapping-orders?category=UNKNOWN')->assertSessionHasErrors('category');
        app(PriorityOrderService::class)->set($this->area, $order, true);
        foreach (['CSO', 'MSO'] as $group) {
            $this->get('/master-data/mapping-owner-groups/create')->assertOk()->assertSee('value="'.$group.'"', false);
            $this->put('/master-data/mapping-owner-groups/'.$rule->getKey(), ['owner_grup' => $rule->owner_grup, 'kelompok_ownergrup' => $group,
                'active' => 1, 'version' => $this->version($rule)])->assertSessionHasNoErrors();
            $this->get('/master-data/mapping-owner-groups?category='.$group)->assertOk()->assertSee('1 data sesuai filter');
            $this->get(route('work-orders.index', ['jenis_pekerjaan' => 'all', 'owner_group' => $group]))->assertOk()->assertViewHas('total', 1);
            $this->get(route('area.dashboard', ['tab' => 'owner-group']))->assertOk()->assertViewHas('ownerGroups', fn ($data) => $data['totals'][$group] === 1);
            $this->get(route('priorities.index', ['owner_group' => $group]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
            $this->get(route('priorities.monitoring', ['owner_group' => $group]))->assertOk()->assertViewHas('report', fn ($data) => $data['total'] === 1 && $data['totals'][$group] === 1);
        }
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_used_mapping_keys_cannot_be_deleted_or_renamed_even_after_deactivation(): void
    {
        $orderRule = MappingOrder::create(['status_order' => 'Task-STARTWORK', 'mapping_order' => 'OPEN']);
        $ownerRule = MappingOwnergrup::create(['owner_grup' => 'Owner Used', 'kelompok_ownergrup' => 'PMD']);
        $this->order('USED-MAPPING', ['task_bima' => 'Task', 'status_bima' => 'STARTWORK', 'owner_grup' => 'Owner Used']);
        $this->actingAs($this->area);
        foreach (['mapping-orders' => $orderRule, 'mapping-owner-groups' => $ownerRule] as $type => $rule) {
            $def = MasterDataService::TYPES[$type];
            $this->get('/master-data/'.$type.'/'.$rule->getKey().'/delete')->assertOk()->assertSee('Data masih digunakan');
            $this->delete('/master-data/'.$type.'/'.$rule->getKey(), ['version' => $this->version($rule), 'confirmed' => 1])->assertSessionHasErrors('delete');
            $this->put('/master-data/'.$type.'/'.$rule->getKey(), [$def['code'] => 'Changed Key', $def['value'] => $rule->{$def['value']}, 'active' => 0, 'version' => $this->version($rule)])->assertSessionHasErrors($def['code']);
            $this->put('/master-data/'.$type.'/'.$rule->getKey(), [$def['code'] => $rule->{$def['code']}, $def['value'] => $rule->{$def['value']}, 'active' => 0, 'version' => $this->version($rule)])->assertSessionHasNoErrors();
            $this->delete('/master-data/'.$type.'/'.$rule->getKey(), ['version' => $this->version($rule), 'confirmed' => 1])->assertSessionHasErrors('delete');
            $this->assertNotNull($rule->fresh());
        }
    }

    public function test_mapping_concurrency_confirmation_and_import_lock_prevent_writes(): void
    {
        $this->actingAs($this->area);
        foreach (['mapping-orders', 'mapping-owner-groups'] as $type) {
            $def = MasterDataService::TYPES[$type];
            $data = [$def['code'] => 'Stale Key', $def['value'] => $type === 'mapping-orders' ? 'OPEN' : 'PMD', 'active' => 1];
            $rule = $def['model']::create($data);
            $version = $this->version($rule);
            $rule->update(['active' => false]);
            $this->put('/master-data/'.$type.'/'.$rule->getKey(), $data + ['version' => $version])->assertConflict();
            $this->delete('/master-data/'.$type.'/'.$rule->getKey(), ['version' => $version, 'confirmed' => 1])->assertConflict();
            $this->delete('/master-data/'.$type.'/'.$rule->getKey(), ['version' => $this->version($rule)])->assertSessionHasErrors('confirmed');
            $lock = Cache::lock('olo:work-order-inbound', 240);
            $lock->get();
            try {
                $data[$def['code']] = 'LOCKED';
                $this->post('/master-data/'.$type, $data)->assertSessionHasErrors('master_data');
            } finally {
                $lock->release();
            }
            $this->assertDatabaseMissing($rule->getTable(), [$def['code'] => 'LOCKED']);
        }
    }
}
