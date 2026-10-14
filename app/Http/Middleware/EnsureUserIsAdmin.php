<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chỉ cho ADMIN và HR vào site quản trị; nhân viên thường về site học tập.
 * Chuyển hướng về site học tập thay vì trả 403 để tránh ngõ cụt cho người dùng.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasAnyRole(RoleName::adminRoles())) {
            return redirect()->route('learn.events');
        }

        return $next($request);
    }
}
