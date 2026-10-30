{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The Renewal card: expiry, auto-renew, next invoice, auth code, price and Renew now. --}}
@php
    $page = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::class;
    $expires = $page::expires($domain, $summary ?? null, $support);
    $invoice = $page::nextInvoice($domain, $summary ?? null, $support);
    $authCode = $page::authCode($domain, $support);
    $show = $domain->actions['show'];
    $renewOpen = request()->query('edit') === 'renew' || session('domains_failed_form') === ($domain->renewNow['url'] ?? false);
@endphp
<section class="domains-panel" id="renewal" aria-labelledby="heading-renewal"><div class="domains-panel__h"><h2 id="heading-renewal" class="domains-h2">Renewal</h2></div><div class="domains-panel__b">
<dl class="domains-card">
<div><dt>Expires</dt><dd>@if ($expires['missing'])@include('domains::partials.missing', ['cause' => $expires['cause'], 'fix' => $expires['fix'], 'stack' => true])@else<span class="domains-date domains-card__v{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $domain->expiry])</span>@if ($expires['note']) <span class="domains-sub">{{ $expires['note'] }}</span>@endif @endif</dd></div>
@if ($domain->renewal !== '' && !$domain->setupPending)
<div><dt>Auto-renew @include('domains::partials.help', ['id' => 'auto-renew', 'label' => 'Auto-renew'])</dt><dd><span class="domains-card__v">{{ $domain->autoRenew ? ($domain->autoRenew['enabled'] ? 'On' : 'Off') : $domain->renewal }}</span>@if ($domain->autoRenew) <span class="domains-row__a domains-card__a"><button type="submit" form="form-auto-renew" class="domains-a domains-linkbtn">{{ $domain->autoRenew['enabled'] ? 'Turn off' : 'Turn on' }}</button></span>@elseif ($domain->renewal === 'Off' && $support) <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $support }}">Contact support</a></span>@endif@if ($domain->renewal === 'On')<span class="domains-sub">@if ($domain->autoPay)Auto Pay is on: we charge your saved payment method for each renewal invoice.@else Auto Pay is off: we send you an invoice and you pay it to renew.@isset($domain->actions['autoPay']) <a class="domains-a" href="{{ $domain->actions['autoPay'] }}">Manage Auto Pay</a>@endisset @endif</span>@endif</dd></div>
@endif
@if ($invoice)
<div><dt>Next invoice</dt><dd>@if ($invoice['missing'] ?? false)@include('domains::partials.missing', ['cause' => $invoice['cause'] ?? null, 'fix' => $invoice['fix'] ?? null, 'stack' => true])@else<span class="domains-card__v">{{ $invoice['value'] }}</span>@isset($invoice['action']) <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $invoice['action']['url'] }}">{{ $invoice['action']['label'] }}</a></span>@endisset @endif</dd></div>
@endif
@if ($authCode)
<div><dt>Auth code @include('domains::partials.help', ['id' => 'transfer-auth-code', 'label' => 'Auth code', 'helpText' => 'The code from your current registrar. We need it to move this domain to us.'])</dt><dd>@if ($authCode['missing'])@include('domains::partials.missing', ['cause' => null, 'fix' => $authCode['fix'], 'stack' => true])@else<span class="domains-card__v">Saved</span> <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $authCode['action']['url'] }}">{{ $authCode['action']['label'] }}</a></span>@endif</dd></div>
@endif
@if ($domain->renewalPrice && $domain->renewal === 'On' && !$domain->setupPending)
<div><dt>Renewal price</dt><dd><span class="domains-card__v">{{ $domain->renewalPrice }}/yr</span></dd></div>
@endif
</dl>
@include('domains::partials.owner-auto-renew')
@if (isset($domain->renewNow['url']) && !$domain->renewalInvoice)
<a class="domains-btn domains-btn--primary domains-btn--block" data-dialog-open href="{{ $show }}?edit=renew#edit-renew">Renew now</a>
@component('domains::partials.owner-edit', ['id' => 'renew', 'title' => 'Renew '.$domain->name, 'open' => $renewOpen, 'cancel' => $show])
<form method="POST" action="{{ $domain->renewNow['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'renew'])
<label class="domains-label" for="renew-years">Years</label><select id="renew-years" class="domains-input" name="years">@foreach ($domain->renewNow['terms'] as $years)<option value="{{ $years }}">{{ $years }} {{ $years === 1 ? 'year' : 'years' }}</option>@endforeach</select>
@if ($domain->renewNow['order_by'] ?? null)<p class="domains-hint">You can renew online until @include('domains::partials.date', ['date' => $domain->renewNow['order_by']]).</p>@endif
<div class="domains-buttons"><button type="submit" class="domains-btn domains-btn--primary">Create invoice</button></div></form>
@endcomponent
@endif
</div></section>
