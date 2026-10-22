<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\RedirectResponse;
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
        // Vai trò quản trị, hoặc được cấp quyền Xem ít nhất một màn quản trị
        if (! $request->user()?->canAccessAdminSite()) {
            // Tự tạo RedirectResponse: trong request Livewire, redirect() trả Redirector
            // của Livewire (không phải Response) và làm vỡ kiểu trả về của middleware
            return new RedirectResponse(route('learn.events'));
        }

        return $next($request);
    }
}
