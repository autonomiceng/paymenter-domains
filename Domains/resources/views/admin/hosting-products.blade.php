{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Monitoring settings > Hosting products: the summary row, and the Choose products checklist (a native modal dialog, so the page behind it is inert). Staff store only the products they turn off. --}}
@php
    $products = $lines['products']; $unlisted = $lines['unlisted'];
    $names = fn (array $n) => count($n) <= 3 ? implode(', ', $n) : implode(', ', array_slice($n, 0, 3)).' and '.(count($n) - 3).' more';
    $on = array_values(array_filter($products, fn ($p) => $p['on'])); $off = array_column(array_filter($products, fn ($p) => ! $p['on']), 'name');
    $count = $active === null ? null : array_sum(array_map(fn ($p) => $active[$p['id']] ?? 0, $on));
    $notListed = $unlisted === [] ? null : 'Not listed: '.$names($unlisted).'. '.(count($unlisted) === 1 ? 'Its' : 'Their').' orders carry no domain.';
    $pick = array_map(fn ($p) => $p + ['active' => $active[$p['id']] ?? 0], $products);
@endphp
@if ($products === [] && $unlisted === [])
    <div class="domains-panel-row domains-hp-empty" data-domains-row="notice"><div class="domains-panel-row-v">No hosting products yet. New ones are monitored automatically.</div>
    <div class="domains-panel-row-a">@if ($add)<x-filament::link :href="$add">Add a product</x-filament::link>@endif</div></div>
@else
<div class="domains-panel-row domains-hp" data-domains-row="setting" id="hosting-products-row" wire:key="hosting-products-{{ md5(json_encode([$pick, $unlisted, $active !== null])) }}" @if ($active === null && $products !== []) data-domains-actions="2" @endif
    x-data="domainsHostingProducts(@js($pick), @js($unlisted), @js($active !== null))">
    <div class="domains-panel-row-k">Monitored</div>
    <div class="domains-panel-row-v"><span class="domains-hp-sum">
        <span>{{ $products === [] ? 'None' : ($off === [] ? 'All hosting products' : count($on).' of '.count($products).' hosting products') }}</span>
        @if ($products !== [] && $count !== null)<span class="domains-panel-sub">{{ number_format($count) }} active {{ $count === 1 ? 'service' : 'services' }}</span>
        @elseif ($products !== [])<span class="domains-panel-st domains-hp-err">Couldn't read</span>@endif
    </span>
    @if ($products !== [] && $count === null)<span class="domains-panel-sub domains-hp-line" id="hosting-products-why">Active services couldn't be counted; the read failed.</span>@endif
    @if ($off !== [])<span class="domains-panel-sub domains-hp-line">Off: {{ $names($off) }}.</span>@endif
    @if ($notListed)<span class="domains-panel-sub domains-hp-line">{{ $notListed }}</span>@endif
    </div>
    <div class="domains-panel-row-a">
        @if ($products !== [] && $can)<x-filament::button color="gray" aria-haspopup="dialog" x-on:click="open()">Choose products</x-filament::button>@endif
        @if ($products !== [] && $count === null)<x-filament::button color="gray" wire:click="$refresh" aria-describedby="hosting-products-why">Try again</x-filament::button>@endif
    </div>
    @if ($products !== [] && $can)
    <dialog class="domains-hp-dlg" x-ref="dlg" aria-labelledby="hp-dlg-t" x-on:close="query = ''">
        <div class="domains-hp-dlg-h"><h2 id="hp-dlg-t">Choose products</h2><x-filament::icon-button icon="heroicon-m-x-mark" color="gray" label="Close" x-on:click="$refs.dlg.close()" /></div>
        <div class="domains-hp-search"><label class="sr-only" for="hp-q">Search products</label>
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input id="hp-q" type="search" placeholder="Search products" autocomplete="off" aria-controls="hp-list" x-model="query" />
                <x-slot name="suffix"><button type="button" class="domains-hp-clr" aria-label="Clear search" aria-controls="hp-q" x-show="query" x-on:click="query = ''; $nextTick(() => document.getElementById('hp-q').focus())"><x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" /></button></x-slot></x-filament::input.wrapper>
            <p class="sr-only" role="status" x-text="query ? `${shown().length} of ${items.length} products match.` + (unlistedShown().length ? ` ${unlistedShown().length} not listed.` : '') : ''"></p></div>
        <div class="domains-hp-all" x-bind:class="{ 'domains-hp-solo': !shown().length }">
            <label class="domains-hp-ck" x-show="shown().length"><x-filament::input.checkbox x-ref="all" aria-controls="hp-list" x-on:change="selectAll($event.target.checked)" x-effect="syncAll()" /><span x-text="query ? 'Select all shown' : 'Select all'"></span></label>
            <p class="domains-panel-sub" role="status" aria-live="polite" x-text="`${checkedCount()} of ${items.length} monitored`"></p></div>
        <fieldset id="hp-list" class="domains-hp-list" x-bind:class="{ 'domains-hp-searching': query }"><legend class="sr-only">Monitored hosting products</legend>
            <template x-for="item in shown()" :key="item.id"><label class="domains-hp-ck"><x-filament::input.checkbox x-model="item.on" />
                <span class="domains-hp-nm" x-html="mark(item.name)"></span><span class="domains-panel-st domains-hp-hidden" x-show="item.hidden">Not for sale</span></label></template>
            <template x-for="name in unlistedShown()" :key="name"><div class="domains-hp-ck domains-hp-nl"><span class="domains-hp-gap" aria-hidden="true"></span><span><span class="domains-hp-nm" x-html="mark(name)"></span> <span class="domains-panel-sub">&mdash; not listed: its orders carry no domain</span></span></div></template>
            <p class="domains-hp-none" x-show="query && !shown().length && !unlistedShown().length">No products match "<span x-text="query"></span>". <x-filament::link tag="button" x-on:click="query = ''">Clear search</x-filament::link></p>
        </fieldset>
        <p class="domains-hp-eff" id="hp-eff" x-text="effect()"></p>
        <p class="domains-panel-sub">New hosting products are monitored automatically.</p>
        <div class="domains-hp-err-line" role="alert" x-show="error"><span class="domains-panel-st domains-hp-err">Couldn't save</span> <span class="domains-panel-sub" x-text="error"></span></div>
        <div class="domains-hp-foot"><x-filament::button color="gray" x-on:click="$refs.dlg.close()">Cancel</x-filament::button>
            <x-filament::button aria-describedby="hp-eff" x-bind:aria-disabled="!changed() || saving" x-on:click="save()"><span x-text="error ? 'Try again' : 'Save'"></span></x-filament::button></div>
    </dialog>
    @endif
</div>
@if ($products === [])<div class="domains-panel-row domains-hp-empty" data-domains-row="notice"><div class="domains-panel-row-v">New hosting products with a domain are monitored automatically.</div></div>@endif
@endif
@once
<script>
function domainsHostingProducts(products, unlisted, counted) {
    const list = (n) => n.length <= 3 ? n.join(', ') : n.slice(0, 3).join(', ') + ' and ' + (n.length - 3) + ' more';
    const esc = (t) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    return {
        items: products.map((p) => ({ ...p })), unlisted, query: '', error: null, saving: false,
        open() { this.items = products.map((p) => ({ ...p })); this.error = null; this.$refs.dlg.showModal(); },
        match(name) { return name.toLowerCase().includes(this.query.trim().toLowerCase()); },
        shown() { return this.items.filter((i) => this.match(i.name)); },
        unlistedShown() { return this.query.trim() ? this.unlisted.filter((n) => this.match(n)) : []; },
        mark(name) { const q = this.query.trim(), at = q ? name.toLowerCase().indexOf(q.toLowerCase()) : -1;
            return at < 0 ? esc(name) : esc(name.slice(0, at)) + '<b>' + esc(name.slice(at, at + q.length)) + '</b>' + esc(name.slice(at + q.length)); },
        checkedCount() { return this.items.filter((i) => i.on).length; },
        selectAll(on) { this.shown().forEach((i) => { i.on = on; }); },
        syncAll() { const s = this.shown(), n = s.filter((i) => i.on).length; if (!this.$refs.all) return; this.$refs.all.checked = s.length > 0 && n === s.length; this.$refs.all.indeterminate = n > 0 && n < s.length; },
        changed() { return this.items.some((i, k) => i.on !== products[k].on); },
        effect() { const off = this.items.filter((i) => !i.on); if (!off.length) return '';
            const turning = off.filter((i) => products.find((p) => p.id === i.id).on), n = turning.reduce((t, i) => t + i.active, 0);
            const stop = counted && turning.length ? ` ${off.length === 1 ? 'Its' : 'Their'} ${n.toLocaleString()} active ${n === 1 ? 'service stops' : 'services stop'} being monitored, unless another monitored product has their domain.` : '';
            return `Off after saving: ${list(off.map((i) => i.name))}.${stop}`; },
        async save() { if (!this.changed() || this.saving) return; this.saving = true;
            try { this.error = await this.$wire.saveMonitored(this.items.filter((i) => i.on).map((i) => i.id), products.filter((p) => !p.on).map((p) => p.id)); if (!this.error) this.$refs.dlg.close(); }
            catch (e) { this.error = 'The save didn\'t finish. Your choices are kept; nothing changed yet.'; }
            finally { this.saving = false; } },
    };
}
</script>
@endonce
