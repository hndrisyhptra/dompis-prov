<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\MappingOwnergrup;
use App\Models\SyncJob;
use App\Models\WorkOrder;
use App\Support\ProgressSummary;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

class DashboardDrilldownTest extends OloTestCase
{
    private function fixtures(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 17:05:00', 'UTC'));
        $this->branch->update(['region_id' => 1]);
        $next = Branch::create(['code' => 'DASH-NEXT', 'name' => 'Branch Regional Dua', 'region_id' => 2]);
        $noRegion = Branch::create(['code' => 'DASH-NOREGION', 'name' => 'Branch Tanpa Region']);
        MappingOwnergrup::create(['owner_grup' => 'OWNER-TEST', 'kelompok_ownergrup' => 'DISTRICT']);
        foreach (['SURVEY', 'OGP BIMA', 'RESUBMIT AO', 'INSTALASI', 'HOLD', 'JT', 'CNDC', null, 'CLOSE', 'CANCEL'] as $i => $category) {
            $this->order('DASH-'.$i, $this->branch->getKey(), ['progress_category' => $category,
                'operational_status' => $category === 'CANCEL' ? 'CANCELLED' : 'IN_PROGRESS',
                'tgl_order' => $i % 2 ? '2026-10-24' : '2026-10-25',
                'task_bima' => $category === 'CLOSE' ? 'Approval E2E Testing Manual' : 'Review Order TSQ',
                'status_bima' => $category === 'CLOSE' ? 'COMPLETE' : 'STARTWORK', 'tgl_ps' => $category === 'CLOSE' ? '2026-10-25' : null]);
        }
        $this->order('OTHER-REGION', $next->getKey(), ['progress_category' => 'INSTALASI']);
        $this->order('NO-REGION', $noRegion->getKey(), ['owner_grup' => null]);
        $this->order('UNASSIGNED', null, ['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'tgl_ps' => '2026-10-25']);
        $this->order('MODIFY-ONLY', $this->branch->getKey(), ['jenis_pekerjaan' => 'MODIFY', 'layanan' => 'INTERNET']);
    }

    private function order(string $id, ?int $branch, array $extra = []): WorkOrder
    {
        return WorkOrder::create($extra + ['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => $branch,
            'jenis_pekerjaan' => 'NEW INSTALL', 'layanan' => 'METRO', 'tgl_order' => '2026-10-25', 'owner_grup' => 'OWNER-TEST',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_matrix_structure_categories_and_region_subtotals_reconcile_with_every_clickable_number(): void
    {
        $this->fixtures();
        $before = WorkOrder::orderBy('id_work_orders')->get()->toArray();
        $response = $this->actingAs($this->area)->get(route('area.dashboard'))->assertOk()->assertSee('On Going')->assertSee('Complete')
            ->assertSee('New Order Hari ini')->assertDontSee('Dalam proses')->assertDontSee('Penutupan');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(12, $xpath->query('//table[contains(@class,"area-matrix")]/tfoot/tr/*')->length);
        $this->assertSame(['Survey', 'OGP BIMA', 'Instalasi', 'Kendala', 'JT', 'Lainnya', 'Cancel', 'CLOSE'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//table[contains(@class,"area-matrix")]/thead/tr[2]/th'))));
        $this->assertGreaterThanOrEqual(3, $xpath->query('//table[contains(@class,"area-matrix")]//button[@data-region-toggle and @aria-expanded="false"]')->length);
        $ownerPage = $this->get(route('area.dashboard', ['tab' => 'owner-group']))->assertOk();
        $ownerXpath = $this->xpath($ownerPage->getContent());
        $this->assertGreaterThanOrEqual(3, $ownerXpath->query('//table[contains(@class,"owner-group-matrix")]//button[@data-region-toggle and @aria-expanded="false"]')->length);
        foreach ($ownerXpath->query('//a[@data-dashboard-orders]') as $link) {
            $list = $this->get($link->getAttribute('href'))->assertOk();
            $this->assertSame((int) str_replace(',', '', $link->textContent), $list->viewData('total'));
        }
        foreach ($xpath->query('//button[@data-region-toggle]') as $button) {
            $this->assertSame(1, $xpath->query('//tbody[@id="'.$button->getAttribute('aria-controls').'" and @hidden]')->length);
        }
        $grand = $response->viewData('grand');
        $this->assertSame($grand['total'], array_sum(array_intersect_key($grand, array_flip(['survey', 'bima', 'instalasi', 'kendala', 'jt', 'other', 'cancel', 'close']))));
        $this->assertSame(2, $grand['bima']);
        $this->assertSame(2, $grand['instalasi']);
        $this->assertSame(4, $grand['other']); // CNDC, unset progress, and two orders without progress.
        $this->assertSame(9, $grand['incoming_today']);
        $this->assertSame(2, $grand['ps_today']);
        foreach ($response->viewData('ownerGroups')['regions'] as $region) {
            $this->assertSame($region['total'], array_sum(array_column($region['rows'], 'total')));
            $this->assertSame($region['total'], array_sum($region['totals']));
        }
        $links = $xpath->query('//a[@data-dashboard-orders]');
        $this->assertGreaterThan(100, $links->length);
        foreach ($links as $link) {
            if ($xpath->query('.//circle', $link)->length) {
                $this->assertMatchesRegularExpression('/:\s*([\d,]+) order$/u', $link->getAttribute('aria-label'));
                preg_match('/:\s*([\d,]+) order$/u', $link->getAttribute('aria-label'), $count);
                $expected = (int) str_replace(',', '', $count[1]);
            } else {
                $expected = (int) str_replace(',', '', trim($link->textContent));
            }
            $parts = parse_url($link->getAttribute('href'));
            parse_str($parts['query'] ?? '', $filters);
            $modal = $this->get(route('work-orders.index', $filters + ['modal' => 1]))->assertOk()->assertHeader('X-Dashboard-Fragment', 'orders');
            $this->assertSame($expected, $modal->viewData('orders')->total(), $link->getAttribute('aria-label').' '.json_encode($filters));
            foreach ($modal->viewData('orders') as $order) {
                $modal->assertSee(route('work-orders.show', $order))->assertSee('Detail Order '.$order->no_tq_sc_id);
            }
        }
        $this->assertSame($before, WorkOrder::orderBy('id_work_orders')->get()->toArray());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_modal_respects_active_filters_branch_authorization_and_independent_unassigned_and_date_dimensions(): void
    {
        $this->fixtures();
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor)->get(route('branch.dashboard'))->assertOk()->assertSee('dashboard-order-dialog');
        $response = $this->get(route('work-orders.index', ['modal' => 1, 'jenis_pekerjaan' => 'NEW INSTALL', 'bucket' => 'instalasi']))->assertOk()->assertDontSee('OTHER-REGION');
        $this->assertSame(['DASH-3'], $response->viewData('orders')->pluck('no_tq_sc_id')->all());
        $other = Branch::where('code', 'DASH-NEXT')->sole();
        $this->get(route('work-orders.index', ['modal' => 1, 'branch' => $other->getKey()]))->assertForbidden();
        $this->get(route('work-orders.index', ['modal' => 1, 'region' => 2]))->assertOk()->assertSee('Belum ada order untuk pilihan ini');
        $this->actingAs($this->area);
        $unassigned = $this->get(route('work-orders.index', ['modal' => 1, 'jenis_pekerjaan' => 'all', 'unassigned' => 1, 'queue' => 'ps_today']))->assertOk();
        $this->assertSame(['UNASSIGNED'], $unassigned->viewData('orders')->pluck('no_tq_sc_id')->all());
        $this->assertSame(2, $this->get(route('work-orders.index', ['modal' => 1, 'without_region' => 1]))->viewData('orders')->total());
        $this->assertSame(2, $this->get(route('work-orders.index', ['modal' => 1, 'ps_date' => '2026-10-25']))->viewData('orders')->total());
        $filtered = ['modal' => 1, 'region' => 1, 'branch' => $this->branch->getKey(), 'service' => 'INTERNET', 'jenis_pekerjaan' => 'MODIFY', 'owner_group' => 'DISTRICT'];
        $this->assertSame(['MODIFY-ONLY'], $this->get(route('work-orders.index', $filtered))->viewData('orders')->pluck('no_tq_sc_id')->all());
        foreach ([['modal' => 2], ['bucket' => 'unsafe'], ['unassigned' => 'yes'], ['without_region' => 'unsafe'], ['ps_date' => '2026-02-31']] as $invalid) {
            $this->getJson(route('work-orders.index', $invalid))->assertUnprocessable();
        }
        $this->actingAs($this->user(Role::BranchAdmin, false))->get(route('work-orders.index', ['modal' => 1]))->assertForbidden();
        $this->assertSame('other', ProgressSummary::bucket(WorkOrder::where('no_tq_sc_id', 'DASH-6')->sole()));
        $this->actingAs($this->area)->get(route('work-orders.index', ['modal' => 1, 'bucket' => 'cndc']))->assertOk()->assertSee('DASH-6')->assertDontSee('DASH-3');
    }

    public function test_modal_pagination_escaping_and_detail_links_do_not_return_private_source_or_foreign_orders(): void
    {
        $branch = $this->branch;
        for ($i = 0; $i < 27; $i++) {
            $this->order('PAGE-'.$i, $branch->getKey(), ['nama_olo' => '<script>unsafe()</script>', 'source_bima_fields' => ['secret' => 'PRIVATE-SOURCE']]);
        }
        $actor = $this->user();
        $actor->branches()->attach($branch);
        $this->actingAs($actor);
        $url = route('work-orders.index', ['modal' => 1, 'branch' => $branch->getKey(), 'jenis_pekerjaan' => 'NEW INSTALL']);
        $first = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()', false)->assertDontSee('PRIVATE-SOURCE')->assertDontSee('selection_token')->assertSee('Berikutnya');
        $this->assertSame(27, $first->viewData('orders')->total());
        $this->assertCount(25, $first->viewData('orders'));
        $next = $first->viewData('orders')->nextPageUrl();
        $this->assertStringContainsString('modal=1', $next);
        $second = $this->get($next)->assertOk()->assertSee('Sebelumnya');
        $this->assertCount(2, $second->viewData('orders'));
        foreach ($second->viewData('orders') as $order) {
            $this->get(route('work-orders.show', $order))->assertOk()->assertSee($order->no_tq_sc_id);
        }
    }

    public function test_sync_center_limits_to_latest_five_and_authorized_history_paginates_all_without_running_jobs(): void
    {
        for ($i = 0; $i < 27; $i++) {
            SyncJob::create(['idempotency_key' => (string) Str::uuid(), 'id_users' => $this->area->getKey(), 'direction' => 'import', 'stage' => 'commit', 'state' => 'success', 'finished_at' => now(), 'connection_hash' => hash('sha256', 'test')]);
        }
        $expected = SyncJob::latest('id_sync_jobs')->limit(5)->pluck('id_sync_jobs')->all();
        $index = $this->actingAs($this->area)->get(route('sync.index'))->assertOk()->assertSee('Menampilkan 5 riwayat terakhir.')->assertSee(route('sync.history'));
        $this->assertSame($expected, $index->viewData('jobs')->modelKeys());
        $this->assertSame(27, $index->viewData('jobCount'));
        $all = $this->get(route('sync.history'))->assertOk();
        $this->assertSame(27, $all->viewData('jobs')->total());
        $this->assertCount(20, $all->viewData('jobs'));
        $second = $this->get(route('sync.history', ['page' => 2]))->assertOk();
        $this->assertCount(7, $second->viewData('jobs'));
        foreach ([Role::BranchAdmin, Role::TeamLeader, Role::AreaCoordinator] as $role) {
            $this->actingAs($this->user($role))->get(route('sync.history'))->assertForbidden();
        }
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
