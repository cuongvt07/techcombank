@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';

// Ô "Mỗi trang" (fb7 mục 6) chỉ hiện khi component đang render dùng trait HasPerPage;
// component khác (vd. các báo cáo) giữ nguyên hành vi cũ, không phát sinh wire:model lạ.
$perPageComponent = \Livewire\Livewire::current();
$perPageOptions = ($perPageComponent && in_array(\App\Livewire\Concerns\HasPerPage::class, class_uses_recursive($perPageComponent), true))
    ? $perPageComponent->perPageOptions()
    : [];
$showSummary = $summary ?? true;
// Có ô "Mỗi trang" thì vẫn hiện thanh khi chỉ có 1 trang — người dùng đã chọn 50
// dòng vẫn cần chỗ để chọn lại 15.
$showBar = $paginator->hasPages() || ($perPageOptions !== [] && $paginator->total() > 0);
$showPerPage = $perPageOptions !== [] && $paginator->total() > min($perPageOptions);
$pageBtn = 'inline-flex h-9 min-w-[36px] items-center justify-center rounded-admin border px-2.5 text-sm leading-none transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-red/40';
$pageIdle = $pageBtn . ' border-brand-line bg-white font-medium text-brand-ink hover:border-brand-red hover:text-brand-red';
$pageDisabled = $pageBtn . ' cursor-default border-brand-line bg-white text-brand-ink opacity-40';
@endphp

<div>
    @if ($showBar)
        <nav role="navigation" aria-label="Phân trang" class="list-pager">
            {{-- Bên trái: "Hiển thị 1–15 trên 122 kết quả · Mỗi trang [15 ▾]" — chữ đen, không mờ --}}
            <div class="list-pager-info">
                @if ($showSummary)
                    <p class="text-sm text-brand-ink">
                        Hiển thị
                        <span class="font-semibold">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</span>
                        trên
                        <span class="font-semibold">{{ number_format($paginator->total(), 0, ',', '.') }}</span>
                        kết quả
                    </p>
                @endif

                @if ($showPerPage)
                    <label class="list-pager-size">
                        <span>Mỗi trang</span>
                        <select wire:model.live="perPage" class="list-pager-select" aria-label="Số dòng mỗi trang">
                            @foreach ($perPageOptions as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>

            @if ($paginator->hasPages())
                <div class="flex flex-wrap items-center gap-1">
                    {{-- Trang trước --}}
                    @if ($paginator->onFirstPage())
                        <span class="{{ $pageDisabled }}" aria-disabled="true" aria-label="Trang trước">
                            @svg('heroicon-o-chevron-left', 'h-4 w-4')
                        </span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="{{ $pageIdle }}" aria-label="Trang trước">
                            @svg('heroicon-o-chevron-left', 'h-4 w-4')
                        </button>
                    @endif

                    {{-- Số trang: trang hiện tại nền đỏ chữ trắng, các trang khác nền trắng chữ đen --}}
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="{{ $pageDisabled }}" aria-disabled="true">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}" class="contents">
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page" class="{{ $pageBtn }} cursor-default border-brand-red bg-brand-red font-bold text-white">{{ $page }}</span>
                                    @else
                                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="{{ $pageIdle }}" aria-label="Đến trang {{ $page }}">
                                            {{ $page }}
                                        </button>
                                    @endif
                                </span>
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Trang sau --}}
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="{{ $pageIdle }}" aria-label="Trang sau">
                            @svg('heroicon-o-chevron-right', 'h-4 w-4')
                        </button>
                    @else
                        <span class="{{ $pageDisabled }}" aria-disabled="true" aria-label="Trang sau">
                            @svg('heroicon-o-chevron-right', 'h-4 w-4')
                        </span>
                    @endif
                </div>
            @endif
        </nav>
    @endif
</div>
