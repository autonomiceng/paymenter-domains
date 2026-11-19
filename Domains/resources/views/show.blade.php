@component('layouts.app', ['title' => $domain->name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@php
    $support = ($summary ?? null)?->urls['support'] ?? \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl();
    // A departed page has no notice: its head says the departure with its saved reason.
    $notice = ($departed ?? null) ? null : \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($domain, $support, $summary ?? null);
    $pill = \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::owned($domain, $summary ?? null);
    // The status reason is said once: the page notice already gives it, so the line under the status would repeat it.
    if ($pill && $notice && filled($pill['reason'] ?? null) && str_contains(strtolower($notice['text']), strtolower(rtrim($pill['reason'], '.')))) { $pill['reason'] = null; }
    // Work that is ours (a cyan or green head) is said once, by the notice; an amber or red head keeps its reason (P-STA-11).
    if ($pill && $notice && !in_array($pill['tone'] ?? null, ['warn', 'bad'], true)) { $pill['reason'] = null; }
    $state = $pill ? \Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::labelled($pill['label'], $pill['reason'] ?? null) : null;
    $authCode = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::authCode($domain, $support);
    $settings = array_values(array_filter(['flags', 'lock', 'privacy', 'auth_code_email', 'contacts', 'nameservers', 'dns', 'ds', 'srv', 'glue'], fn ($cap) => ($cap !== 'privacy' || \Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) && (isset($domain->management[$cap]) || ($cap === 'nameservers' && $domain->syncPaused))
        // Domain settings only says the settings were read. Once the read is full, the rows below show each one.
        && !($cap === 'flags' && ($domain->management['flags']['family']['state'] ?? null) === 'complete' && ($domain->management['flags']['family_only'] ?? false))));
@endphp
@if ($departed ?? null)
{{-- A confirmed departure (mocks 02, 02b, 02c): read-only, with the way back as the one action. --}}
<x-domains::customer.head :title="$domain->name" :demo="$domain->demo" :test="$domain->testEnvironment" :crumbs="[['Domains', route('domains.domains.index')], [$domain->name, null]]" :state="$state">
<x-slot:actions><a class="domains-btn domains-btn--secondary" href="{{ $departed['back'][1] }}">{{ $departed['back'][0] }}</a></x-slot:actions>
</x-domains::customer.head>
@if (\Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl())<p class="domains-hint">@include('domains::partials.contact-support', ['before' => $departed['support'], 'after' => '.'])</p>@endif
<x-domains::customer.split>
<x-domains::customer.section id="registration" title="Registration" :note="$departed['note']">
<x-domains::customer.rows>@foreach ($departed['rows'] as $row)
<x-domains::customer.row :label="$row['label']"><span class="domains-card__v">{{ $row['value'] }}</span>@if ($row['note'] ?? null) <span class="domains-sub">{{ $row['note'] }}</span>@endif
<x-slot:actions>@if ($row['link'] ?? null)<a class="domains-a" href="{{ $row['link'][1] }}">{{ $row['link'][0] }}</a>@endif</x-slot:actions></x-domains::customer.row>
@endforeach</x-domains::customer.rows>
</x-domains::customer.section>
<x-slot:rail>@include('domains::partials.activity')</x-slot:rail>
</x-domains::customer.split>
@include('domains::partials.client-script')
@else
<x-domains::customer.head :title="$domain->name" :demo="$domain->demo" :test="$domain->testEnvironment" :crumbs="[['Domains', route('domains.domains.index')], [$domain->name, null]]" :state="$state" />
@if ($domain->departureSuspected)<p class="domains-hint">Didn't move this domain? @include('domains::partials.contact-support')</p>@endif
@include('domains::partials.notices', ['fieldKeys'=>['nameservers','contact','dns','srv','ds','glue']])
@if ($notice)<x-domains::customer.notice :tone="$notice['tone']" :action="$notice['action']">{{ $notice['text'] }}</x-domains::customer.notice>@endif
@if (($summary ?? null) && $summary->kind === 'managed' && !$summary->relatedServices && !$summary->relatedOverflow && $domain->expiry && !$domain->setupPending && in_array('domain.dns.platform', $summary->dnsKeys, true) && !$summary->website && !$summary->email)
<p class="domains-muted">To use this domain, buy a service or point it to your own nameservers.</p>
@endif
<x-domains::customer.split>
{{-- Renewal leads the settings section, as in the approved customer layout. --}}
@include('domains::partials.owner-renew')
@if ($settings || $authCode)
{{-- When reads are stopped, the page notice already says we can't read this domain and are on it. --}}
@php
    $readHeld = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::readHeldSaid($notice);
    $unreadRows = ($recordFamilies ?? null) && collect($recordFamilies['rows'] ?? [])->contains('state', 'unknown');
    $readAt = isset($recordFamilies) ? ($recordFamilies['at'] ?? null) : null;
    // If every record is waiting on its first read, the section note gives that state once.
    $allUnread = !$readHeld && ($recordFamilies ?? null) && collect($settings)->every(function ($cap) use ($domain) {
        $section = $domain->management[$cap] ?? [];
        return ($section['family']['state'] ?? null) === 'unknown'
            && empty($section['family']['read_failed']) && empty($section['actions']) && empty($section['current'])
            && empty($section['inventory']) && empty($section['caa']) && ($section['records'] ?? null) === null
            && empty($section['progress']) && empty($section['off']);
    });
    $dns = array_intersect($settings, ['nameservers', 'dns', 'ds', 'srv', 'glue']);
    $ownership = array_diff($settings, $dns);
    $groups = [];
    if ($ownership || $authCode) { $groups['Ownership and transfer'] = $ownership; }
    if ($dns) { $groups['DNS'] = $dns; }

    // Fold only quiet, empty rows. A supported per-host action, retained data or a failed read stays visible.
    $advanced = array_intersect($dns, ['srv', 'glue']);
    $foldAdvanced = count($advanced) === 2 && !$domain->syncPaused && collect($advanced)->every(function ($cap) use ($domain) {
        $section = $domain->management[$cap];
        $family = $section['family'] ?? [];
        $knownEmpty = ($family['state'] ?? null) === 'complete' && !($family['limited'] ?? false);
        return $knownEmpty && ($family['count'] ?? null) === 0 && empty($family['hosts'])
            && empty($section['records']) && empty($section['fixed']) && empty($section['actions'])
            && empty($section['progress']) && empty($section['waiting']) && empty($section['warnings'])
            && empty($family['read_failed']) && empty($family['read_checking']) && empty($family['initial_read_pending']);
    });
@endphp
@foreach ($groups as $title => $caps)
@continue($allUnread && !$loop->first)
@php($fold = $title === 'DNS' && $foldAdvanced)
<x-domains::customer.section :id="$loop->first ? 'settings' : 'dns-settings'" :title="$title">
<x-slot:head>
@if ($loop->first)
@if (($recordFamilies ?? null) && $recordFamilies['stale'] && !($recordFamilies['initial_read_pending'] ?? false) && !$readHeld && !$unreadRows)<p class="domains-hint">Registrar records are waiting for an update.@if ($recordFamilies['retained']) Last confirmed data is retained.@endif</p>@endif
@if (($recordFamilies ?? null) && !$readHeld)@include('domains::partials.record-read-note', ['recordFamilies' => $recordFamilies])@endif
@if ($readAt)<p class="domains-hint">Checked @include('domains::partials.moment', ['at' => $readAt, 'dateOnly' => true]).</p>@endif
@endif
</x-slot:head>
@if ($title === 'Ownership and transfer' && $authCode)
<x-domains::customer.rows>
<x-domains::customer.row label="Auth code">
<x-slot:help>@include('domains::partials.help', ['id' => 'transfer-auth-code', 'label' => 'Auth code', 'helpText' => 'The code from your current registrar. We need it to move this domain to us.'])</x-slot:help>
@if ($authCode['missing'])<x-domains::customer.unread :fix="$authCode['fix']" />@else<span class="domains-card__v">Saved</span> <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $authCode['action']['url'] }}">{{ $authCode['action']['label'] }}</a></span>@endif
</x-domains::customer.row>
</x-domains::customer.rows>
@endif
@if ($caps && !$allUnread)
<ul class="domains-rows">
@include('domains::partials.owner-management', ['caps' => $fold ? array_diff($caps, $advanced) : $caps])
@if ($fold)
<li>
<details class="domains-disclosure" data-record-disclosure>
<summary>Advanced DNS<span class="domains-sub">SRV and glue records</span></summary>
<ul class="domains-rows">@include('domains::partials.owner-management', ['caps' => $advanced])</ul>
</details>
</li>
@endif
</ul>
@endif
</x-domains::customer.section>
@endforeach
@endif
<x-slot:rail>@unless ($domain->setupPending)@include('domains::partials.activity')@endunless</x-slot:rail>
</x-domains::customer.split>
@include('domains::partials.client-script')
@include('domains::partials.management-poll')
@include('domains::partials.order-poll')
@endif
</div></div>
@endcomponent
