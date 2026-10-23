@component('layouts.app', ['title' => $domain->name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@php
    $support = ($summary ?? null)?->urls['support'] ?? (\Illuminate\Support\Facades\Route::has('tickets.create') ? route('tickets.create') : null);
    $notice = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($domain, $support, $summary ?? null);
    $pill = \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::owned($domain, $summary ?? null);
    $groups = ['DNS' => ['nameservers', 'dns', 'srv', 'glue', 'ds'], 'Security and contacts' => ['lock', 'privacy', 'contacts', 'auth_code_email']];
@endphp
<nav class="domains-crumb" aria-label="Breadcrumb"><a href="{{ route('domains.domains.index') }}">Domains</a> / {{ $domain->name }}</nav>
<div class="domains-ph"><h1 class="domains-mono">{{ $domain->name }}{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}</h1>@if ($pill)<span class="domains-pill">@include('domains::partials.status-pill', ['pill' => $pill])</span>@endif</div>
@include('domains::partials.notices', ['fieldKeys'=>['nameservers','contact','dns','srv','ds','glue']])
@if ($notice)
<div class="domains-notice domains-notice--{{ $notice['tone'] }}" role="status"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4M12 16h.01"/><circle cx="12" cy="12" r="10"/></svg><span class="domains-notice__tx">{{ $notice['text'] }}</span>@if ($notice['action']) <a class="domains-a" href="{{ $notice['action']['url'] }}">{{ $notice['action']['label'] }}</a>@endif</div>
@endif
<div class="domains-cols"><div>
@foreach ($groups as $heading => $caps)
@php($caps = array_values(array_filter($caps, fn ($cap) => isset($domain->management[$cap]) || ($cap === 'nameservers' && $domain->syncPaused))))
@if ($caps)
<section class="domains-panel" aria-labelledby="heading-{{ \Illuminate\Support\Str::slug($heading) }}"><div class="domains-panel__h"><h2 id="heading-{{ \Illuminate\Support\Str::slug($heading) }}" class="domains-h2">{{ $heading }}</h2></div>
<ul class="domains-rows">@include('domains::partials.owner-management', ['caps' => $caps])</ul></section>
@endif
@endforeach
@unless ($domain->setupPending)
@include('domains::partials.activity')
@endunless
</div><aside>
@include('domains::partials.owner-renew')
</aside></div>
@include('domains::partials.client-script')
</div></div>
@endcomponent
