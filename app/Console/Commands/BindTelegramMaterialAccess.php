<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\TelegramChat;
use App\Models\TelegramIdentity;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BindTelegramMaterialAccess extends Command
{
    protected $signature = 'olo:telegram-bind {--actor= : Username/email superadmin} {--user= : Username/email pengguna} {--telegram-user= : ID numerik Telegram pengguna} {--chat= : ID chat numerik} {--type= : private/group/supergroup} {--branch= : Kode Branch untuk chat} {--disable : Cabut binding yang disebut}';

    protected $description = 'Petakan akun/grup Telegram untuk laporan material, tanpa mengubah role/Branch user';

    public function handle(): int
    {
        if (! Schema::hasTable('telegram_identities')) {
            $this->error('Jalankan migration material terlebih dahulu.');

            return self::FAILURE;
        }
        $actor = $this->findUser((string) $this->option('actor'));
        $user = $this->option('user') ? $this->findUser((string) $this->option('user')) : null;
        $userId = $this->option('telegram-user');
        $chatId = $this->option('chat');
        $validator = Validator::make(['telegram_user' => $userId, 'chat' => $chatId, 'type' => $this->option('type')],
            ['telegram_user' => 'nullable|integer|min:1|max:4503599627370495', 'chat' => 'nullable|integer|not_in:0|min:-4503599627370495|max:4503599627370495', 'type' => ['nullable', Rule::in(['private', 'group', 'supergroup'])]]);
        $branch = $this->option('branch') ? Branch::where('code', $this->option('branch'))->where('active', true)->first() : null;
        if (! $actor?->active || $actor->role !== Role::SuperAdmin || $validator->fails() || (! $userId && ! $chatId)
            || ($userId && (! $user?->active || $user->role === Role::Bot)) || ($chatId && (! $this->option('type') || ($this->option('type') !== 'private' && ! $branch)))
            || ($chatId && (($this->option('type') === 'private' && (int) $chatId <= 0) || ($this->option('type') !== 'private' && (int) $chatId >= 0)))
            || ($this->option('branch') && ! $branch)) {
            $this->error('Binding tidak valid. Gunakan actor superadmin aktif, ID numerik, akun aktif dan tipe chat/Branch yang sesuai.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($actor, $user, $userId, $chatId, $branch) {
            if ($userId) {
                $taken = TelegramIdentity::where('telegram_user_id', $userId)->where('id_users', '<>', $user->getKey())->exists();
                if ($taken) {
                    $this->error('ID Telegram tersebut sudah dipetakan ke akun lain.');

                    return self::FAILURE;
                }
                $binding = TelegramIdentity::updateOrCreate(['id_users' => $user->getKey()], ['telegram_user_id' => $userId, 'active' => ! $this->option('disable')]);
                AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'telegram.identity_bound', 'entity_type' => 'telegram_identity', 'id_entity' => $binding->getKey(), 'metadata' => ['id_users' => $user->getKey(), 'active' => $binding->active]]);
            }
            if ($chatId) {
                $binding = TelegramChat::updateOrCreate(['telegram_chat_id' => $chatId], ['chat_type' => $this->option('type'), 'id_branches' => $branch?->getKey(), 'active' => ! $this->option('disable')]);
                AuditLog::create(['id_users' => $actor->getKey(), 'action' => 'telegram.chat_bound', 'entity_type' => 'telegram_chat', 'id_entity' => $binding->getKey(), 'metadata' => ['id_branches' => $branch?->getKey(), 'active' => $binding->active]]);
            }
            $this->info('Binding Telegram disimpan. Role dan penugasan Branch akun tidak diubah.');

            return self::SUCCESS;
        });
    }

    private function findUser(string $identity): ?User
    {
        return $identity === '' ? null : User::where(filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username', $identity)->first();
    }
}
