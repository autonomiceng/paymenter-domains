{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The head of a domain store page (prices, register, transfer): the same customer
     head as every other page (DESIGN.md P-LAY-12), with "Domains >" crumbs when the visitor has a domain list to go back to,
     then one lede line. Register and Transfer share one title, "Get a domain", and their tab row (P-LAY-11). --}}
@php($listed = auth()->check() && \Paymenter\Extensions\Servers\Domains\Http\DomainList::reachable() && \Illuminate\Support\Facades\Route::has('domains.domains.index'))
<div class="domains-store-head">
<x-domains::customer.head :title="$title" :crumbs="$listed ? [['Domains', route('domains.domains.index')], [$title, null]] : []" />
@if ($lede ?? null)<p class="domains-lede">{{ $lede }}</p>@endif
@isset($tabs)@include('domains::partials.acquire-tabs', ['current' => $tabs])@endisset
</div>
