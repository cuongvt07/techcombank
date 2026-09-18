{{--
    Cây thư mục đệ quy.

    Dữ liệu đã được nhóm sẵn theo parent_id ở component, nên mỗi cấp chỉ đọc
    từ mảng có sẵn — không sinh truy vấn mới trong vòng lặp.
--}}
@foreach ($nodes as $node)
    <button type="button" wire:click="openFolder({{ $node->id }})"
            wire:key="tree-{{ $node->id }}"
            @class([
                'flex w-full items-center gap-2 whitespace-nowrap rounded-admin py-2 pr-2 text-left text-[13px] transition-colors',
                'bg-brand-red-tint font-semibold text-brand-red' => $activeId === $node->id,
                'font-medium text-brand-ink hover:bg-brand-soft' => $activeId !== $node->id,
            ])
            style="padding-left: {{ 10 + $level * 14 }}px">
        @svg($activeId === $node->id ? 'heroicon-s-folder-open' : 'heroicon-o-folder', 'h-4 w-4 shrink-0 text-state-warning')
        <span class="min-w-0 flex-1 truncate">{{ $node->name }}</span>
        @if ($node->files_count > 0)
            <span class="shrink-0 text-[11px] text-brand-muted">{{ $node->files_count }}</span>
        @endif
    </button>

    @if (isset($tree[$node->id]))
        @include('livewire.admin.partials.folder-tree', [
            'nodes' => $tree[$node->id],
            'tree' => $tree,
            'level' => $level + 1,
            'activeId' => $activeId,
        ])
    @endif
@endforeach
