<script>
(() => {
    const tools = document.getElementById('domains-price-tools');
    const input = document.getElementById('domains-price-filter');
    const count = document.getElementById('domains-price-count');
    const all = document.getElementById('domains-price-all');
    const empty = document.getElementById('domains-price-empty');
    const rows = Array.from(document.querySelectorAll('#domains-price-rows tr'), row => ({row, name: row.dataset.tld.toLowerCase()}));
    if (!tools || !input) return;
    const limit = 25;
    let expanded = false;
    let timer;
    function update() {
        const query = input.value.trim().toLowerCase().replace(/^\./, '');
        let matches = 0;
        let shown = 0;
        for (const {row, name} of rows) {
            const match = name.includes(query);
            if (match) matches++;
            // A filter searches the entire catalogue; only the initial list is shortened.
            const visible = match && (query || expanded || shown < limit);
            row.hidden = !visible;
            if (visible) shown++;
        }
        count.textContent = `${shown} of ${rows.length} TLDs${query ? ` (${matches} matching)` : ''}`;
        empty.hidden = matches !== 0;
        all.hidden = Boolean(query) || expanded || rows.length <= limit;
    }
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(update, 80); });
    all.addEventListener('click', () => { clearTimeout(timer); expanded = true; update(); });
    tools.hidden = false;
    update();
})();
</script>
