<!doctype html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Đăng nhập') — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('brand/logo-mark.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{--
    Nền dùng hoạ tiết hình thoi chính thức của Techcombank (public/brand/pattern.svg).
    Hoạ tiết dồn về bên phải nên đặt background-position: right center; trên màn
    hẹp phần đó bị cắt bớt, khung đăng nhập vẫn nằm giữa và đọc được bình thường.
--}}
<body class="relative grid h-full place-items-center bg-brand-soft px-4 font-sans text-brand-ink antialiased">
    <div class="pointer-events-none absolute inset-0 bg-cover bg-right bg-no-repeat"
         style="background-image: url('{{ asset('brand/pattern.svg') }}')"
         aria-hidden="true"></div>

    <main class="relative w-full max-w-[400px] py-10">
        {{ $slot ?? '' }}
        @yield('content')
    </main>
    @livewireScripts
</body>
</html>
