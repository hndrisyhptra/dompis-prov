<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateOloUser extends Command
{
    protected $signature = 'olo:user {email} {--name=} {--role=super_admin} {--branch=*}';

    protected $description = 'Provision an OLO user; password is entered securely, never passed as a CLI argument';

    public function handle(): int
    {
        $role = Role::tryFrom($this->option('role'));
        if (! $role || $role === Role::Bot) {
            $this->error('Role web tidak valid.');

            return self::FAILURE;
        }
        $codes = array_unique($this->option('branch'));
        $branches = Branch::whereIn('code', $codes)->where('active', true)->get();
        if ($branches->count() !== count($codes) || (! $role->areaScope() && $branches->isEmpty())) {
            $this->error('Role branch memerlukan minimal satu --branch=KODE yang aktif.');

            return self::FAILURE;
        }
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name') ?: $this->ask('Nama'), 'password' => $this->secret('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka)')];
        $validation = Validator::make($data, ['email' => 'required|email|max:255|unique:users', 'name' => 'required|string|max:255', 'password' => ['required', 'string', 'max:255', Password::min(12)->mixedCase()->numbers()]]);
        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $role, $branches) {
            $user = new User($data);
            $user->role = $role;
            $user->active = true;
            $user->save();
            $user->branches()->sync($branches->modelKeys());
            AuditLog::create(['action' => 'user.provisioned_cli', 'entity_type' => 'user', 'id_entity' => $user->getKey(), 'metadata' => ['role' => $role->value, 'branches' => $branches->modelKeys()]]);
        });
        $this->info('User aktif dibuat. Tidak ada kata sandi default.');

        return self::SUCCESS;
    }
}
