<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Keep the existing email request contract working while the form accepts either identity.
        $request->merge(['login' => $request->input('login', $request->input('email'))]);
        $data = $request->validate(['login' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $identity = trim($data['login']);
        $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $errorField = $request->has('email') ? 'email' : 'login';
        $key = 'login:'.hash('sha256', Str::lower($identity).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([$errorField => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        if (! Auth::attempt([$field => $identity, 'password' => $data['password'], 'active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([$errorField => 'Username/email atau kata sandi tidak sesuai, atau akun tidak aktif.']);
        }
        if ($request->user()->role === Role::Bot) {
            Auth::logout();
            throw ValidationException::withMessages([$errorField => 'Identitas bot tidak dapat login melalui web.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        AuditLog::create(['id_users' => $request->user()->getKey(), 'action' => 'auth.login', 'entity_type' => 'user', 'id_entity' => $request->user()->getKey()]);

        return redirect()->intended(route($request->user()->role->areaScope() ? 'area.dashboard' : 'branch.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
