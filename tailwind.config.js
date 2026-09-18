import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Bộ token dùng chung cho cả hai site (spec mục 7.2).
 *
 * Màu: một nguồn duy nhất — khi có Brand Guideline (CIP) chính thức của Techcombank
 * chỉ cần sửa ở đây, không phải dò hex rải rác trong Blade.
 *
 * Bo góc: cố tình tách hai thang.
 *   - `admin-*` (2–4px): sắc cạnh, tạo cảm giác chuẩn mực của hệ thống nghiệp vụ ngân hàng.
 *   - `user-*` (12–20px + pill): mềm mại, thân thiện cho trải nghiệm học tập.
 * Nhờ vậy hai site có "chất" hình khối khác nhau mà vẫn chung một bộ nhận diện.
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
                // Site Quản trị — chắc chắn, "có lực"
                'admin-sm': '2px',
                admin: '4px',
                'admin-lg': '6px',
                // Site Người dùng — mềm mại, dễ tiếp cận
                'user-md': '12px',
                'user-lg': '20px',
                'user-pill': '999px',
            },
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                // Admin dựa vào viền để phân định vùng dữ liệu, shadow chỉ dùng rất nhẹ
                admin: '0 1px 2px rgba(10, 12, 18, .06)',
                // User dùng shadow lan toả mềm thay cho viền cứng
                user: '0 18px 42px rgba(10, 12, 18, .10)',
                'user-sm': '0 8px 18px rgba(10, 12, 18, .05)',
            },
        },
    },
    plugins: [],
};
