<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BimaTaskLog;
use App\Models\WorkOrder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BimaLogEncryptionTest extends OloTestCase
{
    private function order(string $number = 'MAC-TEST'): WorkOrder
    {
        return WorkOrder::create(['source_system' => 'BIMA', 'no_tq_sc_id' => $number, 'id_branches' => $this->branch->getKey(),
            'task_bima' => 'Site Survey', 'status_bima' => 'STARTWORK', 'progress_category' => 'SURVEY', 'tgl_order' => '2026-10-20',
            'source_bima_fields' => [], 'source_snapshot_hash' => hash('sha256', $number)]);
    }

    private function log(WorkOrder $order): BimaTaskLog
    {
        return $order->taskLogs()->create(['id_users' => $this->area->getKey(), 'changed_at' => now(), 'old_status_order' => 'Site Survey-STARTWORK',
            'new_status_order' => 'Site Survey-COMPLETE', 'field_changes' => ['alamat' => ['label' => 'ALAMAT', 'before' => 'Alamat lama sintetis', 'after' => 'Alamat baru sintetis']]]);
    }

    private function foreignPayload(BimaTaskLog $log): array
    {
        $key = random_bytes(32);
        $encrypted = (new Encrypter($key, config('app.cipher')))->encryptString(json_encode(['alamat' => ['label' => 'ALAMAT', 'before' => 'private-legacy-before', 'after' => 'private-legacy-after']]));
        DB::table('bima_task_logs')->where('id_bima_task_logs', $log->getKey())->update(['field_changes' => $encrypted]);

        return [$key, $encrypted];
    }

    public function test_foreign_key_log_does_not_break_detail_or_redirect_after_progress_and_issue_saves(): void
    {
        $order = $this->order();
        $bad = $this->log($order);
        [, $ciphertext] = $this->foreignPayload($bad);
        $this->log($order);
        $branch = $this->user(Role::BranchHd);
        $branch->branches()->attach($this->branch);
        Log::spy();
        $this->actingAs($branch)->get(route('work-orders.show', $order))->assertOk()->assertSee('Sebagian rincian Log Task BIMA belum dapat dibaca')
            ->assertSee('Alamat baru sintetis')->assertSee('Site Survey-COMPLETE')->assertDontSee('private-legacy-before');
        $this->patch(route('work-orders.action.update', [$order, 'progress']), ['revision' => 0, 'progress_category' => 'OGP BIMA', 'notes' => 'Pembaruan progress tetap tersimpan.'])
            ->assertSessionHasNoErrors()->assertRedirect(route('work-orders.show', $order));
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Pembaruan progress tetap tersimpan.');
        $this->patch(route('work-orders.action.update', [$order, 'issue']), ['revision' => 1, 'progress_category' => 'HOLD', 'notes' => 'Kendala menunggu akses lokasi.'])
            ->assertSessionHasNoErrors()->assertRedirect(route('work-orders.show', $order));
        $this->get(route('work-orders.show', $order))->assertOk()->assertSee('Kendala menunggu akses lokasi.');
        $this->assertSame(2, $order->fresh()->revision);
        $this->assertDatabaseCount('wo_status_events', 2);
        $this->assertDatabaseCount('wo_issues', 1);
        $this->assertDatabaseCount('bima_task_logs', 2);
        $this->assertSame($ciphertext, $bad->fresh()->getRawOriginal('field_changes'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'bima_task_log.unreadable' && array_keys($context) === ['id_work_orders', 'id_bima_task_logs']
            && $context['id_bima_task_logs'] === [$bad->getKey()]);
        $this->expectException(DecryptException::class);
        $bad->fresh()->field_changes;
    }

    public function test_configured_previous_key_recovers_history_without_rewriting_it_and_new_data_uses_current_key(): void
    {
        $order = $this->order();
        $legacy = $this->log($order);
        [$oldKey, $ciphertext] = $this->foreignPayload($legacy);
        $currentKey = random_bytes(32);
        config(['app.key' => 'base64:'.base64_encode($currentKey), 'app.previous_keys' => ['base64:'.base64_encode($oldKey)]]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->actingAs($this->area)->get(route('work-orders.show', $order))->assertOk()->assertSee('private-legacy-before')->assertSee('private-legacy-after')
            ->assertDontSee('Sebagian rincian Log Task BIMA belum dapat dibaca');
        $this->assertSame($ciphertext, $legacy->fresh()->getRawOriginal('field_changes'));
        $new = $this->log($order);
        $decrypted = (new Encrypter($currentKey, config('app.cipher')))->decryptString($new->getRawOriginal('field_changes'));
        $this->assertStringContainsString('Alamat baru sintetis', $decrypted);
        $this->assertSame(0, Artisan::call('olo:check-bima-log-encryption', ['--work-order' => $order->getKey()]));
        $this->assertStringContainsString('Tidak dapat dibaca: 0', Artisan::output());
    }

    public function test_diagnostic_is_read_only_scoped_and_does_not_print_keys_payload_or_customer_data(): void
    {
        $order = $this->order();
        $bad = $this->log($order);
        [$key, $ciphertext] = $this->foreignPayload($bad);
        $this->log($order);
        $this->log($order)->update(['field_changes' => null]);
        $this->foreignPayload($this->log($this->order('ANOTHER-ORDER')));
        $before = DB::table('bima_task_logs')->orderBy('id_bima_task_logs')->get()->toJson();
        $this->assertSame(1, Artisan::call('olo:check-bima-log-encryption', ['--work-order' => $order->getKey()]));
        $output = Artisan::output();
        $this->assertStringContainsString('Diperiksa: 3', $output);
        $this->assertStringContainsString('Tidak dapat dibaca: 1', $output);
        foreach (['private-legacy', $ciphertext, base64_encode($key), 'Alamat baru sintetis'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertSame($before, DB::table('bima_task_logs')->orderBy('id_bima_task_logs')->get()->toJson());
        $this->assertSame(2, Artisan::call('olo:check-bima-log-encryption', ['--work-order' => 'invalid']));
    }

    public function test_tampered_payload_stays_unreadable_and_another_branch_is_denied_before_presentation(): void
    {
        $order = $this->order();
        $log = $this->log($order);
        $payload = json_decode(base64_decode($log->getRawOriginal('field_changes')), true);
        $payload['mac'] = str_repeat('0', 64);
        DB::table('bima_task_logs')->where('id_bima_task_logs', $log->getKey())->update(['field_changes' => base64_encode(json_encode($payload))]);
        Log::spy();
        $this->actingAs($this->user(Role::BranchHd))->get(route('work-orders.show', $order))->assertForbidden();
        Log::shouldNotHaveReceived('warning');
        $this->actingAs($this->area)->get(route('work-orders.show', $order))->assertOk()->assertSee('Rincian perubahan log ini belum dapat dibaca');
        $this->assertSame(1, Artisan::call('olo:check-bima-log-encryption', ['--work-order' => $order->getKey()]));
    }

    public function test_authenticated_but_malformed_change_data_is_not_silently_reported_as_empty_history(): void
    {
        $order = $this->order();
        foreach (['{invalid-json', '"not-an-array"', '{"alamat":{"before":[],"after":"private-invalid-after"}}'] as $json) {
            $log = $this->log($order);
            DB::table('bima_task_logs')->where('id_bima_task_logs', $log->getKey())->update(['field_changes' => Crypt::encryptString($json)]);
        }
        $this->actingAs($this->area)->get(route('work-orders.show', $order))->assertOk()->assertSee('Sebagian rincian Log Task BIMA belum dapat dibaca')
            ->assertDontSee('private-invalid-after');
        $this->assertSame(1, Artisan::call('olo:check-bima-log-encryption', ['--work-order' => $order->getKey()]));
        $this->assertStringContainsString('Masalah enkripsi: 0; Format tidak sesuai: 3', Artisan::output());
        $this->assertDatabaseCount('bima_task_logs', 3);
    }
}
