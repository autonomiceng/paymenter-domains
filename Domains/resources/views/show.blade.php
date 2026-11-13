@component('layouts.app', ['title' => $domain->name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@php
    $support = ($summary ?? null)?->urls['support'] ?? \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl();
    $notice = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($domain, $support, $summary ?? null);
    $pill = \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::owned($domain, $summary ?? null);
    // The status reason is said once: the page notice already gives it, so the line under the status would repeat it.
    if ($pill && $notice && filled($pill['reason'] ?? null) && str_contains(strtolower($notice['text']), strtolower(rtrim($pill['reason'], '.')))) { $pill['reason'] = null; }
    $state = $pill ? \Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::labelled($pill['label'], $pill['reason'] ?? null) : null;
    $settings = array_values(array_filter(['flags', 'lock', 'privacy', 'auth_code_email', 'contacts', 'nameservers', 'dns', 'srv', 'glue', 'ds'], fn ($cap) => ($cap !== 'privacy' || \Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) && (isset($domain->management[$cap]) || ($cap === 'nameservers' && $domain->syncPaused))
        // Domain settings only says the settings were read. Once the read is full, the rows below show each one.
        && !($cap === 'flags' && ($domain->management['flags']['family']['state'] ?? null) === 'complete' && ($domain->management['flags']['family_only'] ?? false))));
@endphp
<x-domains::customer.head :title="$domain->name" :domain="true" :demo="$domain->demo" :test="$domain->testEnvironment" :crumbs="[['Domains', route('domains.domains.index')], [$domain->name, null]]" :state="$state" />
@include('domains::partials.notices', ['fieldKeys'=>['nameservers','contact','dns','srv','ds','glue']])
@if ($notice)<x-domains::customer.notice :tone="$notice['tone']" :action="$notice['action']">{{ $notice['text'] }}</x-domains::customer.notice>@endif
@if (($summary ?? null) && $summary->kind === 'managed' && !$summary->relatedServices && !$summary->relatedOverflow && $domain->expiry && !$domain->setupPending && in_array('domain.dns.platform', $summary->dnsKeys, true) && !$summary->website && !$summary->email)
<p class="domains-muted">To use this domain, buy a service or point it to your own nameservers.</p>
@endif
<x-domains::customer.split>
{{-- Renewal leads the settings section, as in the approved customer layout. --}}
@include('domains::partials.owner-renew')
@if ($settings)
{{-- When reads are stopped, the page notice already says we can't read this domain and are on it. --}}
@php
    $readHeld = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::readHeldSaid($notice);
    $unreadRows = ($recordFamilies ?? null) && collect($recordFamilies['rows'] ?? [])->contains('state', 'unknown');
    $readAt = isset($recordFamilies) ? ($recordFamilies['at'] ?? null) : null;
    // Nothing read yet and nothing to change: the note in the head says it once, instead of a Not read line per record.
    $allUnread = ($recordFamilies ?? null) && collect($settings)->every(function ($cap) use ($domain) {
        $section = $domain->management[$cap] ?? [];
        return ($section['family']['state'] ?? null) === 'unknown'
            && empty($section['actions']) && empty($section['current'])
            && empty($section['inventory']) && empty($section['caa'])
            && ($section['records'] ?? null) === null
            && !($section['progress'] ?? null) && !($section['off'] ?? false);
    });
@endphp
<x-domains::customer.section id="settings" :title="($recordFamilies ?? null) ? 'Registrar records' : 'Settings'">
<x-slot:head>@if (($recordFamilies ?? null) && $recordFamilies['stale'] && !($recordFamilies['initial_read_pending'] ?? false) && !$readHeld && !$unreadRows)<p class="domains-hint">Registrar records are waiting for an update.@if ($recordFamilies['retained']) Last confirmed data is retained.@endif</p>@endif
@if (($recordFamilies ?? null) && !$readHeld)@include('domains::partials.record-read-note', ['recordFamilies' => $recordFamilies])@endif
@if ($readAt)<p class="domains-hint">Checked @include('domains::partials.moment', ['at' => $readAt]).</p>@endif</x-slot:head>
@unless ($allUnread)<ul class="domains-rows">@include('domains::partials.owner-management', ['caps' => $settings])</ul>@endunless
</x-domains::customer.section>
@endif
<x-slot:rail>@unless ($domain->setupPending)@include('domains::partials.activity')@endunless</x-slot:rail>
</x-domains::customer.split>
@include('domains::partials.client-script')
@include('domains::partials.management-poll')
@include('domains::partials.order-poll')
</div></div>
@endcomponent
