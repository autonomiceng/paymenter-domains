@component('layouts.app', ['title' => $domain->name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@include('domains::partials.test-banner', ['test' => $domain->testEnvironment])
<a class="domains-back" href="{{ route('domains.domains.index') }}">Domains</a>
<div class="domains-dhead"><div><h1 class="domains-title domains-record-value" tabindex="0">{{ $domain->name }}</h1><div class="domains-dhead__meta">@if ($domain->registrationBadge)<span class="domains-badge">{{ $domain->registrationBadge }}</span>@endif<span class="domains-badge domains-tone-{{ $domain->tone }}">{{ isset($summary)?($summary->labels[$summary->stateKeys[0]??'']??$domain->status):$domain->status }}</span>@if ($domain->expiry)<span>Expires <strong>@include('domains::partials.date', ['date' => $domain->expiry])</strong></span>@endif</div></div></div>
@isset($summary)<section class="domains-panel domains-section" aria-label="Registration and DNS">@include('domains::partials.summary',['summaryFinancials'=>false,'summaryOnManaged'=>true])</section>@endisset
@include('domains::partials.notices', ['fieldKeys'=>['nameservers','contact','dns','srv','ds','glue']])
@if ($domain->setupPending)<div class="domains-notice" role="status">Our team will confirm this domain’s registration. Renewals and changes are paused until then. If your domain expires soon or you need a change, contact support.</div>@endif
@include('domains::partials.owner-auto-renew')
@if ($domain->changesUnavailable)<div class="domains-notice" role="status">You can’t change this domain’s settings right now. Try again later, or contact support if this continues.</div>@endif
@if (isset($domain->actions['setup']))
@if (($domain->transfer['phase'] ?? null) === 'cutoff' && $domain->setup['contacts'])
<section class="domains-panel domains-section"><h2 class="domains-h2">Update your details</h2><p>The code-entry deadline has passed. You can update your account details for our team to review while this transfer remains on hold.</p><a class="domains-btn domains-btn--primary" href="{{ $domain->actions['setup'] }}">Update details for review</a></section>
@else
<section class="domains-panel domains-section"><h2 class="domains-h2">{{ $domain->setup['defaults'] && !$domain->setup['contacts'] ? 'Choose your nameservers' : 'Complete your domain setup' }}</h2><p>{{ $domain->setup['defaults'] && !$domain->setup['contacts'] ? 'Optional. If you don’t choose, we use the default nameservers. You can change them later.' : 'Confirm the details for your domain before we continue.' }}</p><a class="domains-btn domains-btn--primary" href="{{ $domain->actions['setup'] }}">{{ $domain->setup['defaults'] && !$domain->setup['contacts'] ? 'Choose nameservers' : 'Continue setup' }}</a></section>
@endif
@endif
@if (isset($domain->actions['code']))<section class="domains-panel domains-section"><h2 class="domains-h2">Transfer code</h2><p>{{ $domain->transfer['received'] ? 'Code saved.' : (!$domain->transfer['open'] ? 'See your transfer’s status.' : 'Your current registrar gives you this code. It’s also called an auth code or EPP code.') }}</p><a class="domains-btn domains-btn--secondary" href="{{ $domain->actions['code'] }}">{{ (!$domain->transfer['open'] || $domain->transfer['received']) ? 'View transfer' : 'Enter transfer code' }}</a></section>@endif
@if ($domain->management)
<nav class="domains-tabs" aria-label="Domain settings"><a class="domains-tab" href="#overview">Overview</a>@foreach ($domain->management as $cap => $section)<a class="domains-tab" href="#{{ $cap }}">{{ $section['label'] }}</a>@endforeach<a class="domains-tab" href="#domains-activity">Activity</a></nav>
@endif
<section id="overview" class="domains-panel domains-section"><h2 class="domains-h2">Overview</h2><dl class="domains-facts"><div class="domains-fact"><dt>Auto-renew</dt><dd>{{ $domain->setupPending?'Paused until verification':$domain->renewal }}</dd></div>@if ($domain->renewalPrice && $domain->renewal !== 'Off' && !$domain->setupPending)<div class="domains-fact"><dt>Renewal price</dt><dd>{{ $domain->renewalPrice }}/yr</dd></div>@endif</dl></section>
@if ($domain->renewalInvoice)<section class="domains-panel domains-section">
@if ($domain->renewalInvoice['state'] === 'pending')<h2 class="domains-h2">Renewal invoice issued</h2><p>Payment due @include('domains::partials.date', ['date' => $domain->renewalInvoice['due']]). See your invoice for payment details.</p>@isset($domain->renewNow['invoice'])<a class="domains-btn domains-btn--primary" href="{{ $domain->renewNow['invoice'] }}">View renewal invoice</a>@endisset
@elseif ($domain->renewalInvoice['state'] === 'paid')<h2 class="domains-h2">Renewal payment received</h2><p>Renewal is awaiting confirmation. The confirmed expiry above will update when it completes.</p>
@elseif ($domain->renewalInvoice['state'] === 'cancelled')<h2 class="domains-h2">Renewal invoice cancelled</h2><p>{{ \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerRenewalController::CANCELLED }}</p>
@else<h2 class="domains-h2">Renewal under review</h2><p>Contact support if you need an update on this renewal.</p>@endif</section>
@elseif ($domain->plannedRenewalDue)<section class="domains-panel domains-section"><h2 class="domains-h2">Next renewal</h2><p>Renewal due @include('domains::partials.date', ['date' => $domain->plannedRenewalDue]). An invoice will be issued when billing checks pass.</p></section>@endif
@if ($domain->history)<section class="domains-panel domains-section"><h2 class="domains-h2">Historical invoices</h2><ul>@foreach ($domain->history as $invoice)<li><a href="{{ $invoice['url'] }}">Invoice {{ $invoice['number'] }}</a> · {{ $invoice['status'] }}</li>@endforeach</ul></section>@endif
@include('domains::partials.owner-renew')
@include('domains::partials.owner-management')
@unless ($domain->setupPending)
@include('domains::partials.activity')
@endunless
@include('domains::partials.client-script')
</div></div>
@endcomponent
