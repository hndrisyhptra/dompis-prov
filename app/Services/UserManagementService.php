<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Region;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function save(User $actor, array $data, ?User $target = null): User
    {
        return DB::transaction(function () use ($actor, $data, $target) {
            // Serialize admin membership changes so simultaneous requests cannot remove all admins.
            $admins = User::where('role', Role::SuperAdmin->value)->orderBy('id_users')->lockForUpdate()->get();
            $operator = $admins->firstWhere('id_users', $actor->getKey()) ?? $actor->fresh();
            Gate::forUser($operator)->authorize('administer');
            $user = $target ? User::lockForUpdate()->findOrFail($target->getKey()) : new User;
            abort_if($user->exists && $user->role === Role::Bot, 403, 'Akun bot dikelola melalui integrasi.');
            abort_if($user->exists && $user->management_revision !== (int) $data['management_revision'], 409, 'Data user sudah berubah. Muat ulang halaman sebelum menyimpan.');
            $role = Role::from($data['role']);
            abort_if($role === Role::Bot, 403);
            $active = (bool) $data['active'];
            if ($user->exists && $user->is($actor) && ($role !== Role::SuperAdmin || ! $active)) {
                throw ValidationException::withMessages(['active' => 'Role dan status aktif akun sendiri tidak dapat diturunkan. Gunakan superadmin lain untuk perubahan tersebut.']);
            }
            if ($user->exists && $user->active && $user->role === Role::SuperAdmin && (! $active || $role !== Role::SuperAdmin) && $admins->where('active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Minimal satu superadmin aktif harus tetap tersedia.']);
            }
            $branchIds = array_map('intval', $data['branches'] ?? []);
            $branches = Branch::whereIn('id_branches', $branchIds)->lockForUpdate()->get();
            $existingBranches = $user->exists ? $user->branches()->get()->modelKeys() : [];
            sort($branchIds);
            sort($existingBranches);
            if ($branches->count() !== count($branchIds) || $branches->contains(fn ($branch) => ! $branch->active && ! in_array($branch->getKey(), $existingBranches, true))) {
                throw ValidationException::withMessages(['branches' => 'Cabang tidak tersedia atau tidak aktif.']);
            }
            if (! $role->areaScope() && ($branches->isEmpty() || ($active && ! $branches->contains('active', true)))) {
                throw ValidationException::withMessages(['branches' => 'Role cabang memerlukan minimal satu cabang aktif untuk akun aktif.']);
            }
            $region = ! empty($data['id_regions']) ? Region::where('is_active', true)->lockForUpdate()->find($data['id_regions']) : null;
            $sto = ! empty($data['id_service_areas']) ? ServiceArea::with(['branch', 'region'])->where('is_active', true)->lockForUpdate()->find($data['id_service_areas']) : null;
            if ((! empty($data['id_regions']) && ! $region) || (! empty($data['id_service_areas']) && (! $sto || ! $sto->branch?->active || ! $sto->region?->is_active || ! $sto->branch->matchesRegion($sto->region)))) {
                throw ValidationException::withMessages(['id_service_areas' => 'Region/STO tidak aktif atau mapping branch dan region belum sesuai.']);
            }
            if ($region && $sto && $region->getKey() !== $sto->id_regions) {
                throw ValidationException::withMessages(['id_service_areas' => 'STO tidak berada pada region yang dipilih.']);
            }
            $region ??= $sto?->region;
            if (! $role->areaScope() && (($sto && ! $branches->contains(fn ($branch) => $branch->getKey() === $sto->id_branches && $branch->active)) || ($region && ! $branches->contains(fn ($branch) => $branch->active && $branch->matchesRegion($region))))) {
                throw ValidationException::withMessages(['id_service_areas' => 'Lokasi utama harus berada dalam cabang aktif yang ditugaskan.']);
            }
            $before = $user->exists ? $this->accessState($user) : null;
            $user->forceFill([
                'name' => $data['name'], 'username' => $data['username'] ?: null, 'email' => $data['email'] ?: null,
                'role' => $role, 'active' => $active, 'id_regions' => $region?->getKey(), 'id_service_areas' => $sto?->getKey(),
                'management_revision' => $user->exists ? $user->management_revision + 1 : 0,
            ]);
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = $data['password'];
            }
            $revokeSessions = $user->exists && ($passwordChanged || $user->isDirty(['role', 'active', 'email', 'username', 'id_regions', 'id_service_areas']) || $branchIds !== $existingBranches);
            if ($passwordChanged || $user->isDirty('email')) {
                DB::table('password_reset_tokens')->whereIn('email', array_filter([$user->getRawOriginal('email'), $user->email]))->delete();
            }
            if ($revokeSessions) {
                $user->remember_token = Str::random(60);
            }
            $created = ! $user->exists;
            $user->save();
            $user->branches()->sync($branchIds);
            if ($revokeSessions && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->getKey())
                    ->when($user->is($actor), fn ($query) => $query->where('id', '!=', session()->getId()))->delete();
            }
            AuditLog::create(['id_users' => $actor->getKey(), 'action' => $created ? 'user.created' : 'user.updated', 'entity_type' => 'user', 'id_entity' => $user->getKey(),
                'metadata' => ['before' => $before, 'after' => $this->accessState($user), 'password_changed' => $passwordChanged, 'changed_fields' => array_values(array_diff(array_keys($user->getChanges()), ['password', 'remember_token']))]]);

            return $user;
        }, 3);
    }

    private function accessState(User $user): array
    {
        return ['role' => $user->role->value, 'active' => $user->active, 'id_regions' => $user->id_regions, 'id_service_areas' => $user->id_service_areas,
            'branches' => $user->branches()->orderBy('branches.id_branches')->pluck('branches.id_branches')->all(), 'management_revision' => $user->management_revision];
    }
}
