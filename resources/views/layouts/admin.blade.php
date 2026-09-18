<!doctype html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quản trị') — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('brand/logo-mark.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-brand-soft font-sans text-brand-ink antialiased">
    {{-- Layout cố định sidebar trái + vùng nội dung: cấu trúc quen thuộc để
         quản trị viên đoán được vị trí chức năng mà không phải học lại (spec 6.4). --}}
    <div class="flex min-h-full">
        @include('layouts.partials.admin-sidebar')

        <div class="flex min-w-0 flex-1 flex-col">
            @include('layouts.partials.admin-topbar')

            <main class="flex-1 p-5">
                @if (session('status'))
                    <div class="mb-4 rounded-admin border border-state-success bg-state-success-tint px-4 py-3 text-sm font-semibold text-state-success">
                        {{ session('status') }}
                    </div>
                @endif

                @if (session('error'))
                    <div class="mb-4 rounded-admin border border-brand-red bg-brand-red-tint px-4 py-3 text-sm font-semibold text-brand-red">
                        {{ session('error') }}
                    </div>
                @endif

                @yield('content')
                {{ $slot ?? '' }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
