<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BimaAutoProgressService;
use App\Services\BranchProgressService;
use App\Services\PriorityOrderService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

class PriorityMonitoringTabsTest extends OloTestCase
{
    public function test_issue_rows_are_grouped_by_branch_with_independent_collapsed_controls(): void
    {
        $other = Branch::create(['code' => 'COLLAPSE-OTHER', 'name' => 'Branch Kedua']);
        foreach (['GROUP-A', 'GROUP-B'] as $id) {
            $this->issue($this->order($id), 'Update '.$id);
        }
        $this->issue($this->order('GROUP-C', branch: $other), 'Update GROUP-C');
        $page = $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new DOMXPath($dom);
        $buttons = $xpath->query('//table[contains(@class,"priority-issues-table")]//button[@data-region-toggle]');
        $this->assertSame(2, $buttons->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="priority-issues-title"]//button[@data-region-expand]')->length);
        $this->assertSame(0, $xpath->query('//table[contains(@class,"priority-issues-table")]//button[@data-region-open]')->length);
        foreach ($buttons as $i => $button) {
            $bodyId = $button->getAttribute('aria-controls');
            $this->assertSame(1, $xpath->query('//tbody[@id="'.$bodyId.'"]')->length);
            $this->assertSame($i === 0 ? 2 : 1, $xpath->query('//tbody[@id="'.$bodyId.'"]/tr')->length);
        }
        $this->assertSame(3, $page->viewData('orders')->total());
        $page->assertSee('2 order di halaman ini')->assertSee('1 order di halaman ini');
    }

    public function test_progress_only_orders_appear_for_branch_admin_and_hd_without_open_issues(): void
    {
        foreach ([Role::BranchAdmin, Role::BranchHd] as $role) {
            $actor = $this->user($role);
            $actor->branches()->attach($this->branch);
            $order = $this->order('PROGRESS-ONLY-'.$role->value);
            app(PriorityOrderService::class)->set($actor, $order, true);
            $note = 'Catatan progress tanpa kendala untuk '.$role->value;
            $this->actingAs($actor)->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'OGP BIMA', 'notes' => $note])->assertSessionHasNoErrors();
            $this->assertSame(0, $order->issues()->count());
            $before = $order->fresh()->getRawOriginal();
            $this->get(route('priorities.monitoring', ['tab' => 'issues', 'q' => $order->no_tq_sc_id]))->assertOk()->assertSee($note)->assertSee('OGP BIMA')
                ->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
            $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'issues', 'q' => $order->no_tq_sc_id]))->assertOk()->assertSee($note)
                ->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
    }

    public function test_updated_order_union_keeps_scope_filters_and_requires_matching_manual_event_provenance(): void
    {
        $branch = $this->branchActor();
        $valid = $this->order('MATCH-MANUAL');
        app(BranchProgressService::class)->update($branch, $valid, ['revision' => 0, 'progress_category' => 'JT', 'notes' => 'Manual Branch update']);
        $this->issue($this->order('MATCH-ISSUE'), 'Active issue only');
        $this->order('UNTOUCHED');
        $auto = $this->order('AUTOMATIC-ONLY');
        $auto->update(['task_bima' => 'Activate Access Manual', 'status_bima' => 'STARTWORK']);
        app(BimaAutoProgressService::class)->apply($auto, $this->area);
        $unmatched = $this->order('UNMATCHED-AUDIT');
        AuditLog::create(['id_users' => $this->area->getKey(), 'action' => 'progress.changed', 'entity_type' => 'work_order', 'id_entity' => $unmatched->getKey(), 'metadata' => ['revision' => 99]]);
        $unmatched->events()->create(['id_users' => $branch->getKey(), 'revision' => 1, 'old_status' => 'NEW', 'new_status' => 'SURVEY', 'notes' => 'Event without matching audit']);
        $other = Branch::create(['code' => 'UPDATED-OTHER', 'name' => 'Other Updated Branch']);
        $otherUser = $this->branchActor($other);
        $private = $this->order('PRIVATE-PROGRESS', $otherUser, $other);
        app(BranchProgressService::class)->update($otherUser, $private, ['revision' => 0, 'progress_category' => 'INSTALASI', 'notes' => 'Private progress only']);
        $notPriority = $this->order('NOT-PRIORITY');
        app(BranchProgressService::class)->update($branch, $notPriority, ['revision' => 0, 'progress_category' => 'SURVEY', 'notes' => 'Unbookmarked progress']);
        $notPriority->priorities()->delete();
        $ps = $this->order('PS-PROGRESS');
        app(BranchProgressService::class)->update($branch, $ps, ['revision' => 0, 'progress_category' => 'SURVEY', 'notes' => 'PS progress excluded']);
        $ps->update(['task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE', 'progress_category' => 'CLOSE']);
        $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Manual Branch update')->assertSee('Active issue only')
            ->assertDontSee('UNTOUCHED')->assertDontSee('AUTOMATIC-ONLY')->assertDontSee('UNMATCHED-AUDIT')->assertDontSee('Private progress only')
            ->assertDontSee('Unbookmarked progress')->assertDontSee('PS progress excluded')->assertViewHas('orders', fn ($rows) => $rows->total() === 2);
        $this->get(route('priorities.monitoring', ['tab' => 'issues', 'q' => 'MATCH-MANUAL']))->assertViewHas('orders', fn ($rows) => $rows->total() === 1)->assertDontSee('Active issue only');
        $this->get(route('priorities.monitoring', ['tab' => 'issues', 'state' => 'issues']))->assertViewHas('orders', fn ($rows) => $rows->total() === 1)->assertSee('Active issue only')->assertDontSee('Manual Branch update');
        $this->actingAs($branch)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertViewHas('orders', fn ($rows) => $rows->total() === 2)->assertDontSee('Private progress only');
        $this->actingAs($otherUser)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertViewHas('orders', fn ($rows) => $rows->total() === 1)->assertSee('Private progress only')->assertDontSee('Manual Branch update');
    }

    private function order(string $id, ?User $selector = null, ?Branch $branch = null): WorkOrder
    {
        $order = WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $id, 'id_branches' => ($branch ?? $this->branch)->getKey(),
            'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK', 'jenis_pekerjaan' => 'MODIFY', 'progress_category' => 'SURVEY',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $id)]);
        app(PriorityOrderService::class)->set($selector ?? $this->area, $order, true);

        return $order;
    }

    private function branchActor(?Branch $branch = null): User
    {
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($branch ?? $this->branch);

        return $actor;
    }

    private function issue(WorkOrder $order, string $text, string $status = 'OPEN', ?string $created = null)
    {
        return $order->issues()->create(['id_users' => $this->area->getKey(), 'category' => 'OTHER', 'description' => $text, 'pic' => 'PIC',
            'status' => $status, 'created_at' => $created ?? now()]);
    }

    public function test_tabs_preserve_filters_and_display_latest_branch_progress_or_issue_update(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 01:00:00', 'UTC'));
        $branch = $this->branchActor();
        $order = $this->order('LATEST-ISSUE');
        app(PriorityOrderService::class)->set($branch, $order, true);
        $this->actingAs($branch)->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => 'HOLD', 'notes' => 'Kendala awal akses lokasi.'])->assertSessionHasNoErrors();
        $order->events()->where('revision', 1)->update(['created_at' => now()]);
        $this->travel(1)->minutes();
        $latestText = 'PIC meminta jadwal kunjungan ulang.';
        $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 1, 'progress_category' => 'PERIZINAN', 'notes' => $latestText])->assertSessionHasNoErrors();
        $order->events()->where('revision', 2)->update(['created_at' => now()]);
        // The database CURRENT_TIMESTAMP does not follow Carbon's test clock.
        $order->issues()->sole()->notes()->sole()->update(['created_at' => now()]);
        $this->travel(1)->minutes();
        $progressText = 'Instalasi dilanjutkan setelah koordinasi lokasi.';
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 2, 'progress_category' => 'INSTALASI', 'notes' => $progressText])->assertSessionHasNoErrors();
        $order->events()->where('revision', 3)->update(['created_at' => now()]);
        $before = $order->fresh()->getRawOriginal();
        $filters = ['tab' => 'issues', 'branch' => $this->branch->getKey(), 'q' => 'LATEST-', 'source' => 'branch'];
        $page = $this->get(route('priorities.monitoring', $filters))->assertOk()->assertSee($progressText)->assertSee('INSTALASI')->assertSee('Site Survey-STARTWORK')
            ->assertSee('08:02:00 WIB')->assertDontSee('Kendala awal akses lokasi.')->assertDontSee($latestText)
            ->assertDontSee('Rincian Order per Branch')->assertDontSee('Status BIMA × Owner Group');
        $this->assertSame(1, $page->viewData('orders')->total());
        $dom = new DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new DOMXPath($dom);
        $this->assertSame(['Branch', 'No WO / TQ-SC-ID', 'Status order', 'Update Progress/Kendala'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//table[contains(@class,"priority-issues-table")]/thead/tr/th'))));
        $this->assertSame(3, $xpath->query('//nav[@aria-label="Tab Monitoring Order Prioritas"]/a')->length);
        $this->assertSame('priority-tab-issues', $xpath->query('//nav[@aria-label="Tab Monitoring Order Prioritas"]/a[@aria-current="page"]')->item(0)->getAttribute('id'));
        $this->assertSame(1, $xpath->query('//form//input[@name="tab" and @value="issues"]')->length);
        foreach (['branch', 'owner-group'] as $tab) {
            $url = $xpath->query('//a[@id="priority-tab-'.$tab.'"]')->item(0)->getAttribute('href');
            parse_str(parse_url($url, PHP_URL_QUERY), $params);
            $this->assertEquals(array_replace($filters, ['tab' => $tab]), $params);
            $tabPage = $this->get($url)->assertOk()->assertViewHas('activeTab', $tab);
            $tab === 'branch' ? $tabPage->assertSee('Rincian Order per Branch')->assertDontSee('Status BIMA × Owner Group')
                : $tabPage->assertSee('Status BIMA × Owner Group')->assertSee('Rincian Task per Owner Group')->assertDontSee('Rincian Order per Branch');
        }
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $issue = $order->issues()->sole();
        $this->travel(1)->minutes();
        $this->post(route('issues.notes', $issue), ['revision' => 3, 'note' => 'Update kendala setelah progress instalasi.'])->assertSessionHasNoErrors();
        $issue->notes()->where('note', 'Update kendala setelah progress instalasi.')->update(['created_at' => now()]);
        $this->get(route('priorities.monitoring', $filters))->assertOk()->assertSee('Update kendala setelah progress instalasi.')->assertSee('08:03:00 WIB')->assertDontSee($progressText);
        $this->patch(route('issues.resolve', $issue), ['revision' => 4, 'resolution' => 'Akses sudah diberikan.'])->assertSessionHasNoErrors();
        $this->get(route('priorities.monitoring', $filters))->assertOk()->assertViewHas('orders', fn ($rows) => $rows->total() === 1)
            ->assertSee($progressText)->assertDontSee('Update kendala setelah progress instalasi.');
    }

    public function test_latest_update_is_selected_across_open_issues_by_time_not_just_id_and_resolved_data_is_ignored(): void
    {
        $order = $this->order('MULTIPLE-ISSUES');
        $first = $this->issue($order, 'Older original', created: '2026-10-27 01:00:00');
        $second = $this->issue($order, 'Newer original', created: '2026-10-27 02:00:00');
        $first->notes()->create(['id_users' => $this->area->getKey(), 'note' => 'Latest open note <script>alert(1)</script>', 'created_at' => '2026-10-27 04:00:00']);
        $first->notes()->create(['id_users' => $this->area->getKey(), 'note' => 'Higher ID with older time', 'created_at' => '2026-10-27 03:00:00']);
        $resolved = $this->issue($order, 'Resolved issue newer than open', 'RESOLVED', '2026-10-27 05:00:00');
        $resolved->notes()->create(['id_users' => $this->area->getKey(), 'note' => 'Newest but resolved', 'created_at' => '2026-10-27 06:00:00']);
        $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()
            ->assertSee('Latest open note <script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Higher ID with older time')->assertDontSee('Newest but resolved')->assertDontSee('Newer original')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
        $second->update(['created_at' => '2026-10-27 07:00:00']);
        $this->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Newer original')->assertDontSee('Latest open note');
    }

    public function test_automatic_bima_and_assignment_events_never_replace_manual_progress(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 01:00:00', 'UTC'));
        $branch = $this->branchActor();
        $order = $this->order('HUMAN-UPDATE');
        $this->issue($order, 'Initial issue');
        $this->actingAs($branch)->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'SURVEY', 'notes' => 'Survey lapangan oleh Branch.'])->assertSessionHasNoErrors();
        $order->events()->where('revision', 1)->update(['created_at' => now()]);
        $this->travel(1)->minutes();
        $order->update(['task_bima' => 'Activate Access Manual', 'status_bima' => 'STARTWORK']);
        app(BimaAutoProgressService::class)->apply($order, $this->area);
        $order->events()->where('revision', 2)->update(['created_at' => now()]);
        $order->events()->create(['id_users' => $this->area->getKey(), 'old_status' => 'IN_PROGRESS', 'new_status' => 'IN_PROGRESS',
            'old_progress_category' => 'INSTALASI', 'new_progress_category' => 'INSTALASI', 'revision' => 3,
            'notes' => 'Automatic assignment event', 'created_at' => now()->addMinute()]);
        $this->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Survey lapangan oleh Branch.')
            ->assertDontSee('Penyesuaian otomatis')->assertDontSee('Automatic assignment event')->assertDontSee('Initial issue');
    }

    public function test_audit_order_breaks_same_second_ties_between_manual_progress_and_issue_follow_up(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 01:00:00', 'UTC'));
        $branch = $this->branchActor();
        $order = $this->order('SAME-SECOND');
        $this->actingAs($branch)->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 0, 'progress_category' => 'HOLD', 'notes' => 'Kendala awal dalam detik yang sama.'])->assertSessionHasNoErrors();
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 1, 'progress_category' => 'INSTALASI', 'notes' => 'Progress lebih baru pada detik yang sama.'])->assertSessionHasNoErrors();
        $order->events()->update(['created_at' => now()]);
        $this->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Progress lebih baru pada detik yang sama.')->assertDontSee('Kendala awal dalam detik yang sama.');
        $issue = $order->issues()->sole();
        $this->post(route('issues.notes', $issue), ['revision' => 2, 'note' => 'Tindak lanjut paling akhir pada detik yang sama.'])->assertSessionHasNoErrors();
        $issue->notes()->update(['created_at' => now()]);
        $this->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Tindak lanjut paling akhir pada detik yang sama.')->assertDontSee('Progress lebih baru pada detik yang sama.');
    }

    public function test_issue_tab_preserves_priority_privacy_assignment_filters_ps_exclusion_and_permissions(): void
    {
        $other = Branch::create(['code' => 'ISSUE-OTHER', 'name' => 'Other Branch']);
        $branch = $this->branchActor();
        $otherUser = $this->branchActor($other);
        $shared = $this->order('SHARED');
        $this->issue($shared, 'Shared issue');
        app(PriorityOrderService::class)->set($branch, $shared, true);
        $local = $this->order('LOCAL-SECRET', $branch);
        $this->issue($local, 'Private Branch note');
        $foreign = $this->order('FOREIGN-PRIVATE', $otherUser, $other);
        $this->issue($foreign, 'Other private note');
        $resolved = $this->order('RESOLVED');
        $this->issue($resolved, 'Resolved note', 'RESOLVED');
        $notPriority = $this->order('NO-PRIORITY');
        $notPriority->priorities()->delete();
        $this->issue($notPriority, 'Not bookmarked');
        $ps = $this->order('PS-EXCLUDED');
        $this->issue($ps, 'PS old issue');
        $ps->update(['progress_category' => 'CLOSE', 'task_bima' => 'Approval E2E Testing Manual', 'status_bima' => 'COMPLETE']);
        $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Shared issue')
            ->assertDontSee('Private Branch note')->assertDontSee('Other private note')->assertDontSee('Resolved note')->assertDontSee('Not bookmarked')->assertDontSee('PS old issue')
            ->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        $this->actingAs($branch)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertOk()->assertSee('Private Branch note')->assertSee('Shared issue')->assertDontSee('Other private note')
            ->assertViewHas('orders', fn ($rows) => $rows->total() === 2);
        $this->get(route('priorities.monitoring', ['tab' => 'issues', 'source' => 'area']))->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        $this->get(route('priorities.monitoring', ['tab' => 'issues', 'branch' => $other->getKey()]))->assertForbidden();
        $this->getJson(route('priorities.monitoring', ['tab' => 'forged']))->assertUnprocessable()->assertJsonValidationErrors('tab');
        $this->actingAs($this->user(Role::AreaCoordinator))->get(route('priorities.monitoring', ['tab' => 'issues']))->assertForbidden();
        $this->actingAs($this->area)->get(route('priorities.monitoring', ['tab' => 'issues', 'q' => 'ABSENT']))->assertOk()->assertSee('Belum ada order prioritas dengan update progress/kendala');
        $shared->update(['id_branches' => $other->getKey()]);
        $this->actingAs($branch)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertViewHas('orders', fn ($rows) => $rows->total() === 1)->assertDontSee('Shared issue');
        $this->actingAs($otherUser)->get(route('priorities.monitoring', ['tab' => 'issues']))->assertSee('Shared issue')->assertDontSee('Private Branch note');
    }

    public function test_issue_queries_are_bounded_by_page_and_pagination_keeps_tab_and_filters(): void
    {
        $branch = $this->branchActor();
        $this->actingAs($this->area);
        $queriesBySize = [];
        foreach ([5, 30] as $size) {
            for ($i = WorkOrder::count(); $i < $size; $i++) {
                $order = $this->order('PAGED-'.str_pad($i, 3, '0', STR_PAD_LEFT));
                if ($i % 2 === 0) {
                    $issue = $this->issue($order, 'Original issue '.$i);
                    foreach ([1, 2, 3] as $version) {
                        $issue->notes()->create(['id_users' => $this->area->getKey(), 'note' => 'Updated issue '.$i.' v'.$version]);
                    }
                }
                app(BranchProgressService::class)->update($branch, $order, ['revision' => 0, 'progress_category' => 'INSTALASI', 'notes' => 'Manual progress '.$i]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $page = $this->get(route('priorities.monitoring', ['tab' => 'issues', 'q' => 'PAGED-']))->assertOk();
                $queriesBySize[$size] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertLessThanOrEqual(12, $queriesBySize[$size]);
            $this->assertCount(min($size, 25), $page->viewData('orders'));
        }
        $this->assertSame($queriesBySize[5], $queriesBySize[30]);
        $this->get(route('priorities.monitoring', ['tab' => 'issues', 'q' => 'PAGED-', 'page' => 2]))->assertOk()->assertViewHas('activeTab', 'issues')
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 30 && $orders->count() === 5)->assertSee('tab=issues', false)->assertSee('q=PAGED-', false);
        $this->get(route('priorities.monitoring', ['tab' => 'issues', 'page' => 99]))->assertOk()->assertSee('Tidak ada order di halaman ini.')->assertSee('Kembali ke halaman pertama');
    }
}
