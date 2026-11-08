@component('layouts.app', ['title' => $domain->name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@php
    $support = ($summary ?? null)?->urls['support'] ?? \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl();
    $notice = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($domain, $support, $summary ?? null);
    $pill = \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::owned($domain, $summary ?? null);
    $settings = array_values(array_filter(['flags', 'lock', 'privacy', 'auth_code_email', 'contacts', 'nameservers', 'dns', 'srv', 'glue', 'ds'], fn ($cap) => ($cap !== 'privacy' || \Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) && (isset($domain->management[$cap]) || ($cap === 'nameservers' && $domain->syncPaused))
        // Domain settings only says the settings were read. Once the read is full, the rows below show each one.
        && !($cap === 'flags' && ($domain->management['flags']['family']['state'] ?? null) === 'complete' && ($domain->management['flags']['family_only'] ?? false))));
@endphp
<nav class="domains-crumb" aria-label="Breadcrumb"><a href="{{ route('domains.domains.index') }}">Domains</a> / {{ $domain->name }}</nav>
<div class="domains-ph"><h1 class="domains-mono">{{ $domain->name }}{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}</h1>@if ($pill)<span class="domains-pill">@include('domains::partials.status-pill', ['pill' => $pill])</span>@endif</div>
@include('domains::partials.notices', ['fieldKeys'=>['nameservers','contact','dns','srv','ds','glue']])
@if ($notice)
<div class="domains-notice domains-notice--{{ $notice['tone'] }}" role="status"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4M12 16h.01"/><circle cx="12" cy="12" r="10"/></svg><span class="domains-notice__tx">{{ $notice['text'] }}</span>@if ($notice['action']) <a class="{{ ($notice['action']['button'] ?? false) ? 'domains-btn domains-btn--primary domains-btn--sm' : 'domains-a' }}" href="{{ $notice['action']['url'] }}">{{ $notice['action']['label'] }}</a>@endif</div>
@endif
@if (($summary ?? null) && $summary->kind === 'managed' && !$summary->relatedServices && !$summary->relatedOverflow && $domain->expiry && !$domain->setupPending && in_array('domain.dns.platform', $summary->dnsKeys, true) && !$summary->website && !$summary->email)
<p class="domains-muted">To use this domain, buy a service or point it to your own nameservers.</p>
@endif
<div class="domains-cols"><div class="domains-sheet">
{{-- Like the approved mock, registrar records lead when there are any; Renewal follows. --}}
@unless ($settings && ($recordFamilies ?? null))
@include('domains::partials.owner-renew')
@endunless
@if ($settings)
<section class="domains-panel" aria-labelledby="heading-settings"><div class="domains-panel__h"><h2 id="heading-settings" class="domains-h2">{{ ($recordFamilies ?? null) ? 'Registrar records' : 'Settings' }}</h2>
{{-- When reads are stopped, the page notice already says we can't read this domain and are on it. --}}
@php $readHeld = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::readHeldSaid($notice); @endphp
@if (($recordFamilies ?? null) && $recordFamilies['stale'] && !($recordFamilies['initial_read_pending'] ?? false) && !$readHeld)<p class="domains-hint">Registrar records are waiting for an update.@if ($recordFamilies['retained']) Last confirmed data is retained.@endif</p>@endif
@if (($recordFamilies ?? null) && !$readHeld)@include('domains::partials.record-read-note', ['recordFamilies' => $recordFamilies])@endif
@php $readAt = isset($recordFamilies) ? ($recordFamilies['at'] ?? null) : null; @endphp
@if ($readAt)<p class="domains-hint">Checked @include('domains::partials.moment', ['at' => $readAt]).</p>@endif
</div>
<ul class="domains-rows">@include('domains::partials.owner-management', ['caps' => $settings])</ul></section>
@endif
@if ($settings && ($recordFamilies ?? null))
@include('domains::partials.owner-renew')
@endif
</div>
@unless ($domain->setupPending)
<aside class="domains-rail">@include('domains::partials.activity')</aside>
@endunless
</div>
@include('domains::partials.client-script')
@include('domains::partials.management-poll')
@include('domains::partials.order-poll')
</div></div>
@endcomponent
