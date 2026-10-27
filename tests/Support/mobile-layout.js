// MIT, Copyright (c) 2026 Autonomic Engineering. Run on the real Blade fixtures at a 390px viewport.
(() => {
    const errors = [];
    const cells = [...document.querySelectorAll('.domains-pricelist tbody td')];
    if (document.querySelector('.domains-pricelist') && cells.length < 3) errors.push('Price cells missing');
    for (let i = 0; i < cells.length; i++) {
        const a = cells[i].getBoundingClientRect();
        for (const cell of cells.slice(i + 1)) {
            const b = cell.getBoundingClientRect();
            if (Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 &&
                Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1) {
                errors.push(`${cells[i].dataset.label} overlaps ${cell.dataset.label}`);
            }
        }
    }
    const history = document.querySelector('#history');
    if (history) {
        if (!history.textContent.includes('Nameservers') || !history.textContent.includes('Completed')) errors.push('History fixture missing');
        const walker = document.createTreeWalker(history, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            for (const match of node.textContent.matchAll(/\b[A-Za-z]+\b/g)) {
                const range = document.createRange();
                range.setStart(node, match.index); range.setEnd(node, match.index + match[0].length);
                const lines = new Set([...range.getClientRects()].filter(r => r.width > 0).map(r => Math.round(r.top)));
                if (lines.size > 1) errors.push(`History word splits: ${match[0]}`);
            }
        }
    }
    if (document.documentElement.scrollWidth > innerWidth) errors.push('Page overflows viewport');
    return {width: innerWidth, errors};
})()
