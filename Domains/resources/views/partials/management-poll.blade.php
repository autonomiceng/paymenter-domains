{{-- Poll only local operation state; completion reloads the confirmed values and available controls. --}}
@if (array_filter($domain->management, fn ($section) => $section['progress']['pending'] ?? false))
<script>
(() => {
    const started = Date.now();
    const url = @json($domain->actions['show'].'/management-status');
    const poll = async () => {
        let slow = Date.now() - started >= 30000;
        try {
            const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (response.status === 401 || response.status === 403 || response.status === 404) return;
            if (response.ok) {
                const rows = await response.json();
                for (const control of document.querySelectorAll('[data-management-pending]')) {
                    const setting = control.dataset.managementPending === 'srv' ? 'dns' : control.dataset.managementPending;
                    const row = rows[setting];
                    if (!row || !row.pending || row.id !== Number(control.dataset.operation)) { window.location.reload(); return; }
                    control.textContent = row.text;
                    slow ||= row.slow;
                }
            }
        } catch (_) { /* A failed status read grants no send authority. Try the stored state again. */ }
        window.setTimeout(poll, slow ? 10000 : 2000);
    };
    window.setTimeout(poll, 2000);
})();
</script>
@endif
