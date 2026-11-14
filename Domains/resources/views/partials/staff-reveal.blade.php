{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Staff one-line texts (OneLine) that the browser cuts: in a row that opens nothing, a cut text takes a tab stop, kept right as text, widths and fonts change; keyboard focus on a cut text, or on the row link around it, shows the whole text in an overlay that leaves the table as it is. An overlay too tall for the screen scrolls by wheel, arrow and Page keys, and focus stays on the text. --}}
<script data-domains-reveal data-navigate-once>
(() => {
    if (window.domainsReveal) return;
    window.domainsReveal = true;
    const ITEMS = '.domains-cut,.domains-line,.domains-name-cell', OWN = 'data-domains-focus';
    const observed = new WeakSet();
    const SCROLL = 'data-domains-scroll', KEYS = { ArrowDown: [1, 'line'], ArrowUp: [-1, 'line'], PageDown: [1, 'page'], PageUp: [-1, 'page'] };
    let overlay = null, anchor = null, queued = false;
    // Cut: the item, or a block inside it, is narrower than its text. Inline children have no box of their own.
    const cut = item => [item, ...item.children].some(box => box.clientWidth > 0 && box.scrollWidth > box.clientWidth);
    // A row link or button already takes focus. Otherwise only a cut text needs a tab stop, and only the ones added here are removed.
    function sync(item) {
        const want = !item.closest('a[href],button') && cut(item), own = item.hasAttribute(OWN);
        if (want && !own) { item.setAttribute('tabindex', '0'); item.setAttribute(OWN, ''); }
        if (!want && own) { item.removeAttribute('tabindex'); item.removeAttribute(OWN); }
    }
    const resized = new ResizeObserver(entries => entries.forEach(entry => sync(entry.target)));
    function scan() {
        queued = false;
        document.querySelectorAll(ITEMS).forEach(item => {
            if (!observed.has(item)) { observed.add(item); resized.observe(item); }
            sync(item);
        });
        if (anchor && !anchor.isConnected) hide();
    }
    const queue = () => { if (!queued) { queued = true; requestAnimationFrame(scan); } };
    // Fixed to the viewport, so no table, row or scroll container changes size. Below the text, or above it; when neither
    // side has room (a short screen), over the text. Never past the viewport, even for text scrolled out of view: staff-styles
    // caps its height, and it scrolls.
    function place() {
        if (!anchor) return;
        // Measured at the origin: a fixed box shrinks to the room right of its last left, which would wrap it taller.
        overlay.style.left = overlay.style.top = '0px';
        const box = anchor.getBoundingClientRect(), edge = 8, gap = 4, height = overlay.offsetHeight;
        overlay.style.left = `${Math.max(edge, Math.min(box.left, innerWidth - overlay.offsetWidth - edge))}px`;
        const below = box.bottom + gap, above = box.top - gap - height;
        const top = below + height <= innerHeight - edge ? below : above >= edge ? above : innerHeight - edge - height;
        overlay.style.top = `${Math.max(edge, Math.min(top, innerHeight - edge - height))}px`;
        // Only an overlay whose text still overflows takes the mouse (to scroll); otherwise hover reaches the rows under it.
        overlay.toggleAttribute(SCROLL, overlay.scrollHeight > overlay.clientHeight);
    }
    function show(items) {
        if (!overlay?.isConnected) {
            overlay = document.body.appendChild(document.createElement('div'));
            overlay.className = 'domains-reveal';
            // Screen readers already read the whole text in the cell.
            overlay.setAttribute('aria-hidden', 'true');
            // A press on it would move focus off the text and close it.
            overlay.addEventListener('mousedown', event => event.preventDefault());
        }
        overlay.textContent = items.map(item => item.innerText.replace(/\s+/g, ' ').trim()).join('\n');
        anchor = items[0];
        overlay.hidden = false;
        overlay.scrollTop = 0;
        place();
    }
    function hide() {
        anchor = null;
        if (overlay) overlay.hidden = true;
    }
    document.addEventListener('focusin', ({ target }) => {
        if (!(target instanceof Element) || !target.matches(':focus-visible')) return hide();
        const items = (target.matches(ITEMS) ? [target] : target.matches('a[href],button') ? [...target.querySelectorAll(ITEMS)] : []).filter(cut);
        items.length ? show(items) : hide();
    });
    document.addEventListener('focusout', hide);
    // While the text (or its row link) keeps focus, arrow and Page keys scroll an overflowing overlay, and fall through to
    // the page when it cannot scroll further. Tab still moves on, and Escape closes it.
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') return hide();
        const [way, size] = KEYS[event.key] ?? [];
        if (!way || !anchor || !overlay.hasAttribute(SCROLL) || event.altKey || event.ctrlKey || event.metaKey) return;
        const before = overlay.scrollTop, line = parseFloat(getComputedStyle(overlay).lineHeight) || 20;
        overlay.scrollTop += way * (size === 'line' ? line : Math.max(line, overlay.clientHeight - line));
        if (overlay.scrollTop !== before) event.preventDefault();
    });
    addEventListener('scroll', ({ target }) => { if (target !== overlay) place(); }, true);
    addEventListener('resize', place);
    new MutationObserver(records => { if (records.some(record => !overlay?.contains(record.target))) queue(); })
        .observe(document.documentElement, { childList: true, subtree: true, characterData: true });
    // A font that finishes loading changes text widths without changing a box or the DOM: measure again.
    document.fonts?.ready.then(queue);
    document.fonts?.addEventListener('loadingdone', queue);
    queue();
})();
</script>

{{-- Remember only column names and visibility, never record data or a user's server profile. --}}
<script data-domains-columns data-navigate-once>
// A host's row-selection handler must not take browser gestures away from real record links.
window.addEventListener('click', event => {
    if ((event.ctrlKey || event.metaKey || event.shiftKey) && event.target.closest('[data-domains-table] a[href]')) event.stopPropagation();
}, true);
window.domainsColumns = async (element, wire) => {
    // The component class key is stable across record URLs and page reloads.
    const tableKey = 'domains.columns.' + element.dataset.domainsColumnsKey;
    const choices = columns => Object.fromEntries(columns.filter(c => c.isToggleable).map(c => [c.name, !!c.isToggled]));
    let saved;
    try { saved = JSON.parse(localStorage.getItem(tableKey) || 'null'); } catch (_) {}
    const columns = wire.$get('tableColumns');
    if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
        const restored = columns.map(c => ({ ...c, isToggled: c.isToggleable && typeof saved[c.name] === 'boolean' ? saved[c.name] : c.isToggled }));
        if (JSON.stringify(choices(restored)) !== JSON.stringify(choices(columns))) await wire.applyTableColumnManager(restored);
    }
    wire.$watch('tableColumns', columns => {
        try { localStorage.setItem(tableKey, JSON.stringify(choices(columns))); } catch (_) {}
    });
};
</script>
