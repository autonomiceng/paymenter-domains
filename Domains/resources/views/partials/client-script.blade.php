{{-- Shared progressive enhancement adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@once
<p class="domains-sr" aria-live="polite" id="domains-live" data-nameservers-filled="{{ ucfirst(\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::nameservers()).' filled in. Save to apply them.' }}"></p>
@verbatim
<script>
(() => {
    const live = document.getElementById('domains-live');
    document.querySelectorAll('.domains-record-form select[name$="[type]"]').forEach(type => {
        const row = type.closest('tr');
        const priority = row.querySelector('input[name$="[mx_pref]"]');
        // A saved row keeps its TTL only while its type is unchanged; otherwise the registrar default is sent.
        const ttl = row.querySelector('[data-saved-ttl]');
        if (!priority && !ttl) return;
        const update = () => {
            if (priority) priority.disabled = type.value !== 'MX';
            if (ttl) ttl.textContent = type.value === ttl.dataset.savedType ? ttl.dataset.savedTtl : 'Registrar default';
        };
        type.addEventListener('change', update); update();
    });
    document.querySelectorAll('[data-contact-fields]').forEach(group => {
        const country = group.querySelector('[name$="[country]"]');
        const state = group.querySelector('[name$="[state_province]"]');
        if (!country || !state) return;
        const codes = group.querySelector('datalist[data-state-codes]');
        const suggestions = codes ? JSON.parse(codes.dataset.stateCodes) : {};
        const update = () => {
            state.required = group.dataset.stateRequired === '1' || ['US', 'CA', 'AU'].includes(country.value);
            if (codes) codes.replaceChildren(...(suggestions[country.value] || []).map(code => {
                const option = document.createElement('option'); option.value = code; return option;
            }));
        };
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
        // A record row's Delete adds a hidden delete field; Cancel takes it back out.
        const deleting = row => !!row.querySelector('input[name$="[_delete]"]');
        const filled = row => [...row.querySelectorAll('input:not([type=hidden]):not(:disabled), select:not(:disabled)')].some(field => field.value !== '');
        const removed = () => [...form.querySelectorAll('tr[data-record]')].filter(row => deleting(row) || !filled(row)).map(row => row.dataset.record);
        let added = [];
        const needed = () => {
            if (form.dataset.confirmWhen === 'lock-off') return form.elements.locked?.value === '0';
            if (form.dataset.confirmWhen === 'records-removed') {
                const kept = [...form.querySelectorAll('tbody tr')].filter(row => !deleting(row) && filled(row)).length;
                return removed().length > 0 || kept < Number(form.dataset.current || 0);
            }
            return true;
        };
        const servers = form.elements.nameservers;
        form.querySelector('[data-fill-defaults]')?.addEventListener('click', event => {
            event.preventDefault();
            servers.value = form.dataset.defaultServers;
            servers.dispatchEvent(new Event('input', {bubbles: true})); servers.focus();
            if (live) live.textContent = live.dataset.nameserversFilled;
        });
        // The same rules the server applies to a nameserver list (OwnerNameservers::parse), so a list it would refuse
        // says why inside the editor and never reaches the warning.
        const nameserverProblem = () => {
            const max = Number(form.dataset.nameserversMax || 0);
            if (!servers || !max) return '';
            const lines = servers.value.split(/\r\n|\n|\r/).map(line => line.trim()).filter(Boolean);
            if (lines.length < 2) return 'Enter at least 2 nameservers, one per line.';
            if (lines.length > max) return 'Enter no more than ' + max + ' nameservers.';
            const seen = [];
            for (const line of lines) {
                const host = (line.endsWith('.') ? line.slice(0, -1) : line).toLowerCase(), labels = host.split('.');
                const valid = host.length <= 253 && labels.length > 1 && labels.every(label => /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/.test(label))
                    && !/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/.test(host) && !(form.dataset.nameserversLetterEnding && !/[a-z]/.test(labels[labels.length - 1]));
                if (!valid) return line.slice(0, 254) + " isn't a valid hostname. Use a name like ns1.example.com.";
                if (seen.includes(host)) return host + ' is listed more than once.';
                seen.push(host);
            }
            return '';
        };
        const showProblem = message => {
            const id = servers.id + '-client-error';
            let error = document.getElementById(id);
            if (!error) {
                error = document.createElement('p'); error.id = id; error.className = 'domains-error';
                error.setAttribute('aria-live', 'polite'); servers.after(error);
            }
            error.textContent = message; servers.setAttribute('aria-invalid', 'true');
            const refs = new Set((servers.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
            refs.add(id); servers.setAttribute('aria-describedby', [...refs].join(' ')); servers.focus();
        };
        // The warning takes the editor's place instead of opening on top of it. A close event from reopening an open
        // warning arrives after the warning is open again, so the editor stays hidden while it is.
        const editor = form.closest('dialog.domains-edit');
        dialog.addEventListener('close', () => { if (!dialog.open) editor?.classList.remove('domains-edit--behind'); });
        form.addEventListener('submit', event => {
            if (event.submitter?.name === 'confirmed') return;
            if (!event.submitter?.hasAttribute('formnovalidate')) {
                const problem = nameserverProblem();
                if (problem) { event.preventDefault(); showProblem(problem); return; }
            }
            if (event.submitter?.matches('[data-delete-record]')) {
                const mark = Object.assign(document.createElement('input'), {type: 'hidden', name: event.submitter.name, value: '1'});
                event.submitter.after(mark); event.submitter.hidden = true; added.push(mark);
            }
            if (!needed()) return;
            event.preventDefault();
            if (servers) fill('nameservers', servers.value.split(/\r?\n/).map(line => line.trim()).filter(Boolean));
            if (form.dataset.confirmWhen === 'records-removed') fill('removed', removed());
            if (dialog.open) dialog.close();
            editor?.classList.add('domains-edit--behind');
            dialog.showModal();
        });
        const undo = () => { added.forEach(mark => { mark.previousElementSibling.hidden = false; mark.remove(); }); added = []; };
        dialog.addEventListener('cancel', undo);
        dialog.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => { undo(); dialog.close(); });
    });
    // Page editors: the row link opens its dialog as a modal; without script the link reloads with the editor open.
    document.querySelectorAll('a[data-dialog-open]').forEach(link => {
        const dialog = document.getElementById(link.hash.slice(1));
        if (!dialog || typeof dialog.showModal !== 'function') return;
        link.addEventListener('click', event => { event.preventDefault(); if (dialog.open) dialog.close(); dialog.showModal(); });
    });
    document.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
    document.querySelectorAll('dialog.domains-edit[open]').forEach(dialog => {
        if (typeof dialog.showModal !== 'function' || dialog.querySelector('dialog[open]')) return;
        dialog.close(); dialog.showModal();
    });
    // List filters apply on change; the search box submits on Enter.
    document.querySelectorAll('form[data-domains-autosubmit]').forEach(form => {
        form.addEventListener('change', event => { if (event.target.matches('select')) form.requestSubmit(); });
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
    document.querySelectorAll('[data-renewal-form]').forEach(form => {
        form.querySelectorAll('[data-renewal-summary]').forEach(row => { row.hidden = false; });
        const updateRenewal = () => {
            const option = form.querySelector('select').selectedOptions[0];
            form.querySelector('[data-renewal-expiry]').textContent = option.dataset.expiry || 'Confirmed at payment';
            form.querySelector('[data-renewal-breakdown]').textContent = option.dataset.breakdown || '';
            form.querySelector('[data-renewal-total]').textContent = option.dataset.total || 'Confirmed at payment';
        };
        form.querySelector('select').addEventListener('change', updateRenewal);
        updateRenewal();
    });
    document.querySelectorAll('[data-domains-results]').forEach(list => {
        const summary = () => {
            const states = [...list.querySelectorAll('[data-domains-result]')].map(row => row.dataset.state);
            if (states.includes('idle') || list.querySelector('[data-busy]')) return;
            if (list.dataset.mode === 'transfer') {
                const row = list.querySelector('[data-domains-result]');
                if (live) live.textContent = row?.querySelector('.domains-result__note').textContent.trim()
                    || (states.includes('transferable') ? 'This name can be transferred. Choose Transfer to continue.'
                    : states.includes('in_cart') ? 'Already in your cart. Go to your cart to continue.'
                    : states.includes('busy') ? 'Try again in a moment.'
                    : 'Couldn’t check this transfer. Retry to check it.');
                return;
            }
            const available = states.filter(state => state === 'available').length;
            const errors = states.filter(state => state === 'error').length;
            const busy = states.filter(state => state === 'busy').length;
            const inCart = states.filter(state => state === 'in_cart').length;
            if (live) live.textContent = (available ? available + ' of ' + states.length + ' names are available.'
                + (inCart ? ' ' + inCart + (inCart === 1 ? ' is' : ' are') + ' in your cart.' : '')
                : errors || busy ? '' : states.includes('in_cart') ? 'Already in your cart. Try a different name, or go to your cart.'
                : 'None of these names are available. Try a different name.')
                + (errors ? (available ? ' ' : '') + 'Couldn’t check ' + errors
                    + (errors === 1 ? ' name. Retry to check it.' : ' names. Retry to check them.') : '')
                + (busy ? (available || errors ? ' ' : '') + 'Try again in a moment to check the remaining names.' : '');
        };
        const check = async row => {
            if (row.dataset.busy) return;
            row.dataset.busy = '1'; row.setAttribute('aria-busy', 'true');
            row.dataset.state = 'idle';
            row.querySelector('.domains-result__status').innerHTML = '<span class="domains-checking">Checking</span>';
            row.querySelector('.domains-result__price').replaceChildren();
            row.querySelector('.domains-result__note').textContent = '';
            row.querySelector('.domains-result__action').replaceChildren();
            let note = list.dataset.mode === 'transfer' ? 'We couldn’t check this transfer right now. Try again in a few minutes.'
                : 'We couldn’t check this name right now. Retry in a minute.';
            try {
                const response = await fetch(list.dataset.domainsResults, {method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
                    body: JSON.stringify({domain: row.dataset.domain, ...(list.dataset.mode === 'transfer' ? {mode: 'transfer'} : {}), _request_key: list.dataset.requestKey, ...(list.dataset.replace ? {replace: list.dataset.replace} : {}), ...(list.dataset.instead ? {instead: 1} : {})})});
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
                badge.className = list.dataset.mode === 'transfer' ? 'domains-result-label' : 'domains-badge domains-tone-warn'; badge.textContent = 'Couldn’t check'; status.append(badge);
                row.querySelector('.domains-result__price').replaceChildren();
                const message = row.querySelector('.domains-result__note'); message.textContent = note;
                if (list.dataset.supportUrl && !/expired|Too many/.test(note)) {
                    const support = document.createElement('a'); support.className = 'domains-a';
                    support.href = list.dataset.supportUrl; support.textContent = 'Contact support'; message.append(' ', support);
                }
                const actions = row.querySelector('.domains-result__action'); actions.replaceChildren();
                const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Retry';
                retry.className = 'domains-btn domains-btn--secondary'; retry.dataset.domainsRetry = ''; actions.append(retry);
            } finally { delete row.dataset.busy; row.removeAttribute('aria-busy'); summary(); }
        };
        list.addEventListener('click', e => { if (e.target.closest('[data-domains-retry]')) check(e.target.closest('[data-domains-result]')); });
        list.addEventListener('change', e => {
            if (e.target.matches('select[name="term"]')) {
                const option = e.target.selectedOptions[0];
                e.target.closest('[data-domains-result]').querySelector('.domains-result__price strong').textContent = option.dataset.total;
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
