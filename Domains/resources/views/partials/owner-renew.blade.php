{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The Renewal section: expiry, renewal setting, Auto Pay, price, next invoice, auth code and Renew now. --}}
@php
    $page = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::class;
    $expires = $page::expires($domain, $summary ?? null, $support);
    $invoice = $page::nextInvoice($domain, $summary ?? null, $support);
    $authCode = $page::authCode($domain, $support);
    $show = $domain->actions['show'];
    $renewOpen = request()->query('edit') === 'renew' || session('domains_failed_form') === ($domain->renewNow['url'] ?? false);
    // A domain that ended without a registration (closed unpaid, or cancelled and credited) has no date: the row is left out, not None.
    $showExpires = $expires['missing'] || $domain->expiry !== null || isset($expires['value']);
    $renewalRows = $domain->renewal !== '' && !$domain->setupPending;
    // Renew now sits on the Expires row: a quiet link, until expiry is near (the date turns amber within 30 days) or the renewal is late.
    $renewSoon = ($expires['tone'] ?? null) !== null || ($domain->renewNow['late'] ?? false);
    // Renew now needs a registrar expiry date, so the Expires row is always there to hold it.
    $renewable = isset($domain->renewNow['url']) && !$domain->renewalInvoice;
@endphp
@if ($showExpires || $renewalRows || $invoice || $authCode || isset($domain->renewNow['url']))
<section class="domains-panel" id="renewal" aria-labelledby="heading-renewal"><div class="domains-panel__h"><h2 id="heading-renewal" class="domains-h2">Renewal</h2></div><div class="domains-panel__b">
<dl class="domains-card">
@if ($showExpires)
<div><dt>Expires</dt><dd>@if ($expires['missing'])@include('domains::partials.missing', ['cause' => $expires['cause'], 'fix' => $expires['fix'], 'stack' => true])@elseif (isset($expires['value']))<span class="domains-card__v">{{ $expires['value'] }}</span>@if (($expires['support'] ?? true) && ($expires['note'] || \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl())) <span class="domains-sub">{{ $expires['note'] }} @include('domains::partials.contact-support')</span>@endif@else<span class="domains-date domains-card__v{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $domain->expiry, 'fallback' => 'None'])</span>@if ($expires['note']) <span class="domains-sub">{{ $expires['note'] }}</span>@endif @endif@if ($renewable)<span class="domains-row__a domains-card__a"><a class="{{ $renewSoon ? 'domains-btn domains-btn--primary domains-btn--sm' : 'domains-a' }}" data-dialog-open href="{{ $show }}?edit=renew#edit-renew">Renew now</a></span>@endif</dd></div>
@endif
@if ($renewalRows)
<div><dt>Renewal invoices @include('domains::partials.help', ['id' => 'auto-renew', 'label' => 'Renewal invoices'])</dt><dd><span class="domains-card__v">{{ $domain->autoRenew ? ($domain->autoRenew['enabled'] ? 'On' : 'Off') : $domain->renewal }}</span>@if ($domain->autoRenew) <span class="domains-row__a domains-card__a"><button type="submit" form="form-auto-renew" class="domains-a domains-linkbtn">{{ $domain->autoRenew['enabled'] ? 'Turn off' : 'Turn on' }}</button></span>@elseif ($domain->renewal === 'Off' && $support) <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $support }}">Contact support</a></span>@endif</dd></div>
@if ($domain->renewal === 'On' && $domain->renewalPrice !== \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL)
<div><dt>Auto Pay</dt><dd><span class="domains-card__v">{{ $domain->autoPay ? 'On' : 'Off' }}</span>@if (!$domain->autoPay && isset($domain->actions['autoPay'])) <span class="domains-card__a"><a class="domains-a" href="{{ $domain->actions['autoPay'] }}">Manage Auto Pay</a></span>@endif<span class="domains-sub">{{ $domain->autoPay ? 'We charge your saved payment method for each renewal invoice.' : 'We send you an invoice and you pay it to renew.' }}</span></dd></div>
@endif
@endif
@if ($domain->renewalPrice && !($domain->renewNow['late'] ?? false) && $domain->renewal === 'On' && !$domain->setupPending)
<div><dt>Renewal price</dt><dd><span class="domains-card__v">{{ $domain->renewalPrice }}{{ $domain->renewalPrice === \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL ? '' : '/yr' }}</span></dd></div>
@endif
@if ($invoice)
<div><dt>{{ $invoice['label'] ?? 'Next invoice' }}</dt><dd>@if ($invoice['missing'] ?? false)@include('domains::partials.missing', ['cause' => $invoice['cause'] ?? null, 'fix' => $invoice['fix'] ?? null, 'stack' => true])@elseif (isset($invoice['tone']))@include('domains::partials.status-pill', ['pill' => ['label' => $invoice['value'], 'tone' => $invoice['tone']]]) <span class="domains-sub">{{ $invoice['note'] }}</span>@else<span class="domains-card__v">{{ $invoice['value'] }}</span>@if (isset($invoice['note']) && !isset($invoice['at'])) <span class="domains-sub">{{ $invoice['note'] }}</span>@endif @isset($invoice['at']) <span class="domains-sub">{{ $invoice['note'] }} @include('domains::partials.moment', ['at' => $invoice['at']]).</span>@endisset @isset($invoice['action']) <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $invoice['action']['url'] }}">{{ $invoice['action']['label'] }}</a></span>@endisset @endif</dd></div>
@endif
@if ($authCode)
<div><dt>Auth code @include('domains::partials.help', ['id' => 'transfer-auth-code', 'label' => 'Auth code', 'helpText' => 'The code from your current registrar. We need it to move this domain to us.'])</dt><dd>@if ($authCode['missing'])@include('domains::partials.missing', ['cause' => null, 'fix' => $authCode['fix'], 'stack' => true])@else<span class="domains-card__v">Saved</span> <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $authCode['action']['url'] }}">{{ $authCode['action']['label'] }}</a></span>@endif</dd></div>
@endif
</dl>
@include('domains::partials.owner-auto-renew')
@if ($renewable)
@php $breakdown=static fn($line)=>isset($line['first_year'],$line['renewal'])?'$'.$line['first_year'].' for the first added year'.($line['years']>1?' + $'.$line['renewal'].($line['years']===2?' for the next year.':'/yr for the next '.($line['years']-1).' years.') : '.'):''; @endphp
<dialog id="edit-renew" class="domains-confirm domains-edit domains-dialog-sheet" aria-labelledby="edit-renew-title" @if ($renewOpen) open @endif>
<h3 id="edit-renew-title" class="domains-h3">Renew {{ $domain->name }}</h3>
<form method="POST" action="{{ $domain->renewNow['url'] }}" class="domains-owner-form" data-renewal-form>
@include('domains::partials.form-key', ['action' => 'renew'])
<dl class="domains-card">
<div><dt>Domain</dt><dd class="domains-mono"><strong>{{ $domain->name }}</strong></dd></div>
<div><dt>Expires</dt><dd>@include('domains::partials.date', ['date' => $domain->expiry])@if ($domain->renewNow['order_by'] ?? null)<span class="domains-sub">Renew online by @include('domains::partials.date', ['date' => $domain->renewNow['order_by']]).</span>@endif</dd></div>
<div><dt><label for="renew-years">Years</label></dt><dd><select id="renew-years" class="domains-input domains-term-select" name="years">@foreach ($domain->renewNow['terms'] as $years)@php $line=$renewalChoices['lines'][$years]??null; @endphp<option value="{{ $years }}" data-expiry="{{ isset($line['expiry']) ? \Carbon\CarbonImmutable::parse($line['expiry'])->format('M j, Y') : '' }}" data-breakdown="{{ $breakdown($line) }}" data-total="{{ $line ? '$'.$line['total'] : '' }}">{{ $years }} {{ $years === 1 ? 'year' : 'years' }}{{ $line ? ' · $'.$line['total'] : '' }}</option>@endforeach</select></dd></div>
<div data-renewal-summary hidden><dt>New expiry</dt><dd data-renewal-expiry>{{ !empty($renewalChoices['lines']) ? \Carbon\CarbonImmutable::parse(reset($renewalChoices['lines'])['expiry'])->format('M j, Y') : 'Confirmed at payment' }}</dd></div>
<div data-renewal-summary hidden><dt>Total</dt><dd><strong class="domains-total" data-renewal-total>{{ !empty($renewalChoices['lines']) ? '$'.reset($renewalChoices['lines'])['total'] : 'Confirmed at payment' }}</strong><span class="domains-sub" data-renewal-breakdown>{{ $breakdown(!empty($renewalChoices['lines']) ? reset($renewalChoices['lines']) : null) }}</span></dd></div>
<noscript><div><dt>Term totals</dt><dd>@forelse ($renewalChoices['lines'] ?? [] as $line)<p>{{ $line['years'] }} {{ $line['years'] === 1 ? 'year' : 'years' }}: ${{ $line['total'] }}; new expiry {{ \Carbon\CarbonImmutable::parse($line['expiry'])->format('M j, Y') }}<span class="domains-sub">{{ $breakdown($line) }}</span></p>@empty<p>Total and new expiry confirmed at payment.</p>@endforelse</dd></div></noscript>
@if (isset($renewalChoices['next_annual']))<div><dt>Next annual renewal</dt><dd>${{ $renewalChoices['next_annual'] }}/yr<span class="domains-sub">Renewal invoices stay annual. Renewal prices may change.</span></dd></div>@endif
</dl>
@if ($domain->renewNow['repeat_at'] ?? null)<p class="domains-hint" role="note" data-renewal-repeat>{{ \Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::NEXT }} @include('domains::partials.moment', ['at' => $domain->renewNow['repeat_at']]). The invoice is due now.</p>@endif
@if ($domain->renewNow['late'] ?? false)<p class="domains-hint">Late renewal @include('domains::partials.help', ['id' => 'late-renewal', 'label' => 'Late renewal', 'helpText' => \Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::HELP])</p>@endif
<div class="domains-buttons">@if ($renewOpen)<a href="{{ $show }}" class="domains-btn domains-btn--secondary">Cancel</a>@else<button type="button" class="domains-btn domains-btn--secondary" data-dialog-close>Cancel</button><noscript><a href="{{ $show }}">Cancel</a></noscript>@endif<button type="submit" class="domains-btn domains-btn--primary">{{ $domain->renewalPrice === \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL ? 'Confirm renewal' : 'Create invoice' }}</button></div></form>
</dialog>
@endif
</div></section>
@endif
