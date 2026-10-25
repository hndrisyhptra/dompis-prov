<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Package;
use App\Models\QeBoq;
use App\Models\WorkOrder;
use App\Services\Boq\BoqBreakdown;
use App\Services\Boq\BoqImportService;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\DompisBoqFile;

class BoqCalculationTest extends OloTestCase
{
    public function test_example_is_recomputed_despite_bad_cached_values_and_has_exact_material_labor_and_total(): void
    {
        $file = DompisBoqFile::make();
        try {
            $parsed = app(BoqImportService::class)->parse($file, Package::findOrFail(2));
            $this->assertCount(11, $parsed['items']);
            $this->assertSame('1010302.00', $parsed['material_total']);
            $this->assertSame('770401.00', $parsed['labor_total']);
            $this->assertSame('1780703.00', $parsed['grand_total']);
            $breakdown = BoqBreakdown::totals($parsed['items']);
            $volume = collect($breakdown['volumes'])->keyBy('unit');
            $this->assertSame('74.000', $volume['meter']['material']);
            $this->assertSame('74.000', $volume['meter']['labor']);
            $this->assertSame('4.000', $volume['pcs']['material']);
            $this->assertSame('4.000', $volume['pcs']['labor']);
            $this->assertSame('6.000', $volume['core']['labor']);
        } finally {
            unlink($file->getRealPath());
        }
    }

    public function test_upload_revisions_snapshot_breakdown_and_semantic_hash_preserve_progress_and_deduplicate(): void
    {
        $this->pull([$this->row('CALC-BOQ')]);
        $order = WorkOrder::sole();
        $order->update(['progress_category' => 'JT']);
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor);
        Storage::fake('local');
        $before = $order->fresh()->getRawOriginal();
        foreach ([DompisBoqFile::EXAMPLE_VOLUMES, DompisBoqFile::EXAMPLE_VOLUMES, array_replace(DompisBoqFile::EXAMPLE_VOLUMES, [535 => 7])] as $i => $volumes) {
            $file = DompisBoqFile::make($volumes);
            try {
                $this->post(route('boqs.store', $order), ['file' => $file, 'package_id' => 2, 'order_revision' => $order->revision, 'boq_revision' => min(1, $i), 'header_row' => 1])->assertSessionHasNoErrors()->assertRedirect();
            } finally {
                unlink($file->getRealPath());
            }
        }
        $boq = QeBoq::sole();
        $this->assertSame(2, $boq->revision);
        $this->assertSame('1010302.00', $boq->material_total);
        $this->assertSame('817401.00', $boq->labor_total);
        $this->assertSame('1827703.00', $boq->grand_total);
        $this->assertDatabaseCount('qe_boq_histories', 2);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->get(route('boqs.show', $order))->assertOk()->assertSee('1.827.703,00')->assertSee('817.401,00')->assertSee('Akumulasi volume item per satuan');
    }
}
