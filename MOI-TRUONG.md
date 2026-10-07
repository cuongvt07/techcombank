# Môi trường chạy dự án

## Phiên bản đang dùng

| Thành phần | Phiên bản | Ghi chú |
|---|---|---|
| PHP | **8.2.27** | Bản của Laragon, không phải PHP mặc định |
| Laravel | 11.56.0 | |
| Livewire | 3.x | |
| MySQL | 8.0.30 | |
| Tailwind CSS | 3.4.x | |
| Vite | 6.x | |

## Dùng đúng bản PHP

PHP mặc định trên máy này là **7.2.34** — không chạy được Laravel 11.
Mọi lệnh `artisan` và `composer` phải gọi bản 8.2 của Laragon:

```bash
/c/laragon/bin/php/php-8.2.27-Win32-vs16-x64/php.exe artisan ...
```

Đặt biến cho gọn:

```bash
PHP=/c/laragon/bin/php/php-8.2.27-Win32-vs16-x64/php.exe
$PHP artisan serve --host=127.0.0.1 --port=8123
```

Composer cũng vậy — gọi qua PHP 8.2:

```bash
$PHP /c/laragon/bin/composer/composer.phar install
```

Quên bước này sẽ gặp lỗi khó hiểu kiểu "syntax error, unexpected '?'"
vì PHP 7.2 không đọc được cú pháp mới.

## Cổng chạy

Dùng **8123**, không dùng 8000 — Laragon đã chiếm cổng 8000 và sẽ trả 404
cho mọi đường dẫn của dự án.

```bash
$PHP artisan serve --host=127.0.0.1 --port=8123
```

## Extension PHP bắt buộc

Tất cả đã bật trên máy này:

| Extension | Dùng cho |
|---|---|
| `zip` | Nhập câu hỏi từ Excel. **Tắt là 12 test chết** |
| `gd` | Xử lý ảnh đại diện |
| `mbstring` | Chuỗi tiếng Việt |
| `pdo_mysql` | Kết nối CSDL |
| `fileinfo` | Kiểm tra loại file upload |
| `openssl` | Mã hóa, gọi HTTPS tới Groq |

Bật thêm extension: sửa `php.ini` trong thư mục PHP của Laragon, bỏ dấu `;`
ở đầu dòng `;extension=<tên>`.

## Node và npm

### Bản cần cài

| | Bản tối thiểu | Nên dùng |
|---|---|---|
| Node.js | **18.x** | **20.x LTS** hoặc 22.x LTS |
| npm | 9.x | đi kèm Node |

Vite 6 yêu cầu Node 18 trở lên. Node 16 hoặc thấp hơn sẽ báo lỗi
`Unsupported engine` và build hỏng.

### Cài Node trên Windows

Tải bản **LTS** tại https://nodejs.org — chọn Windows Installer (.msi) 64-bit.
Trình cài đặt tự thêm Node và npm vào PATH.

Cài xong mở terminal **mới** rồi kiểm tra:

```bash
node -v    # phải là v20.x hoặc v22.x
npm -v
```

Không thấy lệnh: khởi động lại terminal, hoặc đăng xuất Windows rồi vào lại
để PATH cập nhật.

### Tình trạng trên máy này

`node_modules` **đã cài sẵn 128 gói**, nên `npm run build` chạy được từ trước.
Nhưng `npm` hiện **không có trên PATH của Git Bash** — nếu Node đã cài mà Git
Bash không thấy, gọi thẳng đường dẫn:

```bash
"/c/Program Files/nodejs/npm.cmd" run build
```

Hoặc dùng PowerShell / CMD thay cho Git Bash — hai shell này thường đọc PATH
hệ thống đầy đủ hơn.

### Khi nào cần Node

| Việc | Cần Node? |
|---|---|
| Chạy dự án, xem giao diện | **Không** — bản build sẵn trong `public/build/` |
| Sửa file trong `resources/css` hoặc `resources/js` | **Có** |
| Sửa `tailwind.config.js` (màu, bo góc) | **Có** |
| Sửa file `.blade.php` có class Tailwind mới chưa từng dùng | **Có** |
| Sửa PHP, route, CSDL | **Không** |

Điểm cuối dễ nhầm: Tailwind chỉ sinh CSS cho class thực sự xuất hiện trong mã.
Thêm class mới vào Blade mà không build lại thì class đó không có tác dụng.

### Lệnh

```bash
npm install       # cài lại thư viện (khi clone mới hoặc xóa node_modules)
npm run build     # build cho production, ghi vào public/build/
npm run dev       # chế độ phát triển, tự cập nhật khi sửa file
```

`npm run dev` giữ terminal chạy liên tục và cần `php artisan serve` chạy song
song ở terminal khác.

## Cơ sở dữ liệu

Hai CSDL riêng, bản `_test` bị xóa sạch mỗi lần chạy test:

```sql
CREATE DATABASE techcombank_lms      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE techcombank_lms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Cấu hình CSDL test nằm trong `phpunit.xml`, không phải `.env`.

## Múi giờ

Ứng dụng chạy **UTC**, MySQL của máy này trả giờ **UTC+7** — lệch 7 tiếng.

Khi viết truy vấn so sánh thời gian, **truyền mốc thời gian từ PHP xuống**
thay vì dùng `NOW()` của MySQL:

```php
// Đúng
->orderByRaw('CASE WHEN starts_at > ? THEN starts_at END', [now()])

// Sai — lệch 7 tiếng
->orderByRaw('CASE WHEN starts_at > NOW() THEN starts_at END')
```

## Cache hay gây nhầm

Sửa route mà không thấy tác dụng: có `bootstrap/cache/routes-v7.php` cũ che mất.

```bash
$PHP artisan optimize:clear
```

Lệnh này xóa cache route, config, view và sự kiện cùng lúc.

## Khóa API Groq

Đặt trong `.env`, không bao giờ đặt trong mã nguồn:

```
GROQ_API_KEY=gsk_...
GROQ_MODEL=openai/gpt-oss-120b
GROQ_FALLBACK_MODELS=qwen/qwen3.8-27b,openai/gpt-oss-20b
```

Lấy khóa tại https://console.groq.com/keys

Đổi khóa xong chạy `$PHP artisan config:clear`.

**Model không dùng được trên tài khoản hiện tại**: `groq/compound` và
`groq/compound-mini` — chúng gọi nội bộ tới `llama-3.3-70b-versatile`,
model này đang bị chặn ở mức project.

## Lệnh hay dùng

```bash
PHP=/c/laragon/bin/php/php-8.2.27-Win32-vs16-x64/php.exe

$PHP artisan serve --host=127.0.0.1 --port=8123   # chạy
$PHP artisan test                                  # 453 test
$PHP artisan migrate:fresh --seed                  # làm mới dữ liệu (~2p40s)
$PHP artisan optimize:clear                        # xóa mọi cache
pkill -f "artisan serve"                           # dừng server
```

## Tài khoản demo

Tất cả mật khẩu: `password`

| Email | Vai trò |
|---|---|
| `admin@techcombank.local` | Quản trị viên |
| `hr@techcombank.local` | Quản trị viên |
| `minh.nv@techcombank.local` | Nhân viên |
| `anh.dm@techcombank.local` | Nhân viên mới (có màn Chào mừng) |

Còn 116 tài khoản nhân viên khác từ seeder, xem trong
**Quản trị → Hồ sơ nhân sự**.
