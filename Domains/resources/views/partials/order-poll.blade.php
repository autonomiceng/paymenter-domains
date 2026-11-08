{{-- Registration and transfers can take several minutes. Read stored status until this page changes. --}}
@php($orderProgress = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::orderProgress($domain))
@if ($orderProgress['pending'])
<script data-domain-order-poll>
(() => {
    const script = document.currentScript;
    const state = @json($orderProgress['state']);
    const url = @json($domain->actions['show'].'/order-status');
    const poll = async () => {
        if (!script.isConnected) return;
        try {
            const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) return;
            if (response.ok) {
                const current = await response.json();
                if (!script.isConnected) return;
                if (typeof current.state !== 'string' || typeof current.pending !== 'boolean') throw new Error('Invalid order status');
                if (current.state !== state || !current.pending) { window.location.reload(); return; }
            }
        } catch (_) { /* Retry local status after a temporary network failure. */ }
        window.setTimeout(poll, 10000);
    };
    window.setTimeout(poll, 10000);
})();
</script>
@endif
