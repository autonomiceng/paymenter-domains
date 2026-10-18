@component('layouts.app', ['title' => $domain->name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@include('domains::partials.test-banner', ['test' => $domain->testEnvironment])
<a class="domains-back" href="{{ route('domains.domains.index') }}">Domains</a>
<div class="domains-dhead"><div><h1 class="domains-title">{{ $domain->name }}</h1><div class="domains-dhead__meta"><span class="domains-badge domains-tone-{{ $domain->tone }}">{{ $domain->status }}</span>@if ($domain->expiry)<span>Expires <strong>@include('domains::partials.date', ['date' => $domain->expiry])</strong></span>@endif</div></div></div>
@include('domains::partials.notices', ['fieldKeys'=>['nameservers','contact','dns','srv','ds','glue']])
@if ($domain->changesUnavailable)<div class="domains-notice" role="status">You can’t change this domain’s settings right now. Try again later, or contact support if this continues.</div>@endif
@if (isset($domain->actions['setup']))<section class="domains-panel domains-section"><h2 class="domains-h2">{{ $domain->setup['defaults'] && !$domain->setup['contacts'] ? 'Choose your nameservers' : 'Complete your domain setup' }}</h2><p>{{ $domain->setup['defaults'] && !$domain->setup['contacts'] ? 'Optional. If you don’t choose, we use the default nameservers. You can change them later.' : 'Confirm the details for your domain before we continue.' }}</p><a class="domains-btn domains-btn--primary" href="{{ $domain->actions['setup'] }}">{{ $domain->setup['defaults'] && !$domain->setup['contacts'] ? 'Choose nameservers' : 'Continue setup' }}</a></section>@endif
@if (isset($domain->actions['code']))<section class="domains-panel domains-section"><h2 class="domains-h2">Transfer code</h2><p>{{ $domain->transfer['received'] ? 'Code saved.' : (!$domain->transfer['open'] ? 'See your transfer’s status.' : 'Your current registrar gives you this code. It’s also called an auth code or EPP code.') }}</p><a class="domains-btn domains-btn--secondary" href="{{ $domain->actions['code'] }}">{{ (!$domain->transfer['open'] || $domain->transfer['received']) ? 'View transfer' : 'Enter transfer code' }}</a></section>@endif
@if ($domain->management)
<nav class="domains-tabs" aria-label="Domain settings"><a class="domains-tab" href="#overview">Overview</a>@foreach ($domain->management as $cap => $section)<a class="domains-tab" href="#{{ $cap }}">{{ $section['label'] }}</a>@endforeach<a class="domains-tab" href="#domains-activity">Activity</a></nav>
@endif
<section id="overview" class="domains-panel domains-section"><h2 class="domains-h2">Overview</h2><dl class="domains-facts"><div class="domains-fact"><dt>Auto-renew</dt><dd>{{ $domain->renewal }}</dd></div>@if ($domain->renewalPrice)<div class="domains-fact"><dt>Renewal price</dt><dd>{{ $domain->renewalPrice }}/yr</dd></div>@endif</dl></section>
@include('domains::partials.owner-management')
@include('domains::partials.activity')
@include('domains::partials.client-script')
</div></div>
@endcomponent
