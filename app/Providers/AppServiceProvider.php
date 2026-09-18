<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Super Admin bỏ qua mọi kiểm tra quyền (spec mục 5).
         * Trả null thay vì false khi không phải Super Admin để Gate tiếp tục
         * xét các policy/permission còn lại — trả false sẽ chặn nhầm mọi vai trò khác.
         */
        Gate::before(function (User $user) {
            return $user->hasRole(RoleName::ADMIN->value) ? true : null;
        });

        // Phân trang dùng Tailwind để khớp bộ token thay vì CSS mặc định của Bootstrap
        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');
    }
}
