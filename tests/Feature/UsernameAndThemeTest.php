<?php

namespace Tests\Feature;

use App\Enums\Role;

class UsernameAndThemeTest extends OloTestCase
{
    public function test_active_super_admin_can_login_by_username(): void
    {
        $admin = $this->user(Role::SuperAdmin);
        $admin->forceFill(['username' => 'test-administrator', 'email' => null])->save();
        $this->post('/login', ['login' => 'test-administrator', 'password' => 'password'])->assertRedirect(route('area.dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->get('/sync')->assertOk();
    }

    public function test_email_still_works_in_the_combined_login_field(): void
    {
        $this->post('/login', ['login' => $this->area->email, 'password' => 'password'])->assertRedirect(route('area.dashboard'));
        $this->assertAuthenticatedAs($this->area);
    }

    public function test_wrong_password_and_inactive_username_are_rejected(): void
    {
        $user = $this->user(Role::SuperAdmin);
        $user->forceFill(['username' => 'test-administrator'])->save();
        $this->post('/login', ['login' => $user->username, 'password' => 'incorrect'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $user->forceFill(['active' => false])->save();
        $this->post('/login', ['login' => $user->username, 'password' => 'password'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_theme_icon_exists_only_once_in_authenticated_topbar(): void
    {
        $this->get('/login')->assertOk()->assertSee('Username atau email')->assertDontSee('data-theme-toggle', false);
        $response = $this->actingAs($this->area)->get('/work-orders')->assertOk();
        $response->assertSeeInOrder(['class="topbar"', 'data-theme-toggle', 'theme-moon', 'theme-sun'], false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-theme-toggle'));
        $this->assertStringNotContainsString('data-theme-toggle', explode('<main', $response->getContent())[0]);
    }
}
