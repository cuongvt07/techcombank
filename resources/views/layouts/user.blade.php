<!doctype html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- theme-color đỏ thương hiệu: khi "Thêm vào màn hình chính" app chạy full-screen (spec 6.5) --}}
    <meta name="theme-color" content="#c00016">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>@yield('title', 'Học tập') — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('brand/logo-mark.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-white font-sans text-brand-ink antialiased">
    <div class="flex min-h-full flex-col lg:flex-row">
        {{-- Desktop: sidebar cố định. Mobile: ẩn, thay bằng bottom tab bar. --}}
        <div class="hidden lg:block">
            @include('layouts.partials.user-sidebar')
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            @include('layouts.partials.user-header')

            {{-- pb-24 trên mobile để nội dung cuối trang không bị bottom tab bar che --}}
            <main class="flex-1 px-4 pb-24 pt-4 sm:px-6 lg:px-8 lg:pb-10">
                @if (session('status'))
                    <div class="mb-4 rounded-user-md border border-state-success bg-state-success-tint px-4 py-3 text-sm font-semibold text-state-success">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
                {{ $slot ?? '' }}
            </main>
        </div>
    </div>

    @include('layouts.partials.user-bottom-nav')

    {{-- Trợ lý AI: có mặt ở mọi trang site người dùng (spec 4.3) --}}
    @livewire('user.ai-chat')

    @livewireScripts
</body>
</html>
