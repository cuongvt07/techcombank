const TABLE_SELECTOR = 'table.admin-table, table.permission-grid';
const SCROLLER_SELECTOR = '.overflow-x-auto, .overflow-auto, .data-scroll, .data-grid-scroll';
const overlays = new Map();
let framePending = false;

function findScroller(table) {
    for (let node = table.parentElement; node && node !== document.body; node = node.parentElement) {
        if (node.matches(SCROLLER_SELECTOR)) return node;
    }

    return table.parentElement;
}

function makeOverlay(table, scroller) {
    const wrapper = document.createElement('div');
    wrapper.className = 'data-grid-sticky-header';
    wrapper.setAttribute('aria-hidden', 'true');

    const clonedTable = table.cloneNode(false);
    clonedTable.removeAttribute('id');
    clonedTable.style.margin = '0';
    clonedTable.style.width = `${table.offsetWidth}px`;

    const clonedHead = table.tHead?.cloneNode(true);
    if (!clonedHead) return null;
    sanitizeHeader(clonedHead);
    sizeHeader(table.tHead, clonedHead);

    clonedTable.append(clonedHead);
    wrapper.append(clonedTable);
    document.body.append(wrapper);

    return { wrapper, clonedTable, table, scroller, sourceMarkup: table.tHead.innerHTML };
}

function sanitizeHeader(head) {
    head.querySelectorAll('[id], [wire\\:click], [wire\\:model], [x-on\\:click]').forEach((node) => {
        node.removeAttribute('id');
        node.removeAttribute('wire:click');
        node.removeAttribute('wire:model');
        node.removeAttribute('x-on:click');
    });
}

function sizeHeader(source, clone) {
    [...source.rows].forEach((row, rowIndex) => {
        [...row.cells].forEach((cell, cellIndex) => {
            const cloneCell = clone.rows[rowIndex]?.cells[cellIndex];
            if (cloneCell) cloneCell.style.width = `${cell.getBoundingClientRect().width}px`;
        });
    });
}

function updateOverlay(overlay) {
    const { wrapper, clonedTable, table, scroller } = overlay;
    if (!table.isConnected || !scroller?.isConnected || !table.tHead) {
        wrapper.remove();
        overlays.delete(table);
        return;
    }

    const headRect = table.tHead.getBoundingClientRect();
    const tableRect = table.getBoundingClientRect();
    const scrollerRect = scroller.getBoundingClientRect();
    const top = headRect.top < 0 && tableRect.bottom > 0;

    if (overlay.sourceMarkup !== table.tHead.innerHTML) {
        const clonedHead = table.tHead.cloneNode(true);
        sanitizeHeader(clonedHead);
        sizeHeader(table.tHead, clonedHead);
        clonedTable.tHead.replaceWith(clonedHead);
        overlay.sourceMarkup = table.tHead.innerHTML;
    }

    wrapper.style.display = top ? 'block' : 'none';
    if (!top) return;

    wrapper.style.left = `${scrollerRect.left + scroller.clientLeft}px`;
    wrapper.style.width = `${scroller.clientWidth}px`;
    clonedTable.style.width = `${table.offsetWidth}px`;
    clonedTable.style.transform = `translateX(${-scroller.scrollLeft}px)`;

    [...table.tHead.rows].forEach((row, rowIndex) => {
        [...row.cells].forEach((cell, cellIndex) => {
            const cloneCell = clonedTable.tHead.rows[rowIndex]?.cells[cellIndex];
            if (cloneCell) cloneCell.style.width = `${cell.getBoundingClientRect().width}px`;
        });
    });
}

function refresh() {
    framePending = false;

    document.querySelectorAll(TABLE_SELECTOR).forEach((table) => {
        const scroller = findScroller(table);
        const current = overlays.get(table);

        if (!current || current.scroller !== scroller) {
            current?.wrapper.remove();
            const next = makeOverlay(table, scroller);
            if (next) overlays.set(table, next);
        }
    });

    overlays.forEach(updateOverlay);
}

function scheduleRefresh() {
    if (framePending) return;
    framePending = true;
    window.requestAnimationFrame(refresh);
}

function initialize() {
    document.addEventListener('scroll', scheduleRefresh, { passive: true, capture: true });
    window.addEventListener('resize', scheduleRefresh, { passive: true });

    new MutationObserver(scheduleRefresh).observe(document.body, {
        childList: true,
        characterData: true,
        subtree: true,
    });

    scheduleRefresh();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
} else {
    initialize();
}
