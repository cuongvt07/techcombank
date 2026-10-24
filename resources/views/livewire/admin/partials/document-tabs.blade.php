<nav class="mb-5 flex gap-7 overflow-x-auto border-b border-brand-line" aria-label="Quản lý tài liệu">
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
