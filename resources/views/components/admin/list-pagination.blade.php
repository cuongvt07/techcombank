@props([
    'paginator',
])

{{--
    Chân bảng dữ liệu: "Hiển thị 1–15 trên 122 kết quả · Mỗi trang [15 ▾]" + số trang (fb7 mục 6).
    Không chỉ hiện khi có nhiều trang như trước: đã chọn "Mỗi trang 50" mà dữ liệu chỉ còn
    một trang thì vẫn phải thấy ô chọn để đổi lại. View phân trang tự quyết phần nào hiện.
--}}
@if ($paginator->hasPages() || $paginator->total() > 0)
    <div {{ $attributes->class('border-t border-brand-line px-4 py-3') }}>
        {{ $paginator->links() }}
    </div>
@endif
