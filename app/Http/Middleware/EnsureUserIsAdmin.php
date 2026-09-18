<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn nhân viên thường vào site quản trị (spec mục 1: hai site tách biệt).
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
