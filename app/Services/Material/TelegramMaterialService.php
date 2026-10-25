<?php

namespace App\Services\Material;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MaterialReport;
use App\Models\TelegramChat;
use App\Models\TelegramIdentity;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\WorkOrder;
use App\Support\MaterialReportFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramMaterialService
{
    public function process(int $id): ?int
    {
        return DB::transaction(function () use ($id) {
            $inbox = TelegramUpdate::lockForUpdate()->findOrFail($id);
            if (! in_array($inbox->state, ['pending', 'failed'], true)) {
                return TelegramOutbox::where('id_telegram_updates', $id)->value('id_telegram_outbox');
            }
            $payload = $inbox->payload;
            $message = $payload['message'] ?? null;
            if (! is_array($message) || ! is_string($message['text'] ?? null) || ! is_array($message['from'] ?? null) || ! is_array($message['chat'] ?? null) || ! isset($message['from']['id'], $message['chat']['id'], $message['message_id'])
                || ($message['from']['is_bot'] ?? true) !== false || isset($message['sender_chat']) || isset($message['forward_origin']) || isset($message['forward_from'])) {
                $inbox->update(['state' => 'ignored', 'error_code' => 'unsupported_message', 'processed_at' => now()]);

                return null;
            }
            $chatId = (string) $message['chat']['id'];
            $sender = (string) $message['from']['id'];
            $text = trim($message['text']);
            if (! $this->validId($chatId, true) || ! $this->validId($sender) || ! $this->validId((string) $message['message_id']) || ! in_array($message['chat']['type'] ?? '', ['private', 'group', 'supergroup'], true)) {
                $inbox->update(['state' => 'rejected', 'error_code' => 'invalid_envelope', 'processed_at' => now()]);

                return null;
            }
            // /id contains only identifiers of the requester and is available before administrator onboarding.
            if (preg_match('/^\/id(?:@[a-zA-Z0-9_]+)?$/D', $text)) {
                return $this->finish($inbox, $chatId, "ID Telegram Anda: {$sender}\nID chat: {$chatId}\nKirim ID ini ke administrator untuk pemetaan akun/Branch.");
            }
            $identity = TelegramIdentity::with('user')->where('telegram_user_id', $sender)->where('active', true)->first();
            $chat = TelegramChat::with('branch')->where('telegram_chat_id', $chatId)->where('active', true)->first();
            $user = $identity?->user;
            if (! $user?->active || $user->role === Role::Bot || ! $chat || $chat->chat_type !== $message['chat']['type']
                || ($chat->chat_type === 'private' && $chatId !== $sender) || ($chat->chat_type !== 'private' && ! $chat->id_branches)
                || ($chat->id_branches && ! $chat->branch?->active)) {
                return $this->finish($inbox, $chatId, 'Akun atau chat belum mendapat akses laporan. Hubungi administrator; gunakan /id untuk melihat ID Anda.', 'rejected', 'access_denied');
            }
            if (preg_match('/^\/(start|help|material)(?:@[a-zA-Z0-9_]+)?$/D', $text)) {
                return $this->finish($inbox, $chatId, MaterialReportFields::template()."\n\n");
            }
            try {
                $parsed = app(MaterialReportParser::class)->parse($text);
                $fields = $parsed['fields'];
                $order = WorkOrder::visibleTo($user)->where('source_system', 'BIMA')->where('no_tq_sc_id', $fields['no_tq_sc_id'])->lockForUpdate()->first();
                if (! $order || $order->no_tq_sc_id !== $fields['no_tq_sc_id'] || ! $order->id_branches || ($chat->id_branches && (int) $chat->id_branches !== (int) $order->id_branches)
                    || ! $order->assignedBranch?->active || $order->assignedBranch->trashed()) {
                    throw new InvalidArgumentException('Order tidak ditemukan atau di luar cakupan akun/chat. Gunakan NO TQ/SC/ID persis, bukan SID.');
                }
                $duplicate = MaterialReport::where('telegram_chat_id', $chatId)->where('telegram_message_id', $message['message_id'])->first();
                if ($duplicate) {
                    return $this->finish($inbox, $chatId, 'Pesan ini sudah tercatat sebagai laporan '.$duplicate->report_number.'. Tidak digandakan.');
                }
                $previous = null;
                if ($parsed['correction']) {
                    $previous = MaterialReport::visibleTo($user)->where('report_number', $parsed['correction'])->lockForUpdate()->first();
                    if (! $previous || $previous->state !== 'submitted' || $previous->id_work_orders !== $order->getKey() || $previous->id_branches !== $order->id_branches) {
                        throw new InvalidArgumentException('Laporan koreksi tidak tersedia, berbeda order/Branch, atau sudah dikoreksi. Gunakan nomor versi terakhir dan template lengkap.');
                    }
                }
                $report = MaterialReport::create($fields + ['report_number' => (string) Str::ulid(), 'id_work_orders' => $order->getKey(), 'id_branches' => $order->id_branches,
                    'id_users' => $user->getKey(), 'id_telegram_updates' => $inbox->getKey(), 'telegram_chat_id' => $chatId, 'telegram_message_id' => $message['message_id'],
                    'telegram_user_id' => $sender, 'state' => 'submitted', 'id_supersedes_material_reports' => $previous?->getKey()]);
                $previous?->update(['state' => 'superseded']);
                AuditLog::create(['id_users' => $user->getKey(), 'action' => $previous ? 'material.corrected' : 'material.submitted', 'entity_type' => 'material_report', 'id_entity' => $report->getKey(),
                    'metadata' => ['id_work_orders' => $order->getKey(), 'id_branches' => $order->id_branches, 'supersedes' => $previous?->getKey(), 'telegram_update_id' => $inbox->telegram_update_id]]);

                return $this->finish($inbox, $chatId, 'Laporan material tersimpan di Dompis Prov.'."\nNomor laporan: ".$report->report_number."\nData kosong/- tetap belum diisi. Koreksi: kirim /koreksi ".$report->report_number.' diikuti template lengkap pada baris berikutnya.');
            } catch (InvalidArgumentException $exception) {
                return $this->finish($inbox, $chatId, 'Laporan belum disimpan. '.$exception->getMessage(), 'rejected', 'validation_failed');
            }
        });
    }

    private function finish(TelegramUpdate $inbox, string $chatId, string $reply, string $state = 'processed', ?string $error = null): int
    {
        $outbox = TelegramOutbox::firstOrCreate(['id_telegram_updates' => $inbox->getKey()], ['telegram_chat_id' => $chatId, 'payload' => ['text' => $reply], 'state' => 'pending']);
        $inbox->update(['state' => $state, 'error_code' => $error, 'processed_at' => now()]);

        return $outbox->getKey();
    }

    private function validId(string $value, bool $signed = false): bool
    {
        return (bool) preg_match($signed ? '/^-?[1-9]\d{0,15}$/D' : '/^[1-9]\d{0,15}$/D', $value) && abs((int) $value) <= 4503599627370495;
    }
}
