{{--
    Không dùng overflow-x-auto: overflow-x khác visible ép overflow-y = auto, đường gạch
    chân -mb-px của tab tràn 1px xuống dưới nên trình duyệt vẽ thanh cuộn dọc tí hon
    (biểu tượng ▲▼ "⋮" ở góc phải — fb7 mục 5). Chỉ có 2 tab, xuống dòng là đủ.
--}}
<nav class="mb-5 flex flex-wrap gap-x-7 border-b border-brand-line" aria-label="Quản lý tài liệu">
    @can('documents.view')
        <a href="{{ route('admin.documents') }}" wire:navigate
           @class(['-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-1 py-3 text-sm font-semibold', 'border-brand-red text-brand-red' => $active === 'library', 'border-transparent text-brand-muted hover:text-brand-ink' => $active !== 'library'])
           @if ($active === 'library') aria-current="page" @endif>
            Thư viện tài liệu
            <span class="rounded-full bg-brand-soft px-2 py-0.5 text-xs tabular-nums">{{ $documentCount }}</span>
        </a>
    @endcan
    @can('document_access.view')
        <a href="{{ route('admin.access-rules') }}" wire:navigate
           @class(['-mb-px inline-flex shrink-0 items-center border-b-2 px-1 py-3 text-sm font-semibold', 'border-brand-red text-brand-red' => $active === 'permissions', 'border-transparent text-brand-muted hover:text-brand-ink' => $active !== 'permissions'])
           @if ($active === 'permissions') aria-current="page" @endif>
            Phân quyền tài liệu
        </a>
    @endcan
</nav>
