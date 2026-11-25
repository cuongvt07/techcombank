import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Bộ token dùng chung cho cả hai site (spec mục 7.2).
 *
 * Màu: một nguồn duy nhất — khi có Brand Guideline (CIP) chính thức của Techcombank
 * chỉ cần sửa ở đây, không phải dò hex rải rác trong Blade.
 *
 * Bo góc (fb7 mục 11 — thống nhất toàn hệ thống, bỏ góc vuông):
 *   card 12px · nút / input / select 9px · chip, pill 20px+ · thanh bar 5px.
 *   - `admin-sm` 4px: checkbox, ô nhỏ trong danh sách.
 *   - `admin` 9px: nút, ô nhập, nút icon.
 *   - `admin-lg` 12px: card / panel.
 *   - `user-*` (12–20px + pill): site người dùng, đã khớp thang trên.
 *
 * Cỡ chữ (fb7 mục 2): font Be Vietnam Pro, line-height 1.5 cho các cỡ chữ thân
 * bài — chữ tiếng Việt có dấu chồng (Ẩ, Ỗ...) cần khoảng thở giữa các dòng,
 * line-height mặc định của Tailwind (1.33 cho text-xs) làm dấu chạm dòng trên.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    red: {
                        // Đỏ đậm thay cho #e60012 tươi: mảng lớn ở site người
                        // dùng (hero, thẻ khóa học, nút) nhìn lâu bị chói mắt.
                        // Vẫn cùng họ đỏ thương hiệu, chỉ hạ độ sáng.
                        DEFAULT: '#c00016',
                        dark: '#8f0010',
                        tint: '#fdf2f3',
                    },
                    ink: '#141821',
                    black: '#05070a',
                    muted: '#6b7280',
                    line: '#e4e7ee',
                    soft: '#f4f6f9',
                    // Nền trang (fb7 mục 11): xám rất nhạt để card trắng tách khỏi nền
                    page: '#f6f7f9',
                },
                // Màu trạng thái: giữ tối thiểu, chỉ dùng cho tín hiệu nghiệp vụ
                // (đạt / cảnh báo hạn), không dùng để trang trí.
                state: {
                    success: '#17a34a',
                    'success-tint': '#ecfdf3',
                    warning: '#d97706',
                    'warning-tint': '#fff7ed',
                },
            },
            borderRadius: {
                // Site Quản trị — bo tròn thống nhất (fb7 mục 11)
                'admin-sm': '4px',
                admin: '9px',
                'admin-lg': '12px',
                bar: '5px',
                // Các cỡ mặc định còn rải trong view (rounded-sm / rounded / rounded-md)
                // nhích lên theo cùng thang để không còn góc gần như vuông.
                sm: '4px',
                DEFAULT: '6px',
                md: '9px',
                lg: '12px',
                // Site Người dùng — mềm mại, dễ tiếp cận
                'user-md': '12px',
                'user-lg': '20px',
                'user-pill': '999px',
            },
            fontFamily: {
                // Tự host qua @fontsource/be-vietnam-pro (import ở app.css), không gọi CDN ngoài
                sans: ['"Be Vietnam Pro"', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            // Nhích cỡ chữ thân bài lên nửa px và đặt line-height 1.5 (fb7 mục 2:
            // "cỡ chữ cần to hơn"). Sửa ở thang chung để mọi màn hình cùng hưởng,
            // không phải dò text-xs / text-sm trong từng view.
            fontSize: {
                xs: ['12.5px', { lineHeight: '1.5' }],
                sm: ['14.5px', { lineHeight: '1.5' }],
                base: ['16px', { lineHeight: '1.5' }],
                lg: ['18px', { lineHeight: '1.5' }],
                xl: ['20px', { lineHeight: '1.4' }],
                '2xl': ['26px', { lineHeight: '1.3' }],
            },
            boxShadow: {
                // fb7 mục 11: card / panel phẳng, chỉ dùng viền 1px — không shadow.
                // `admin`, `user` giữ lại cho lớp nổi (dropdown, popover, modal) cần tách
                // khỏi nội dung bên dưới. `user-sm` vốn chỉ dùng cho card nên tắt hẳn
                // tại token — mọi card đang gắn shadow-user-sm phẳng theo, không phải sửa view.
                admin: '0 1px 2px rgba(10, 12, 18, .06)',
                user: '0 18px 42px rgba(10, 12, 18, .10)',
                'user-sm': 'none',
            },
        },
    },
    plugins: [],
};
