TÀI SẢN THƯƠNG HIỆU TECHCOMBANK
================================

Các file trong thư mục này được hệ thống tự động nhận diện — không cần
sửa code khi thay file.

FILE HIỆN CÓ
------------
logo.svg          Logo đầy đủ (chữ đen + hai hình thoi đỏ). Dùng trên nền sáng.
                  Nguồn: website chính thức techcombank.com.vn
                  Kích thước gốc 406 x 55 (tỉ lệ ~7.3:1)

logo-white.svg    Bản chữ trắng cho nền tối (sidebar quản trị).
                  Sinh từ logo.svg, đổi màu chữ #061922 -> #ffffff.
                  Hai hình thoi giữ nguyên màu đỏ #ec1c24.

logo-mark.svg     Chỉ hai hình thoi, dùng làm favicon và cho chỗ hẹp
                  (header mobile). Trích từ logo.svg.

pattern.svg       Hoạ tiết nền hình thoi, dùng cho trang đăng nhập.
                  Kích thước 1366 x 768, hoạ tiết dồn về bên phải.

MÃ MÀU
------
Đỏ thương hiệu   #ec1c24
Chữ đậm          #061922

THAY FILE CHÍNH THỨC TỪ BỘ CIP
-------------------------------
Khi nhận được bộ Brand Guideline chính thức từ phòng Thương hiệu, chỉ cần
ghi đè các file trên bằng file mới cùng tên. Hệ thống ưu tiên .svg, nếu
không có sẽ tìm .png cùng tên.

Lưu ý về tỉ lệ: component brand-logo đặt chiều cao (h-7 = 28px) và để chiều
rộng tự co theo tỉ lệ. Nếu logo mới có tỉ lệ khác nhiều so với 7.3:1, kiểm
tra lại sidebar (rộng 268px, trừ padding còn ~236px) xem có bị tràn không.

DÙNG TRONG VIEW
---------------
<x-brand-logo />                          Logo + dòng "Hệ thống đào tạo nội bộ"
<x-brand-logo :subtitle="false" />        Chỉ logo
<x-brand-logo :on-dark="true" />          Bản trắng cho nền tối
<x-brand-logo variant="mark" />           Chỉ hai hình thoi
