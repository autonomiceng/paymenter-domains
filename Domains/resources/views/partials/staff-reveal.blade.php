{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Staff one-line texts (OneLine) that the browser cuts: in a row that opens nothing, a cut text takes a tab stop, kept right as text, widths and fonts change; keyboard focus on a cut text, or on the row link around it, shows the whole text in an overlay that leaves the table as it is. --}}
<script data-domains-reveal data-navigate-once>
(() => {
    if (window.domainsReveal) return;
    window.domainsReveal = true;
    const ITEMS = '.domains-cut,.domains-line,.domains-name-cell', OWN = 'data-domains-focus';
    const observed = new WeakSet();
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
    // side has room (a short screen), over the text. Never past the viewport: staff-styles caps its height, and it scrolls.
    function place() {
        if (!anchor) return;
        // Measured at the origin: a fixed box shrinks to the room right of its last left, which would wrap it taller.
        overlay.style.left = overlay.style.top = '0px';
        const box = anchor.getBoundingClientRect(), edge = 8, gap = 4, height = overlay.offsetHeight;
        overlay.style.left = `${Math.max(edge, Math.min(box.left, innerWidth - overlay.offsetWidth - edge))}px`;
        const below = box.bottom + gap, above = box.top - gap - height;
        const top = below + height <= innerHeight - edge ? below : above >= edge ? above : innerHeight - edge - height;
        overlay.style.top = `${Math.max(edge, top)}px`;
    }
    function show(items) {
        if (!overlay?.isConnected) {
            overlay = document.body.appendChild(document.createElement('div'));
            overlay.className = 'domains-reveal';
            // Screen readers already read the whole text in the cell.
            overlay.setAttribute('aria-hidden', 'true');
        }
        overlay.textContent = items.map(item => item.innerText.replace(/\s+/g, ' ').trim()).join('\n');
        anchor = items[0];
        overlay.hidden = false;
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
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    addEventListener('scroll', place, true);
    addEventListener('resize', place);
    new MutationObserver(records => { if (records.some(record => !overlay?.contains(record.target))) queue(); })
        .observe(document.documentElement, { childList: true, subtree: true, characterData: true });
    // A font that finishes loading changes text widths without changing a box or the DOM: measure again.
    document.fonts?.ready.then(queue);
    document.fonts?.addEventListener('loadingdone', queue);
    queue();
})();
</script>
