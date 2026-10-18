<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;

class ActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->active && $request->user()->role !== Role::Bot, 403);

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
