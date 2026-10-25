<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TelegramMaterialWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $secret = (string) config('telegram.webhook_secret');
        abort_unless(config('telegram.enabled') && preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret) && Schema::hasTable('telegram_updates'), 503, 'Telegram material belum diaktifkan.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        abort_unless($request->isJson(), 415);
        $data = $request->validate(['update_id' => 'required|integer|min:0|max:4503599627370495']);
        $inbox = TelegramUpdate::firstOrCreate(['telegram_update_id' => $data['update_id']], ['payload' => $request->json()->all(), 'state' => 'pending']);
        if (in_array($inbox->state, ['pending', 'failed'], true)) {
            ProcessTelegramUpdate::dispatch($inbox->getKey())->afterCommit();
        }

        return response()->json(['ok' => true]);
    }
}
