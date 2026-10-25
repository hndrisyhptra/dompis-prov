<?php

namespace Tests\Feature;

use App\Contracts\TelegramGateway as Gateway;
use App\Enums\Role;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MaterialReport;
use App\Models\TelegramChat;
use App\Models\TelegramIdentity;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Material\TelegramMaterialService;
use App\Services\Telegram\HttpTelegramGateway;
use App\Services\Telegram\TelegramException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\TelegramGateway;

class TelegramMaterialTest extends OloTestCase
{
    private User $reporter;

    private TelegramGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['telegram.enabled' => true, 'telegram.webhook_secret' => str_repeat('s', 40), 'telegram.bot_token' => '12345:synthetic_test_token']);
        $this->gateway = new TelegramGateway;
        $this->app->instance(Gateway::class, $this->gateway);
        $this->reporter = $this->user(Role::BranchHd);
        $this->reporter->branches()->attach($this->branch);
        TelegramIdentity::create(['id_users' => $this->reporter->getKey(), 'telegram_user_id' => 1001, 'active' => true]);
        TelegramChat::create(['telegram_chat_id' => -100100, 'chat_type' => 'supergroup', 'id_branches' => $this->branch->getKey(), 'active' => true]);
        $this->pull([$this->row('000MAT')]);
    }

    private function payload(string $text = "/material\nNO TQ/SC/ID: 000MAT\nSN_ONT: 000SN\nNik Teknisi: 001234\nTotal DC: 12.125\nNama Pelanggan: PRIVATE-CUSTOMER", int $update = 100, int $message = 50): array
    {
        return ['update_id' => $update, 'message' => ['message_id' => $message, 'from' => ['id' => 1001, 'is_bot' => false], 'chat' => ['id' => -100100, 'type' => 'supergroup'], 'text' => $text]];
    }

    private function receive(array $payload)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', config('telegram.webhook_secret'))->postJson(route('telegram.material.webhook'), $payload);
    }

    private function process(array $payload): TelegramUpdate
    {
        $this->receive($payload)->assertOk();
        $inbox = TelegramUpdate::where('telegram_update_id', $payload['update_id'])->sole();
        (new ProcessTelegramUpdate($inbox->getKey()))->handle(app(TelegramMaterialService::class));

        return $inbox->fresh();
    }

    public function test_webhook_secret_disabled_invalid_envelopes_and_queue_ingestion_are_protected(): void
    {
        $this->postJson(route('telegram.material.webhook'), $this->payload())->assertForbidden();
        config(['telegram.enabled' => false]);
        $this->receive($this->payload())->assertStatus(503);
        config(['telegram.enabled' => true]);
        $this->receive(['update_id' => -1])->assertUnprocessable();
        $this->receive($this->payload())->assertOk();
        Queue::assertPushed(ProcessTelegramUpdate::class);
        $this->assertDatabaseCount('material_reports', 0);
        $this->assertStringNotContainsString('PRIVATE-CUSTOMER', DB::table('telegram_updates')->sole()->payload);
    }

    public function test_valid_report_preserves_text_and_nulls_and_does_not_mutate_order_or_duplicate_on_retry(): void
    {
        $before = WorkOrder::sole()->getRawOriginal();
        $inbox = $this->process($this->payload());
        $report = MaterialReport::sole();
        $this->assertSame('processed', $inbox->state);
        $this->assertSame('000SN', $report->sn_ont);
        $this->assertSame('001234', $report->nik_teknisi);
        $this->assertSame('12.125', $report->total_dc);
        $this->assertNull($report->tiang);
        $this->assertSame($this->branch->getKey(), $report->id_branches);
        $this->assertSame($before, WorkOrder::sole()->getRawOriginal());
        $this->receive($this->payload())->assertOk();
        app(TelegramMaterialService::class)->process($inbox->getKey());
        $this->process($this->payload(update: 101)); // Different update_id but the same chat/message identity.
        $this->assertDatabaseCount('material_reports', 1);
        $this->assertSame(1, AuditLog::where('action', 'material.submitted')->count());
        $this->assertStringNotContainsString('PRIVATE-CUSTOMER', json_encode(AuditLog::latest('id_audit_logs')->first()->metadata));
        (new DeliverTelegramMessage(TelegramOutbox::first()->getKey()))->handle($this->gateway);
        $this->assertCount(1, $this->gateway->messages);
        $this->assertStringContainsString($report->report_number, $this->gateway->messages[0]['text']);
        (new DeliverTelegramMessage(TelegramOutbox::first()->getKey()))->handle($this->gateway);
        $this->assertCount(1, $this->gateway->messages);
    }

    public function test_correction_is_append_only_and_complete_snapshot_with_no_double_active_report(): void
    {
        $this->process($this->payload());
        $original = MaterialReport::sole();
        $this->process($this->payload("/koreksi {$original->report_number}\nNO TQ/SC/ID: 000MAT\nTotal DC: 20\nSN_ONT: -", 101, 51));
        $this->assertDatabaseCount('material_reports', 2);
        $this->assertSame('superseded', $original->fresh()->state);
        $this->assertSame('12.125', $original->fresh()->total_dc);
        $latest = MaterialReport::where('state', 'submitted')->sole();
        $this->assertSame('20.000', $latest->total_dc);
        $this->assertNull($latest->sn_ont);
        $this->assertSame($original->getKey(), $latest->id_supersedes_material_reports);
        $this->process($this->payload("/koreksi {$original->report_number}\nNO TQ/SC/ID: 000MAT\nTotal DC: 99", 102, 52));
        $this->assertDatabaseCount('material_reports', 2);
        $this->assertSame(1, AuditLog::where('action', 'material.corrected')->count());
    }

    public function test_foreign_order_revoked_user_or_chat_forwarded_and_edited_messages_never_write_reports(): void
    {
        $other = Branch::create(['code' => 'MAT-OTHER', 'name' => 'Other Material Branch']);
        WorkOrder::sole()->update(['id_branches' => $other->getKey()]);
        $this->assertSame('rejected', $this->process($this->payload())->state);
        WorkOrder::sole()->update(['id_branches' => $this->branch->getKey()]);
        $this->reporter->forceFill(['active' => false])->save();
        $this->assertSame('rejected', $this->process($this->payload(update: 101, message: 51))->state);
        $this->reporter->forceFill(['active' => true])->save();
        TelegramChat::sole()->update(['active' => false]);
        $this->assertSame('rejected', $this->process($this->payload(update: 102, message: 52))->state);
        TelegramChat::sole()->update(['active' => true]);
        $forward = $this->payload(update: 103, message: 53);
        $forward['message']['forward_origin'] = ['type' => 'user'];
        $this->assertSame('ignored', $this->process($forward)->state);
        $edited = ['update_id' => 104, 'edited_message' => $this->payload()['message']];
        $this->assertSame('ignored', $this->process($edited)->state);
        $this->assertDatabaseCount('material_reports', 0);
    }

    public function test_template_id_lookup_and_validation_replies_do_not_create_reports(): void
    {
        $this->process($this->payload('/material'));
        $this->assertStringContainsString('LONGITUDE_ONT:', TelegramOutbox::sole()->payload['text']);
        $this->process($this->payload('/id', 101, 51));
        $this->assertStringContainsString('1001', TelegramOutbox::latest('id_telegram_outbox')->first()->payload['text']);
        $this->assertSame('rejected', $this->process($this->payload("/material\nNO TQ/SC/ID: 000MAT\nTotal DC: -3", 102, 52))->state);
        $this->assertDatabaseCount('material_reports', 0);
    }

    public function test_audit_failure_rolls_back_report_and_outbox_and_reply_failure_keeps_saved_report_for_retry(): void
    {
        $this->receive($this->payload())->assertOk();
        AuditLog::creating(fn () => throw new \RuntimeException('audit failure PRIVATE-CUSTOMER'));
        try {
            (new ProcessTelegramUpdate(TelegramUpdate::sole()->getKey()))->handle(app(TelegramMaterialService::class));
            $this->fail('Processing must roll back when auditing fails');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('PRIVATE-CUSTOMER', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('material_reports', 0);
        $this->assertDatabaseCount('telegram_outbox', 0);
        $this->travel(2)->minutes();
        (new ProcessTelegramUpdate(TelegramUpdate::sole()->getKey()))->handle(app(TelegramMaterialService::class));
        $this->gateway->fail = true;
        $outbox = TelegramOutbox::sole();
        (new DeliverTelegramMessage($outbox->getKey()))->handle($this->gateway);
        $this->assertSame('failed', $outbox->fresh()->state);
        $this->assertDatabaseCount('material_reports', 1);
        $this->travel(2)->minutes();
        $this->gateway->fail = false;
        (new DeliverTelegramMessage($outbox->getKey()))->handle($this->gateway);
        $this->assertSame('sent', $outbox->fresh()->state);
        $this->assertDatabaseCount('material_reports', 1);
    }

    public function test_http_adapter_uses_official_api_plain_text_and_sanitizes_token_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => ['message_id' => 90]])->push(['ok' => false, 'description' => 'synthetic_test_token'], 429)]);
        $this->assertSame('90', (new HttpTelegramGateway)->sendMessage('-100100', 'Template'));
        Http::assertSent(fn ($request) => $request['chat_id'] === '-100100' && $request['text'] === 'Template' && ! isset($request['parse_mode']));
        try {
            (new HttpTelegramGateway)->sendMessage('-100100', 'Template');
            $this->fail('API failures must be explicit');
        } catch (TelegramException $exception) {
            $this->assertStringNotContainsString('synthetic_test_token', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_material_pages_scope_roles_escape_report_text_and_keep_originating_branch_history(): void
    {
        $this->process($this->payload("/material\nNO TQ/SC/ID: 000MAT\nNama Pelanggan: <script>alert(1)</script>"));
        $report = MaterialReport::sole();
        foreach ([Role::SuperAdmin, Role::AreaCoordinator, Role::AreaAdmin, Role::AreaHd] as $role) {
            $this->actingAs($this->user($role))->get(route('materials.index'))->assertOk()->assertSee($report->report_number);
        }
        $this->actingAs($this->reporter)->get(route('materials.show', $report))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('Detail Order');
        $this->get(route('materials.template'))->assertOk()->assertSee('45 kolom')->assertSee('LONGITUDE_ONT:');
        $this->get(route('materials.template.download'))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('NO TQ/SC/ID:');
        $other = Branch::create(['code' => 'HISTORY-OTHER', 'name' => 'Other Branch']);
        $foreign = $this->user(Role::BranchAdmin);
        $foreign->branches()->attach($other);
        $this->actingAs($foreign)->get(route('materials.index'))->assertOk()->assertDontSee($report->report_number);
        $this->get(route('materials.show', $report))->assertForbidden();
        $this->get(route('materials.index', ['branch' => $this->branch->getKey()]))->assertForbidden();
        WorkOrder::sole()->update(['id_branches' => $other->getKey()]);
        $this->actingAs($this->reporter)->get(route('materials.show', $report))->assertOk()->assertDontSee('Detail Order');
        $this->actingAs($foreign)->get(route('materials.show', $report))->assertForbidden();
        foreach ([Role::TeamLeader, Role::MaterialAdmin] as $role) {
            $assigned = $this->user($role);
            $assigned->branches()->attach($this->branch);
            $this->actingAs($assigned)->get(route('materials.index'))->assertOk()->assertSee($report->report_number);
        }
        $this->actingAs($this->user(Role::Bot))->get(route('materials.index'))->assertForbidden();
        $this->actingAs($this->user(Role::BranchAdmin, false))->get(route('materials.index'))->assertForbidden();
        $this->actingAs($this->area)->getJson(route('materials.index', ['from' => '2026-10-20', 'to' => '2026-10-19']))->assertUnprocessable();
    }

    public function test_private_chat_identity_scope_and_malformed_or_oversized_payloads(): void
    {
        TelegramChat::create(['telegram_chat_id' => 1001, 'chat_type' => 'private', 'active' => true]);
        $payload = $this->payload();
        $payload['message']['chat'] = ['id' => 1001, 'type' => 'private'];
        $this->assertSame('processed', $this->process($payload)->state);
        $spoof = $this->payload(update: 101, message: 51);
        $spoof['message']['chat'] = ['id' => 1001, 'type' => 'private'];
        $spoof['message']['from']['id'] = 1002;
        TelegramIdentity::create(['id_users' => $this->area->getKey(), 'telegram_user_id' => 1002]);
        $this->assertSame('rejected', $this->process($spoof)->state);
        $this->receive($this->payload(str_repeat('x', 66000), 102))->assertStatus(413);
        $this->assertDatabaseCount('material_reports', 1);
        $this->assertDatabaseCount('telegram_updates', 2);
    }

    public function test_scheduled_and_manual_retries_are_bounded_and_do_not_process_disabled_bot(): void
    {
        $this->process($this->payload());
        $outbox = TelegramOutbox::sole();
        $outbox->update(['state' => 'failed', 'attempts' => 5, 'next_attempt_at' => now()->subMinute()]);
        Queue::fake();
        $this->artisan('olo:telegram-retry')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->artisan('olo:telegram-retry', ['--manual' => true])->assertSuccessful();
        Queue::assertPushed(DeliverTelegramMessage::class, 1);
        $outbox->update(['attempts' => 10]);
        Queue::fake();
        $this->artisan('olo:telegram-retry', ['--manual' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $outbox->update(['attempts' => 0]);
        config(['telegram.enabled' => false]);
        $this->artisan('olo:telegram-retry')->assertSuccessful();
        (new DeliverTelegramMessage($outbox->getKey()))->handle($this->gateway);
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->gateway->messages);
    }

    public function test_binding_command_validates_actor_ids_and_branch_without_changing_user_scope(): void
    {
        $admin = $this->user(Role::SuperAdmin);
        $admin->forceFill(['username' => 'test-superadmin'])->save();
        $this->reporter->forceFill(['username' => 'test-reporter'])->save();
        $options = ['--actor' => $admin->username, '--user' => $this->reporter->username, '--telegram-user' => '1001', '--chat' => '-100100', '--type' => 'supergroup', '--branch' => $this->branch->code];
        $this->artisan('olo:telegram-bind', $options)->assertSuccessful();
        $this->assertSame([$this->branch->getKey()], $this->reporter->branches()->pluck('branches.id_branches')->all());
        $this->assertSame(Role::BranchHd, $this->reporter->fresh()->role);
        $this->artisan('olo:telegram-bind', $options + ['--disable' => true])->assertSuccessful();
        $this->assertFalse(TelegramIdentity::sole()->active);
        $this->assertFalse(TelegramChat::sole()->active);
        $options['--actor'] = $this->reporter->username;
        $this->artisan('olo:telegram-bind', $options)->assertFailed();
        $options['--actor'] = $admin->username;
        $options['--type'] = 'private';
        $this->artisan('olo:telegram-bind', $options)->assertFailed();
        $this->assertFalse(TelegramChat::sole()->active);
        $this->assertSame(2, AuditLog::where('action', 'telegram.identity_bound')->count());
    }

    public function test_webhook_registration_requires_local_configuration_and_only_uses_official_api_with_fakes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => true])->push(['ok' => false], 401)]);
        $admin = $this->user(Role::SuperAdmin);
        $admin->forceFill(['username' => 'webhook-admin'])->save();
        config(['app.url' => 'http://localhost:8000']);
        $this->artisan('olo:telegram-webhook', ['--actor' => $admin->username])->assertFailed();
        Http::assertNothingSent();
        config(['app.url' => 'https://dompis.example.test']);
        $this->artisan('olo:telegram-webhook', ['--actor' => $admin->username])->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === 'https://dompis.example.test/api/telegram/material/webhook' && $request['secret_token'] === str_repeat('s', 40) && $request['drop_pending_updates'] === false && $request['allowed_updates'] === ['message']);
        $this->artisan('olo:telegram-webhook', ['--actor' => $admin->username])->assertFailed();
        $this->assertSame(1, AuditLog::where('action', 'telegram.webhook_registered')->count());
    }
}
