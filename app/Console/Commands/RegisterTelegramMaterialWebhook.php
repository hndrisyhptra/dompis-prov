<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Telegram\HttpTelegramGateway;
use App\Services\Telegram\TelegramException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RegisterTelegramMaterialWebhook extends Command
{
    protected $signature = 'olo:telegram-webhook {--actor= : Username/email superadmin yang mengaktifkan webhook}';

    protected $description = 'Daftarkan webhook HTTPS bot material menggunakan token dan secret dari konfigurasi lokal';

    public function handle(HttpTelegramGateway $gateway): int
    {
        $identity = (string) $this->option('actor');
        $actor = User::where(filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username', $identity)->first();
        $secret = (string) config('telegram.webhook_secret');
        $baseUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($baseUrl);
        if (! $actor?->active || $actor->role !== Role::SuperAdmin || ! config('telegram.enabled') || ! Schema::hasTable('telegram_updates')
            || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret) || ! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['port'] ?? 443, [443, 80, 88, 8443], true)
            || in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $this->error('Webhook belum didaftarkan. Periksa actor superadmin aktif, migration, konfigurasi Telegram dan APP_URL HTTPS publik.');

            return self::FAILURE;
        }
        try {
            $gateway->registerWebhook($baseUrl.'/api/telegram/material/webhook', $secret);
        } catch (TelegramException) {
            $this->error('Pendaftaran webhook belum berhasil. Periksa koneksi dan konfigurasi lokal; token tidak ditampilkan.');

            return self::FAILURE;
        }
        AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'telegram.webhook_registered', 'entity_type' => 'telegram_webhook', 'id_entity' => 0, 'metadata' => ['registered' => true]]);
        $this->info('Telegram menerima pendaftaran webhook. Lanjutkan uji laporan dan balasan end-to-end; keberhasilan pendaftaran belum membuktikan keduanya.');

        return self::SUCCESS;
    }
}
