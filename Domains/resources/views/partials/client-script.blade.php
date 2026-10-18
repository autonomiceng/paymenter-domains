{{-- Search-only enhancement adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@once
<p class="domains-sr" aria-live="polite" id="domains-live"></p>
@verbatim
<script>
(() => {
    const live = document.getElementById('domains-live');
    document.querySelectorAll('[data-domains-results]').forEach(list => {
        const summary = () => {
            const states = [...list.querySelectorAll('[data-domains-result]')].map(row => row.dataset.state);
            if (states.includes('idle') || list.querySelector('[data-busy]')) return;
            const available = states.filter(state => state === 'available').length;
            const errors = states.filter(state => state === 'error').length;
            if (live) live.textContent = (available ? available + ' of ' + states.length + ' names are available.'
                : errors ? '' : 'None of these names are available. Try a different name.')
                + (errors ? (available ? ' ' : '') + 'Couldn’t check ' + errors
                    + (errors === 1 ? ' name. Retry to check it.' : ' names. Retry to check them.') : '');
        };
        const check = async row => {
            if (row.dataset.busy) return;
            row.dataset.busy = '1'; row.setAttribute('aria-busy', 'true');
            row.dataset.state = 'idle';
            row.querySelector('.domains-result__status').innerHTML = '<span class="domains-checking">Checking</span>';
            row.querySelector('.domains-result__price').replaceChildren();
            row.querySelector('.domains-result__note').textContent = '';
            row.querySelector('.domains-result__action').replaceChildren();
            let note = '';
            try {
                const response = await fetch(list.dataset.domainsResults, {method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
                    body: JSON.stringify({domain: row.dataset.domain, _request_key: list.dataset.requestKey})});
                if (response.status === 429) note = 'Too many searches. Wait a minute, then retry.';
                if (response.status === 419) note = 'This page expired. Reload to try again.';
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!data.html) throw new Error();
                const first = row.classList.contains('is-first');
                const template = document.createElement('template'); template.innerHTML = data.html.trim();
                const next = template.content.firstElementChild;
                next.classList.toggle('is-first', first); row.replaceWith(next);
            } catch {
                row.dataset.state = 'error';
                const status = row.querySelector('.domains-result__status');
                status.replaceChildren(); const badge = document.createElement('span');
                badge.className = 'domains-badge domains-tone-warn'; badge.textContent = 'Couldn’t check'; status.append(badge);
                row.querySelector('.domains-result__price').replaceChildren();
                row.querySelector('.domains-result__note').textContent = note;
                const actions = row.querySelector('.domains-result__action'); actions.replaceChildren();
                const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry';
                retry.className = 'domains-btn domains-btn--secondary'; retry.dataset.domainsRetry = ''; actions.append(retry);
            } finally { delete row.dataset.busy; row.removeAttribute('aria-busy'); summary(); }
        };
        list.addEventListener('click', e => { if (e.target.closest('[data-domains-retry]')) check(e.target.closest('[data-domains-result]')); });
        list.addEventListener('change', e => {
            if (e.target.matches('select[name="term"]')) {
                e.target.closest('[data-domains-result]').querySelector('.domains-result__price strong').textContent = e.target.selectedOptions[0].textContent;
            }
        });
        // Serial human-origin requests; no automatic retry and no optional shared account limiter yet.
        const queue = [...list.querySelectorAll('[data-state="idle"]')];
        (async () => { for (const row of queue) await check(row); })();
    });
})();
</script>
@endverbatim
@endonce
