{{-- Registration and transfers can take several minutes. Read stored status until this page changes. --}}
@php($orderProgress = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::orderProgress($domain))
@if ($orderProgress['pending'])
<script data-domain-order-poll>
(() => {
    const script = document.currentScript;
    const state = @json($orderProgress['state']);
    const url = @json($domain->actions['show'].'/order-status');
    // Every tab open on this domain shares one read: the tab that reads saves the answer and when the next read is due.
    const key = 'domains-order-status:' + url;
    const first = 10000, longest = 60000, limited = 300000;
    const loaded = Date.now();
    let timer = null, stopped = false, reading = false, pace = first;
    const saved = () => {
        try { const value = JSON.parse(window.localStorage.getItem(key) || 'null'); return value && typeof value.next === 'number' ? value : null; }
        catch (_) { return null; }
    };
    const save = value => { try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (_) { /* This tab still reads on its own. */ } };
    const later = wait => { window.clearTimeout(timer); timer = window.setTimeout(poll, wait); };
    const reload = () => { stopped = true; window.location.reload(); };
    // A result read before this page loaded is older than the page itself.
    const changed = value => value.read > loaded && typeof value.state === 'string' && (value.state !== state || value.pending === false);
    // A Web Lock makes checking and saving the shared next read one step across tabs. Without one, each tab still keeps its own pace.
    const busy = {};
    const exclusive = async work => {
        const locks = window.navigator?.locks;
        if (!locks || typeof locks.request !== 'function') return work();
        let started = false;
        try { return await locks.request(key, {ifAvailable: true}, lock => { if (!lock) return busy; started = true; return work(); }); }
        catch (error) { if (started) throw error; return work(); }
    };
    const poll = async () => {
        if (stopped || reading || !script.isConnected || document.hidden) return;
        reading = true;
        let result = null;
        try { result = await exclusive(turn); } catch (_) { result = busy; } finally { reading = false; }
        if (result === busy && !stopped) { const shared = saved(), now = Date.now(); later(shared && shared.next > now ? shared.next - now : pace); }
    };
    const turn = async () => {
        const shared = saved(), now = Date.now();
        if (shared && changed(shared)) { reload(); return; }
        if (shared && shared.next > now) { later(shared.next - now); return; }
        const delay = Math.min(Math.max(shared?.delay || first, pace), longest);
        const grown = pace = Math.min(Math.round(delay * 1.5), longest);
        save({...(shared || {}), next: now + delay, delay});
        let wait = grown;
        try {
            const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) { stopped = true; return; }
            if (response.status === 429) {
                const after = Number(response.headers?.get('Retry-After'));
                wait = Math.min(Math.max(Number.isFinite(after) ? after * 1000 : 0, grown), limited);
            } else if (response.ok) {
                const current = await response.json();
                if (!script.isConnected) return;
                if (typeof current.state !== 'string' || typeof current.pending !== 'boolean') throw new Error('Invalid order status');
                const read = Date.now();
                if (current.state !== state || !current.pending) { save({state: current.state, pending: current.pending, read, next: read + first, delay: first}); reload(); return; }
                save({state: current.state, pending: true, read, next: read + grown, delay: grown});
                later(grown);
                return;
            }
        } catch (_) { /* Retry local status after a temporary network failure. */ }
        save({...(saved() || {}), next: Date.now() + wait, delay: grown});
        later(wait);
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !stopped) poll(); });
    later(first);
})();
</script>
@endif
