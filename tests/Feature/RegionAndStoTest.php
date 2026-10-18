<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\WorkOrder;
use App\Services\BimaSheetPullService;
use Illuminate\Database\QueryException;

class RegionAndStoTest extends OloTestCase
{
    public function test_source_master_is_imported_with_unique_named_ids_and_legacy_audit_preserved_as_metadata(): void
    {
        $this->assertDatabaseCount('regions', 3);
        $this->assertDatabaseCount('service_areas', 392);
        $region = Region::where('code', 'JATIM')->firstOrFail();
        $this->assertSame('id_regions', $region->getKeyName());
        $this->assertSame(1, $region->getKey());
        $this->assertSame(1, $region->source_metadata['area_id']);
        $this->assertSame(2, $region->source_metadata['updated_by']);
        $this->assertNull($region->updater);
        $sto = ServiceArea::where('workzone', 'DMO')->firstOrFail();
        $this->assertSame('id_service_areas', $sto->getKeyName());
        $this->assertSame('DARMO', $sto->name);
        $this->assertSame($region->getKey(), $sto->region->getKey());
        $this->assertSame(3, $sto->source_metadata['branch_id']);
        $this->assertNull($sto->id_branches);
        $this->assertNull($sto->creator);
        $this->assertNull(ServiceArea::find(317));
        $this->assertSame('PGR', ServiceArea::findOrFail(393)->workzone);
        $this->assertSame('2026-09-23 20:52:22', $sto->created_at->format('Y-m-d H:i:s'));
    }

    public function test_region_and_sto_foreign_keys_and_unique_workzone_are_enforced(): void
    {
        try {
            $this->area->forceFill(['id_regions' => 99999])->save();
            $this->fail('Invalid user region accepted.');
        } catch (QueryException) {
            $this->assertNull($this->area->fresh()->id_regions);
        }
        try {
            ServiceArea::create(['workzone' => 'DMO', 'name' => 'Duplicate']);
            $this->fail('Duplicate STO accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('service_areas', 392);
        }
        try {
            ServiceArea::where('workzone', 'DMO')->update(['id_branches' => 99999]);
            $this->fail('Invalid branch accepted.');
        } catch (QueryException) {
            $this->assertNull(ServiceArea::where('workzone', 'DMO')->value('id_branches'));
        }
        $this->area->forceFill(['id_regions' => 1])->save();
        try {
            Region::findOrFail(1)->forceDelete();
            $this->fail('Referenced region deleted.');
        } catch (QueryException) {
            $this->assertNotNull(Region::find(1));
        }
    }

    public function test_sto_mapping_can_route_new_orders_but_cannot_move_existing_orders_or_override_explicit_mapping(): void
    {
        $this->branch->update(['regional' => 'REGION JATIM']);
        $this->artisan('olo:sto-map', ['workzone' => 'DMO', 'branch' => $this->branch->code])->assertSuccessful();
        $sto = ServiceArea::where('workzone', 'DMO')->firstOrFail();
        $this->assertSame($this->branch->getKey(), $sto->branch->getKey());
        $this->assertSame($sto->getKey(), $this->branch->serviceAreas->sole()->getKey());
        $this->pull([$this->row(changes: [5 => 'UNMAPPED BIMA LABEL', 6 => 'DMO'])]);
        $order = WorkOrder::firstOrFail();
        $this->assertSame($this->branch->getKey(), $order->id_branches);
        $order->update(['operational_status' => 'IN_PROGRESS', 'revision' => 5]);
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Other', 'regional' => 'REGION JATIM']);
        $this->artisan('olo:sto-map', ['workzone' => 'DMO', 'branch' => $other->code])->assertSuccessful();
        $job = $this->pull([$this->row(changes: [5 => 'UNMAPPED BIMA LABEL', 6 => 'DMO'])]);
        $this->assertSame(1, $job->stats['conflict']);
        $this->assertSame($this->branch->getKey(), $order->fresh()->id_branches);
        $this->assertSame('IN_PROGRESS', $order->fresh()->operational_status);
        $this->assertSame(5, $order->fresh()->revision);
        $job = $this->preview([$this->row('NEW-ID', [6 => 'DMO'])]);
        $this->assertSame(1, $job->stats['conflict']);
        $this->assertSame(2, AuditLog::where('action', 'sto.branch_mapped_cli')->count());
    }

    public function test_inactive_sto_or_region_and_mapping_changes_since_preview_are_rejected(): void
    {
        $sto = ServiceArea::where('workzone', 'DMO')->firstOrFail();
        $sto->update(['id_branches' => $this->branch->getKey()]);
        $job = $this->preview([$this->row(changes: [6 => 'DMO'])]);
        $this->assertSame(1, $job->stats['new']);
        $sto->update(['is_active' => false]);
        $job->update(['stage' => 'commit']);
        app(BimaSheetPullService::class)->pull($job);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertSame(1, $job->fresh()->stats['conflict']);
        $sto->update(['is_active' => true]);
        $sto->region->update(['is_active' => false]);
        $this->assertSame(1, $this->preview([$this->row(changes: [6 => 'DMO'])])->stats['conflict']);
        $sto->region->update(['is_active' => true]);
        $sto->delete();
        $this->assertSame(1, $this->preview([$this->row(changes: [6 => 'DMO'])])->stats['conflict']);
    }

    public function test_user_location_assignments_are_audited_consistent_and_never_grant_branch_access(): void
    {
        $this->branch->update(['region_id' => 1, 'regional' => 'REGION JATIM']);
        $sto = ServiceArea::where('workzone', 'DMO')->firstOrFail();
        $sto->update(['id_branches' => $this->branch->getKey()]);
        $user = $this->user();
        $user->forceFill(['username' => 'location-user'])->save();
        $this->artisan('olo:user-location', ['identity' => $user->username, '--sto' => 'DMO'])->assertFailed();
        $this->assertNull($user->fresh()->id_service_areas);
        $this->assertSame(0, $user->branches()->count());
        $user->branches()->attach($this->branch);
        $this->artisan('olo:user-location', ['identity' => $user->username, '--region' => 'BALNUS', '--sto' => 'DMO'])->assertFailed();
        $this->artisan('olo:user-location', ['identity' => $user->username, '--sto' => 'dmo'])->assertSuccessful();
        $this->assertSame($sto->getKey(), $user->fresh()->serviceArea->getKey());
        $this->assertSame(1, $user->fresh()->region->getKey());
        $this->assertTrue($sto->fresh()->users->contains($user));
        $this->pull([$this->row()]);
        $user->branches()->detach();
        $this->actingAs($user)->get(route('work-orders.index'))->assertOk()->assertDontSee('000123');
        $this->get(route('work-orders.show', WorkOrder::firstOrFail()))->assertForbidden();
        $this->artisan('olo:user-location', ['identity' => $user->username, '--clear' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->id_regions);
        $this->assertNull($user->fresh()->id_service_areas);
        $this->assertSame(2, AuditLog::where('action', 'user.location_assigned_cli')->count());
    }

    public function test_mapping_command_rejects_unknown_inactive_cross_region_and_assigned_user_remap(): void
    {
        $this->artisan('olo:sto-map', ['workzone' => 'UNKNOWN', 'branch' => $this->branch->code])->assertFailed();
        $this->branch->update(['regional' => 'REGION BALNUS']);
        $this->artisan('olo:sto-map', ['workzone' => 'DMO', 'branch' => $this->branch->code])->assertFailed();
        $this->branch->update(['regional' => 'REGION JATIM']);
        $this->artisan('olo:sto-map', ['workzone' => 'DMO', 'branch' => $this->branch->code])->assertSuccessful();
        $this->artisan('olo:user-location', ['identity' => $this->area->email, '--sto' => 'DMO'])->assertSuccessful();
        $other = Branch::create(['code' => 'NEXT', 'name' => 'Next', 'regional' => 'REGION JATIM']);
        $this->artisan('olo:sto-map', ['workzone' => 'DMO', 'branch' => $other->code])->assertFailed();
        $this->assertSame($this->branch->getKey(), ServiceArea::where('workzone', 'DMO')->value('id_branches'));
        $this->branch->update(['active' => false]);
        $this->artisan('olo:user-location', ['identity' => $this->area->email, '--sto' => 'DMO'])->assertFailed();
    }
}
