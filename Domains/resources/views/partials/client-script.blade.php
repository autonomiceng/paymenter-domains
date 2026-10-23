{{-- Shared progressive enhancement adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@once
<p class="domains-sr" aria-live="polite" id="domains-live"></p>
@verbatim
<script>
(() => {
    const live = document.getElementById('domains-live');
    document.querySelectorAll('.domains-record-form select[name$="[type]"]').forEach(type => {
        const priority = type.closest('tr').querySelector('input[name$="[mx_pref]"]');
        if (!priority) return;
        const update = () => { priority.disabled = type.value !== 'MX'; };
        type.addEventListener('change', update); update();
    });
    document.querySelectorAll('[data-contact-fields]').forEach(group => {
        const country = group.querySelector('[name$="[country]"]');
        const state = group.querySelector('[name$="[state_province]"]');
        if (!country || !state) return;
        const update = () => { state.required = group.dataset.stateRequired === '1' || ['US', 'CA', 'AU'].includes(country.value); };
        country.addEventListener('change', update); update();
    });
    document.querySelectorAll('.domains form').forEach(form => {
        form.addEventListener('invalid', event => {
            const field = event.target;
            if (!field.id) return;
            const id = field.id + '-client-error';
            let error = document.getElementById(id);
            if (!error) {
                error = document.createElement('p'); error.id = id; error.className = 'domains-error';
                error.setAttribute('aria-live', 'polite'); field.after(error);
            }
            error.textContent = field.validity.valueMissing ? 'Required.' : field.validity.typeMismatch ? 'Check the format.' : 'Check this field.';
            field.setAttribute('aria-invalid', 'true');
            const refs = new Set((field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
            refs.add(id); field.setAttribute('aria-describedby', [...refs].join(' '));
        }, true);
        form.addEventListener('input', event => {
            const field = event.target, id = field.id + '-client-error';
            const error = document.getElementById(id);
            if (!error || !field.validity.valid) return;
            error.remove();
            const refs = (field.getAttribute('aria-describedby') || '').split(' ').filter(ref => ref && ref !== id);
            if (refs.length) field.setAttribute('aria-describedby', refs.join(' ')); else field.removeAttribute('aria-describedby');
            if (!refs.some(ref => ref.endsWith('-error'))) field.removeAttribute('aria-invalid');
        });
    });
    // Risky owner actions: submit opens the confirm dialog; only its button sends the confirmation field.
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        const dialog = form.querySelector('[data-confirm-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') return;
        const fill = (slot, lines) => {
            const dd = dialog.querySelector('[data-confirm-slot="' + slot + '"]');
            if (!dd) return;
            dd.replaceChildren(...lines.map(line => { const span = document.createElement('span'); span.className = 'domains-record-value'; span.textContent = line; return span; }));
            dd.closest('[data-confirm-row]').hidden = !lines.length;
        };
        const removed = () => [...form.querySelectorAll('tr[data-record]')].filter(row =>
            row.querySelector('input[name$="[_delete]"]')?.checked
            || ![...row.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), select')].some(field => field.value.trim() !== '')
        ).map(row => row.dataset.record);
        const needed = () => {
            if (form.dataset.confirmWhen === 'lock-off') return form.elements.locked?.value === '0';
            if (form.dataset.confirmWhen === 'records-removed') {
                const kept = [...form.querySelectorAll('tbody tr')].filter(row => !row.querySelector('input[name$="[_delete]"]')?.checked
                    && [...row.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), select')].some(field => field.value.trim() !== '')).length;
                return removed().length > 0 || kept < Number(form.dataset.current || 0);
            }
            return true;
        };
        const servers = form.elements.nameservers, useDefault = form.querySelector('[data-use-default]');
        form.querySelector('[data-fill-defaults]')?.addEventListener('click', event => {
            event.preventDefault();
            servers.value = form.dataset.defaultServers; useDefault.disabled = false;
            servers.dispatchEvent(new Event('input', {bubbles: true})); servers.focus();
            if (live) live.textContent = 'Default nameservers filled in. Save to apply them.';
        });
        servers?.addEventListener('input', event => { if (event.isTrusted && useDefault) useDefault.disabled = true; });
        form.addEventListener('submit', event => {
            if (event.submitter?.name === 'confirmed' || !needed()) return;
            event.preventDefault();
            if (servers) fill('nameservers', servers.value.split(/\r?\n/).map(line => line.trim()).filter(Boolean));
            if (form.dataset.confirmWhen === 'records-removed') fill('removed', removed());
            if (dialog.open) dialog.close();
            dialog.showModal();
        });
        dialog.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => dialog.close());
    });
    // Help popovers: hover, focus, click or tap opens; Esc, a second click or leaving closes.
    const helps = [...document.querySelectorAll('[data-help]')];
    helps.forEach(help => {
        const button = help.querySelector('button');
        let pinned = false, refocus = false, timer;
        help.dataset.ready = '';
        help.close = () => show(false);
        const show = open => { clearTimeout(timer); help.toggleAttribute('data-open', open); button.setAttribute('aria-expanded', String(open)); if (!open) pinned = false; };
        help.addEventListener('mouseenter', () => show(true));
        help.addEventListener('mouseleave', () => { if (!pinned && !help.contains(document.activeElement)) timer = setTimeout(() => show(false), 150); });
        button.addEventListener('focus', () => { if (!refocus) show(true); });
        help.addEventListener('focusout', event => { if (!help.contains(event.relatedTarget)) show(false); });
        button.addEventListener('click', () => { pinned = !pinned || !help.hasAttribute('data-open'); show(pinned); });
        help.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || !help.hasAttribute('data-open')) return;
            event.stopPropagation(); show(false); refocus = true; button.focus(); refocus = false;
        });
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') helps.forEach(help => help.close()); });
    document.addEventListener('click', event => helps.forEach(help => { if (!help.contains(event.target)) help.close(); }));
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
