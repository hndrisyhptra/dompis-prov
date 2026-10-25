<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserManagementTest extends OloTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user(Role::SuperAdmin);
        $this->branch->update(['region_id' => 1, 'regional' => 'REGION JATIM']);
        ServiceArea::where('workzone', 'DMO')->update(['id_branches' => $this->branch->getKey()]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['name' => 'Operator Uji', 'username' => 'operator.one', 'email' => 'operator@example.test',
            'password' => 'ValidPassword123', 'password_confirmation' => 'ValidPassword123', 'role' => 'branch_admin', 'active' => 1,
            'branches' => [$this->branch->getKey()], 'id_regions' => 1, 'id_service_areas' => 1], $changes);
    }

    private function createManagedUser(): User
    {
        $this->actingAs($this->admin)->post(route('users.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        return User::where('username', 'operator.one')->firstOrFail();
    }

    public function test_password_only_requires_eight_characters_for_create_and_update(): void
    {
        $this->actingAs($this->admin);
        foreach (['abcdefgh', '12345678', 'ABCDEFGH'] as $i => $password) {
            $this->post(route('users.store'), $this->payload(['username' => 'simple.'.$i, 'email' => 'simple'.$i.'@example.test', 'password' => $password, 'password_confirmation' => $password]))->assertSessionHasNoErrors()->assertRedirect();
            $this->assertTrue(Hash::check($password, User::where('username', 'simple.'.$i)->sole()->password));
        }
        $user = User::where('username', 'simple.0')->sole();
        $this->put(route('users.update', $user), $this->payload(['username' => $user->username, 'email' => $user->email, 'password' => '87654321', 'password_confirmation' => '87654321', 'management_revision' => 0]))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('87654321', $user->fresh()->password));
        $this->post(route('users.store'), $this->payload(['password' => '1234567', 'password_confirmation' => '1234567']))->assertSessionHasErrors('password');
        $this->put(route('users.update', $user), $this->payload(['username' => $user->username, 'email' => $user->email, 'password' => 'abcdefg', 'password_confirmation' => 'abcdefg', 'management_revision' => 1]))->assertSessionHasErrors('password');
    }

    public function test_only_active_superadmin_can_access_all_management_routes_and_navigation(): void
    {
        $this->get(route('users.index'))->assertRedirect('/login');
        foreach (Role::cases() as $role) {
            if ($role === Role::SuperAdmin) {
                continue;
            }
            $this->actingAs($this->user($role));
            $this->get(route('users.index'))->assertForbidden();
            $this->get(route('users.create'))->assertForbidden();
            $this->get(route('users.edit', $this->admin))->assertForbidden();
            $this->post(route('users.store'), $this->payload())->assertForbidden();
            $this->put(route('users.update', $this->admin), $this->payload())->assertForbidden();
        }
        $this->actingAs($this->user(Role::SuperAdmin, false))->get(route('users.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->assertSee('User Management')->assertSee('Tambah user');
        $this->get(route('users.create'))->assertOk()->assertSee('data-user-form', false)->assertDontSee('value="bot"', false);
        $this->get(route('users.edit', $this->admin))->assertOk()->assertSee('akun sendiri');
    }

    public function test_create_user_hashes_password_saves_scope_and_location_and_user_can_login(): void
    {
        $payload = $this->payload(['username' => ' OPERATOR.ONE ', 'email' => ' OPERATOR@EXAMPLE.TEST ', 'id_regions' => null, 'id_users' => 99999, 'remember_token' => 'injected']);
        $this->actingAs($this->admin)->post(route('users.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('username', 'operator.one')->firstOrFail();
        $this->assertTrue(Hash::check('ValidPassword123', $user->password));
        $this->assertSame('operator@example.test', $user->email);
        $this->assertSame(Role::BranchAdmin, $user->role);
        $this->assertSame([$this->branch->getKey()], $user->branches->modelKeys());
        $this->assertSame(1, $user->id_regions);
        $this->assertSame('DMO', $user->serviceArea->workzone);
        $this->assertSame(0, $user->management_revision);
        $this->assertNotSame(99999, $user->getKey());
        $this->assertNotSame('injected', $user->remember_token);
        $log = AuditLog::where('action', 'user.created')->sole();
        $this->assertSame($this->admin->getKey(), $log->id_users);
        $this->assertStringNotContainsString('ValidPassword123', json_encode($log->metadata));
        $this->assertStringNotContainsString($user->password, json_encode($log->metadata));
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['login' => 'operator.one', 'password' => 'ValidPassword123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_edit_preserves_blank_password_updates_scope_and_rejects_stale_submission(): void
    {
        $user = $this->createManagedUser();
        $hash = $user->password;
        $other = Branch::create(['code' => 'NEXT', 'name' => 'Next Branch', 'region_id' => 3, 'regional' => 'REGION BALNUS']);
        ServiceArea::where('workzone', 'UBN')->update(['id_branches' => $other->getKey()]);
        $data = $this->payload(['name' => 'Operator Diubah', 'password' => '', 'password_confirmation' => '', 'management_revision' => 0,
            'branches' => [$other->getKey()], 'id_regions' => 3, 'id_service_areas' => 2, 'role' => 'team_leader']);
        $this->put(route('users.update', $user), $data)->assertSessionHasNoErrors()->assertRedirect();
        $user->refresh();
        $this->assertSame($hash, $user->password);
        $this->assertSame('Operator Diubah', $user->name);
        $this->assertSame(Role::TeamLeader, $user->role);
        $this->assertSame([$other->getKey()], $user->branches->modelKeys());
        $this->assertSame(3, $user->region->getKey());
        $this->assertSame('UBN', $user->serviceArea->workzone);
        $this->assertSame(1, $user->management_revision);
        $this->put(route('users.update', $user), $data)->assertConflict()->assertSee('Muat ulang data user');
        $this->assertSame(1, AuditLog::where('action', 'user.updated')->count());
    }

    public function test_identity_password_role_branch_and_location_validation_prevents_partial_writes(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('users.store'), $this->payload(['username' => '', 'email' => '']))->assertSessionHasErrors(['username', 'email']);
        $this->post(route('users.store'), $this->payload(['username' => 'invalid@identity', 'password' => 'weak', 'password_confirmation' => 'weak']))->assertSessionHasErrors(['username', 'password']);
        $this->post(route('users.store'), $this->payload(['role' => 'bot']))->assertSessionHasErrors('role');
        $this->post(route('users.store'), $this->payload(['branches' => [], 'id_regions' => null, 'id_service_areas' => null]))->assertSessionHasErrors('branches');
        $this->post(route('users.store'), $this->payload(['branches' => [99999]]))->assertSessionHasErrors('branches.0');
        $this->post(route('users.store'), $this->payload(['id_regions' => 3]))->assertSessionHasErrors('id_service_areas');
        $this->post(route('users.store'), $this->payload(['id_service_areas' => 99999]))->assertSessionHasErrors('id_service_areas');
        $this->post(route('users.store'), $this->payload(['id_service_areas' => 2, 'id_regions' => 3]))->assertSessionHasErrors('id_service_areas');
        $this->assertSame(0, User::where('username', 'operator.one')->count());
        $this->assertSame(0, AuditLog::where('action', 'user.created')->count());
        $user = $this->createManagedUser();
        $this->post(route('users.store'), $this->payload())->assertSessionHasErrors(['username', 'email']);
        $this->branch->update(['active' => false]);
        $this->put(route('users.update', $user), $this->payload(['management_revision' => 0]))->assertSessionHasErrors('branches');
        $this->assertSame(0, $user->fresh()->management_revision);
        $this->assertFalse(session()->hasOldInput('password'));
    }

    public function test_own_admin_cannot_be_disabled_or_demoted_and_bot_accounts_are_excluded(): void
    {
        $data = $this->payload(['username' => 'admin', 'email' => $this->admin->email, 'role' => 'super_admin', 'branches' => [], 'id_regions' => null, 'id_service_areas' => null,
            'password' => '', 'password_confirmation' => '', 'management_revision' => 0]);
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), array_replace($data, ['active' => 0]))->assertSessionHasErrors('active');
        $this->put(route('users.update', $this->admin), array_replace($data, ['role' => 'area_admin']))->assertSessionHasErrors('active');
        $this->assertTrue($this->admin->fresh()->active);
        $this->assertSame(Role::SuperAdmin, $this->admin->fresh()->role);
        $bot = $this->user(Role::Bot);
        $this->get(route('users.edit', $bot))->assertForbidden();
        $this->put(route('users.update', $bot), $data)->assertForbidden();
        $this->get(route('users.index'))->assertOk()->assertDontSee($bot->email);
    }

    public function test_password_and_access_changes_revoke_sessions_and_remember_token(): void
    {
        $user = $this->createManagedUser();
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        DB::table('sessions')->insert(['id' => 'managed-session', 'user_id' => $user->getKey(), 'payload' => '', 'last_activity' => time()]);
        $data = $this->payload(['password' => 'ChangedPassword456', 'password_confirmation' => 'ChangedPassword456', 'management_revision' => 0, 'active' => 0]);
        $this->put(route('users.update', $user), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check('ChangedPassword456', $user->fresh()->password));
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'managed-session']);
        $this->assertFalse($user->fresh()->active);
        $this->post('/logout');
        $this->post('/login', ['login' => $user->username, 'password' => 'ChangedPassword456'])->assertSessionHasErrors('login');
    }

    public function test_search_filters_pagination_empty_state_and_edit_error_state(): void
    {
        $user = $this->createManagedUser();
        User::factory()->count(16)->create();
        $this->get(route('users.index'))->assertOk()->assertSee('Next');
        $this->get(route('users.index', ['q' => 'operator.one', 'role' => 'branch_admin', 'active' => '1']))->assertOk()->assertSee('Operator Uji')->assertDontSee($this->admin->email);
        $this->get(route('users.index', ['q' => 'NO-MATCH']))->assertOk()->assertSee('Tidak ada pengguna yang sesuai');
        $this->put(route('users.update', $user), $this->payload(['management_revision' => 0, 'branches' => []]))->assertSessionHasErrors('branches');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('users.edit', $user))->assertOk()->assertSee('Periksa kembali input.')->assertSee('Operator Uji');
    }

    public function test_second_superadmin_can_change_another_admin_but_cannot_remove_their_own_admin_access(): void
    {
        $second = $this->user(Role::SuperAdmin);
        $this->actingAs($second)->put(route('users.update', $this->admin), $this->payload([
            'username' => 'former.admin', 'email' => $this->admin->email, 'role' => 'area_admin', 'active' => 0, 'branches' => [],
            'password' => '', 'password_confirmation' => '', 'id_regions' => null, 'id_service_areas' => null, 'management_revision' => 0,
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($this->admin->fresh()->active);
        $this->assertSame(1, User::where('role', 'super_admin')->where('active', true)->count());
        $this->put(route('users.update', $second), $this->payload([
            'email' => $second->email, 'role' => 'area_hd', 'management_revision' => 0,
        ]))->assertSessionHasErrors('active');
        $this->assertSame(Role::SuperAdmin, $second->fresh()->role);
    }

    public function test_audit_failure_rolls_back_user_and_branch_assignment(): void
    {
        $this->withoutExceptionHandling();
        AuditLog::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->actingAs($this->admin)->post(route('users.store'), $this->payload());
            $this->fail('Audit failure should stop the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['username' => 'operator.one']);
        $this->assertDatabaseCount('user_branches', 0);
    }

    public function test_new_branch_form_uses_structured_region_and_primary_region_conflicts_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('branches.store'), ['code' => 'STRUCT', 'name' => 'Structured Branch', 'region_id' => 2])->assertSessionHasNoErrors()->assertRedirect();
        $branch = Branch::where('code', 'STRUCT')->firstOrFail();
        $this->assertSame(2, $branch->region_id);
        $this->assertSame('REGION JATENG DIY', $branch->regional);
        $this->branch->update(['region_id' => 3]);
        $this->post(route('users.store'), $this->payload())->assertSessionHasErrors('id_service_areas');
        $this->artisan('olo:sto-map', ['workzone' => 'DMO', 'branch' => $this->branch->code])->assertFailed();
        $this->assertSame(1, $this->preview([$this->row(changes: [6 => 'DMO'])])->stats['conflict']);
    }

    public function test_email_changes_reset_verification_and_password_change_revokes_reset_tokens(): void
    {
        $user = $this->createManagedUser();
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic-reset-token', 'created_at' => now()]);
        $this->put(route('users.update', $user), $this->payload(['email' => 'new-email@example.test', 'management_revision' => 0]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertStringNotContainsString('synthetic-reset-token', json_encode(AuditLog::where('action', 'user.updated')->firstOrFail()->metadata));
    }
}
