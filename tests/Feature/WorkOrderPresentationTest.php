<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\WorkOrder;
use DOMDocument;
use DOMXPath;

class WorkOrderPresentationTest extends OloTestCase
{
    public function test_work_order_table_has_progress_only_full_bima_status_and_four_accessible_compact_actions(): void
    {
        $this->pull([$this->row('UI-ORDER', [14 => 'Review Order TSQ', 13 => 'STARTWORK'])]);
        $order = WorkOrder::sole();
        $order->update(['progress_category' => 'SURVEY', 'last_progress_at' => '2026-10-22 02:03:04']);
        $user = $this->user(Role::BranchHd);
        $user->branches()->attach($this->branch);
        $this->actingAs($user);
        foreach (['all', 'new_order', 'potential_ps'] as $queue) {
            $response = $this->get(route('work-orders.index', ['queue' => $queue]))->assertOk()->assertSee('Status Progress')->assertSee('Status Bima')->assertDontSee('Progress branch')->assertDontSee('BIMA:')->assertDontSee('Update terakhir');
            $dom = new DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new DOMXPath($dom);
            $this->assertSame(7, $xpath->query('//div[contains(@class,"wo-desktop-table")]//thead/tr/th')->length);
            if ($queue !== 'potential_ps') {
                $this->assertSame('SURVEY', trim($xpath->query('//td[@class="wo-progress-cell"]')->item(0)->textContent));
                $this->assertSame('Review Order TSQ-STARTWORK', trim($xpath->query('//td[@class="bima-order-status"]')->item(0)->textContent));
                $response->assertSee('title="Detail Order"', false)->assertSee(route('work-orders.show', $order));
                foreach (['Update Progress' => 'progress', 'Kendala' => 'issue', 'Close' => 'close'] as $label => $action) {
                    $response->assertSee('title="'.$label.'"', false)->assertSee('data-tooltip="'.$label.'"', false)
                        ->assertSee(route('work-orders.action.edit', [$order, $action]));
                }
            }
        }
        $this->get(route('work-orders.action.edit', [$order, 'close']))->assertOk()->assertSee('Close belum tentu PS')->assertSee('Lihat 14 status BIMA untuk PS');
        $viewer = $this->user(Role::MaterialAdmin);
        $viewer->branches()->attach($this->branch);
        $this->actingAs($viewer)->get(route('work-orders.index'))->assertOk()->assertDontSee('/actions/close')->assertSee('title="Detail Order"', false);
        $this->actingAs($this->user())->get(route('work-orders.action.edit', [$order, 'close']))->assertForbidden();
    }

    public function test_close_marks_field_installation_only_and_ps_requires_later_approved_bima_combination(): void
    {
        $this->pull([$this->row('FIELD-CLOSE', [14 => 'Activate Service', 13 => 'STARTWORK'])]);
        $order = WorkOrder::sole();
        $hash = $order->source_snapshot_hash;
        $user = $this->user();
        $user->branches()->attach($this->branch);
        $this->actingAs($user)->patch(route('work-orders.progress', $order), ['revision' => 0, 'progress_category' => 'CLOSE', 'notes' => 'Instalasi dan pengujian lapangan selesai.'])->assertSessionHasNoErrors()->assertRedirect();
        $order->refresh();
        $this->assertSame('CLOSE', $order->progress_category);
        $this->assertSame('FIELD_DONE', $order->operational_status);
        $this->assertSame($hash, $order->source_snapshot_hash);
        $this->assertFalse($order->isPsEligible());
        $this->get(route('branch.dashboard'))->assertViewHas('ps_total', 0)->assertViewHas('waiting_bima', 1);
        $this->assertSame(0, WorkOrder::psEligible()->count());
        $this->pull([$this->row('FIELD-CLOSE', [14 => 'Approval E2E Testing Manual', 13 => 'COMPLETE'])]);
        $this->assertTrue($order->fresh()->isPsEligible());
        $this->assertSame(1, $order->fresh()->revision);
        $this->assertSame(1, WorkOrder::psEligible()->count());
        $this->get(route('branch.dashboard'))->assertViewHas('ps_total', 1)->assertViewHas('waiting_bima', 0)->assertViewHas('complete', 0);
        $this->assertNull($order->fresh()->tgl_ps);
        $this->assertDatabaseCount('wo_status_events', 1);
    }
}
