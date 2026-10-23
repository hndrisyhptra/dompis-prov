<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\WorkOrder;
use App\Services\MasterDataService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MasterDataTest extends OloTestCase
{
    private function version($record): string
    {
        return app(MasterDataService::class)->version($record->fresh());
    }

    private function location(): array
    {
        $region = Region::create(['code' => 'QA-REG', 'name' => 'Region Pengujian', 'is_active' => true]);
        $branch = Branch::create(['code' => 'QA-BR', 'name' => 'Branch Pengujian', 'region_id' => $region->getKey(), 'regional' => $region->name, 'active' => true]);
        $sto = ServiceArea::create(['workzone' => 'QASTO', 'name' => 'STO Pengujian', 'id_branches' => $branch->getKey(), 'id_regions' => $region->getKey(), 'is_active' => true]);

        return [$region, $branch, $sto];
    }

    public function test_only_active_authorized_area_roles_have_master_menu_and_endpoints(): void
    {
        [$region] = $this->location();
        foreach (Role::cases() as $role) {
            $user = $this->user($role);
            $this->actingAs($user);
            $allowed = in_array($role, [Role::SuperAdmin, Role::AreaCoordinator, Role::AreaHd, Role::AreaAdmin], true);
            foreach (array_keys(MasterDataService::TYPES) as $type) {
                $response = $this->get('/master-data/'.$type);
                $allowed ? $response->assertOk()->assertSee('Master Data') : $response->assertForbidden();
                if (! $allowed) {
                    $this->get('/master-data/'.$type.'/create')->assertForbidden();
                    $this->get('/master-data/'.$type.'/1/edit')->assertForbidden();
                    $this->get('/master-data/'.$type.'/1/delete')->assertForbidden();
                    $this->post('/master-data/'.$type, [])->assertForbidden();
                    $this->put('/master-data/'.$type.'/1', [])->assertForbidden();
                    $this->delete('/master-data/'.$type.'/1', [])->assertForbidden();
                }
            }
            if ($role !== Role::Bot) {
                $page = $this->get('/work-orders')->assertOk();
                $allowed ? $page->assertSee('Submenu Master Data', false) : $page->assertDontSee('Submenu Master Data', false);
            }
        }
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaHd, Role::AreaAdmin] as $role) {
            $this->actingAs($this->user($role, false))->get('/master-data/regions')->assertForbidden();
        }
        $this->actingAs($this->area)->get('/master-data/unknown')->assertNotFound();
    }

    public function test_superadmin_and_coordinator_can_create_update_and_delete_each_master(): void
    {
        [$region, $branch] = $this->location();
        foreach ([Role::SuperAdmin, Role::AreaCoordinator] as $index => $role) {
            $actor = $this->user($role);
            $this->actingAs($actor);
            foreach (array_intersect_key(MasterDataService::TYPES, array_flip(['regions', 'branches', 'service-areas'])) as $type => $definition) {
                $code = $definition['code'];
                $values = [$code => 'ACCESS-'.$index, 'name' => 'Master Access '.$index, $definition['active'] => 1];
                if ($type === 'branches') {
                    $values['region_id'] = $region->getKey();
                } elseif ($type === 'service-areas') {
                    $values += ['id_regions' => $region->getKey(), 'id_branches' => $branch->getKey()];
                }
                $this->get('/master-data/'.$type.'/create')->assertOk();
                $this->post('/master-data/'.$type, $values)->assertSessionHasNoErrors()->assertRedirect();
                $record = $definition['model']::where($code, $values[$code])->firstOrFail();
                $this->get('/master-data/'.$type.'/'.$record->getKey().'/edit')->assertOk();
                $values['name'] = 'Updated master '.$index;
                $this->put('/master-data/'.$type.'/'.$record->getKey(), $values + ['version' => $this->version($record)])->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame($values['name'], $record->fresh()->name);
                $this->get('/master-data/'.$type.'/'.$record->getKey().'/delete')->assertOk();
                $this->delete('/master-data/'.$type.'/'.$record->getKey(), ['version' => $this->version($record), 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSoftDeleted($record);
                $this->assertSame(3, AuditLog::where('id_users', $actor->getKey())->where('entity_type', $type)->where('action', 'like', 'master_data.%')->count());
            }
        }
    }

    public function test_master_pages_have_descriptive_tooltips_and_inline_field_errors(): void
    {
        [$region, $branch, $sto] = $this->location();
        $this->actingAs($this->area);
        foreach (['/master-data/service-areas', '/master-data/service-areas/create', '/master-data/service-areas/'.$sto->getKey().'/edit', '/master-data/service-areas/'.$sto->getKey().'/delete'] as $path) {
            $page = $this->get($path)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
            $xpath = new \DOMXPath($document);
            $buttons = $xpath->query('//div[@class="page"]//button | //div[@class="page"]//a[contains(concat(" ", normalize-space(@class), " "), " button ")]');
            $this->assertGreaterThan(0, $buttons->length);
            foreach ($buttons as $button) {
                $this->assertNotSame('', $button->getAttribute('data-master-tooltip'));
                $this->assertSame($button->getAttribute('data-master-tooltip'), $button->getAttribute('title'));
                $this->assertStringContainsString('master-action', $button->getAttribute('class'));
            }
        }
        $this->from('/master-data/regions/create')->post('/master-data/regions', ['code' => 'invalid code', 'name' => '', 'is_active' => 1])->assertRedirect('/master-data/regions/create');
        $this->get('/master-data/regions/create')->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('master-field-error');
    }

    public function test_crud_for_all_three_masters_normalizes_codes_audits_and_archives(): void
    {
        $this->actingAs($this->area);
        $parents = [];
        foreach (['regions', 'branches', 'service-areas'] as $type) {
            $def = MasterDataService::TYPES[$type];
            $code = $def['code'];
            $data = [$code => ' qa-new ', 'name' => ' Nama Baru ', $def['active'] => 1] + $parents;
            $this->post('/master-data/'.$type, $data + ['source_metadata' => ['bad' => true], 'id_users_created_by' => 999])->assertSessionHasNoErrors()->assertRedirect();
            $record = $def['model']::where($code, 'QA-NEW')->firstOrFail();
            $this->assertSame('Nama Baru', $record->name);
            if ($type !== 'branches') {
                $this->assertSame($this->area->getKey(), $record->id_users_created_by);
                $this->assertNull($record->source_metadata);
            }
            $this->get('/master-data/'.$type.'/'.$record->getKey().'/edit')->assertOk()->assertSee('Simpan perubahan');
            $data[$code] = 'QA-NEW';
            $data['name'] = 'Nama Diperbarui';
            $this->put('/master-data/'.$type.'/'.$record->getKey(), $data + ['version' => $this->version($record)])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('Nama Diperbarui', $record->fresh()->name);
            if ($type === 'regions') {
                $parents += ['region_id' => $record->getKey(), 'id_regions' => $record->getKey()];
            }
            if ($type === 'branches') {
                $parents += ['id_branches' => $record->getKey()];
            }
        }
        foreach (['service-areas', 'branches', 'regions'] as $type) {
            $def = MasterDataService::TYPES[$type];
            $record = $def['model']::where($def['code'], 'QA-NEW')->firstOrFail();
            $this->get('/master-data/'.$type.'/'.$record->getKey().'/delete')->assertOk()->assertSee('Konfirmasi penghapusan');
            // Archived child references remain protected; test unused parents separately.
            if ($type !== 'service-areas') {
                $record = $def['model']::create([$def['code'] => 'QA-UNUSED', 'name' => 'Unused', $def['active'] => false]);
            }
            $this->delete('/master-data/'.$type.'/'.$record->getKey(), ['version' => $this->version($record), 'confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSoftDeleted($record->getTable(), [$record->getKeyName() => $record->getKey()]);
            $this->get('/master-data/'.$type.'/'.$record->getKey().'/edit')->assertNotFound();
            $this->post('/master-data/'.$type, [$def['code'] => $record->{$def['code']}, 'name' => 'Duplicate', $def['active'] => 0] + $parents)->assertSessionHasErrors($def['code']);
        }
        $this->assertSame(3, AuditLog::where('action', 'master_data.created')->count());
        $this->assertSame(3, AuditLog::where('action', 'master_data.updated')->count());
        $this->assertSame(3, AuditLog::where('action', 'master_data.deleted')->count());
    }

    public function test_parent_consistency_and_deactivation_are_validated(): void
    {
        [$region, $branch, $sto] = $this->location();
        $other = Region::create(['code' => 'QA-OTHER', 'name' => 'Other', 'is_active' => false]);
        $this->actingAs($this->area);
        $this->post('/master-data/branches', ['code' => 'NEW-BR', 'name' => 'New', 'active' => 1, 'region_id' => $other->getKey()])->assertSessionHasErrors('region_id');
        $this->post('/master-data/service-areas', ['workzone' => 'NEW-STO', 'name' => 'New', 'is_active' => 1, 'id_regions' => $other->getKey(), 'id_branches' => $branch->getKey()])->assertSessionHasErrors('id_branches');
        $this->post('/master-data/service-areas', ['workzone' => 'NEW-STO', 'name' => 'New', 'is_active' => 1])->assertSessionHasErrors(['id_regions', 'id_branches']);
        $this->put('/master-data/regions/'.$region->getKey(), ['code' => $region->code, 'name' => $region->name, 'is_active' => 0, 'version' => $this->version($region)])->assertSessionHasErrors('is_active');
        $this->put('/master-data/branches/'.$branch->getKey(), ['code' => $branch->code, 'name' => $branch->name, 'active' => 0, 'region_id' => $region->getKey(), 'version' => $this->version($branch)])->assertSessionHasErrors('active');
        $this->put('/master-data/branches/'.$branch->getKey(), ['code' => $branch->code, 'name' => $branch->name, 'active' => 0, 'region_id' => $other->getKey(), 'version' => $this->version($branch)])->assertSessionHasErrors('region_id');
        $this->post('/master-data/service-areas', ['workzone' => 'INACTIVE-STO', 'name' => 'Unmapped', 'is_active' => 0])->assertSessionHasNoErrors();
    }

    public function test_used_data_cannot_be_deleted_renamed_or_moved_and_order_is_unchanged(): void
    {
        [$region, $branch, $sto] = $this->location();
        $this->pull([$this->row('QA-ORDER', [5 => 'BRANCH PENGUJIAN', 6 => 'QASTO'])]);
        $order = WorkOrder::where('no_tq_sc_id', 'QA-ORDER')->firstOrFail();
        $order->update(['id_branches' => $branch->getKey(), 'progress_category' => 'SURVEY']);
        $before = $order->fresh()->getRawOriginal();
        $this->area->forceFill(['id_regions' => $region->getKey(), 'id_service_areas' => $sto->getKey()])->save();
        $this->area->branches()->attach($branch);
        $otherBranch = Branch::create(['code' => 'QA-OTHER-BR', 'name' => 'Other', 'region_id' => $region->getKey(), 'active' => true]);
        $this->actingAs($this->area);
        foreach (['regions' => $region, 'branches' => $branch, 'service-areas' => $sto] as $type => $record) {
            $this->get('/master-data/'.$type.'/'.$record->getKey().'/delete')->assertOk()->assertSee('Data masih digunakan');
            $this->delete('/master-data/'.$type.'/'.$record->getKey(), ['version' => $this->version($record), 'confirmed' => 1])->assertSessionHasErrors('delete');
            $this->assertNull($record->fresh()->deleted_at);
        }
        $data = ['workzone' => 'RENAMED', 'name' => $sto->name, 'is_active' => 1, 'id_regions' => $region->getKey(), 'id_branches' => $branch->getKey(), 'version' => $this->version($sto)];
        $this->put('/master-data/service-areas/'.$sto->getKey(), $data)->assertSessionHasErrors('workzone');
        $data['workzone'] = $sto->workzone;
        $data['id_branches'] = $otherBranch->getKey();
        $this->put('/master-data/service-areas/'.$sto->getKey(), $data)->assertSessionHasErrors('id_branches');
        $this->put('/master-data/regions/'.$region->getKey(), ['code' => $region->code, 'name' => 'Renamed Region', 'is_active' => 1, 'version' => $this->version($region)])->assertSessionHasNoErrors();
        $this->assertSame('Renamed Region', $branch->fresh()->regional);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_stale_edits_and_deletes_and_unconfirmed_delete_are_rejected(): void
    {
        $record = Region::create(['code' => 'QA-FREE', 'name' => 'Initial', 'is_active' => true]);
        $version = $this->version($record);
        DB::table('regions')->where('id_regions', $record->getKey())->update(['name' => 'Concurrent update']);
        $this->actingAs($this->area);
        $this->put('/master-data/regions/'.$record->getKey(), ['code' => $record->code, 'name' => 'Overwrite', 'is_active' => 1, 'version' => $version])->assertConflict();
        $this->delete('/master-data/regions/'.$record->getKey(), ['version' => $version, 'confirmed' => 1])->assertConflict();
        $this->delete('/master-data/regions/'.$record->getKey(), ['version' => $this->version($record)])->assertSessionHasErrors('confirmed');
        $this->assertSame('Concurrent update', $record->fresh()->name);
        $this->assertNull($record->fresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('action', 'like', 'master_data.%')->count());
    }

    public function test_import_lock_and_revoked_permission_prevent_writes(): void
    {
        $lock = Cache::lock('olo:work-order-inbound', 240);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($this->area)->post('/master-data/regions', ['code' => 'LOCKED', 'name' => 'Locked', 'is_active' => 1])->assertSessionHasErrors('master_data');
        } finally {
            $lock->release();
        }
        $this->assertDatabaseMissing('regions', ['code' => 'LOCKED']);
        $this->area->fresh()->forceFill(['role' => Role::BranchAdmin])->save();
        $this->expectException(AuthorizationException::class);
        app(MasterDataService::class)->save($this->area, 'regions', ['code' => 'REVOKED', 'name' => 'Revoked', 'is_active' => 1]);
    }

    public function test_search_filters_empty_state_and_validation(): void
    {
        [$region, $branch, $sto] = $this->location();
        $this->actingAs($this->area);
        $this->get('/master-data/service-areas?q=QASTO&status=active&region='.$region->getKey().'&branch='.$branch->getKey())->assertOk()->assertSee('STO Pengujian')->assertSee('1 data sesuai filter');
        $this->get('/master-data/service-areas?q=DOESNOTEXIST')->assertOk()->assertSee('Belum ada Service Area sesuai filter');
        $this->get('/master-data/branches?status=invalid')->assertSessionHasErrors('status');
        $this->post('/master-data/regions', ['code' => ['bad'], 'name' => 'Test', 'is_active' => 1])->assertSessionHasErrors('code');
        $this->post('/master-data/regions', ['code' => 'bad code', 'name' => '', 'is_active' => 'invalid'])->assertSessionHasErrors(['code', 'name', 'is_active']);
        for ($i = 0; $i < 27; $i++) {
            Region::create(['code' => 'PAGING-'.$i, 'name' => 'Page '.$i, 'is_active' => true]);
        }
        $this->get('/master-data/regions?q=PAGING')->assertOk()->assertSee('page=2', false)->assertSee('27 data sesuai filter');
    }
}
