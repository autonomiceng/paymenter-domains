{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The Renewal section: expiry, Auto-renew, Auto Pay, price, next invoice, auth code and Renew now. --}}
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
    $renewalOn = $domain->autoRenew ? $domain->autoRenew['enabled'] : $domain->renewal !== 'Off';
    $complimentary = $domain->renewalPrice === \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL;
    // A row's "?" next to its label.
    $help = static fn (string $id, string $label, ?string $text = null) => new \Illuminate\Support\HtmlString(view('domains::partials.help', array_filter(['id' => $id, 'label' => $label, 'helpText' => $text]))->render());
@endphp
@if ($showExpires || $renewalRows || $invoice || $authCode || isset($domain->renewNow['url']))
<x-domains::customer.section id="renewal" title="Renewal"><x-domains::customer.rows>
@if ($showExpires)
<x-domains::customer.row label="Expires">@if ($expires['missing'])<x-domains::customer.unread :cause="$expires['cause']" :fix="$expires['fix']" />@elseif (isset($expires['value']))<span class="domains-card__v">{{ $expires['value'] }}</span>@if (($expires['support'] ?? true) && ($expires['note'] || \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl())) <span class="domains-sub">{{ $expires['note'] }} @include('domains::partials.contact-support')</span>@endif@else<span class="domains-date domains-card__v{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $domain->expiry, 'fallback' => 'None'])</span>@if ($expires['note']) <span class="domains-sub">{{ $expires['note'] }}</span>@endif @endif@if ($renewable)<span class="domains-row__a domains-card__a"><a class="domains-btn {{ $renewSoon ? 'domains-btn--primary' : 'domains-btn--secondary' }} domains-btn--sm" data-dialog-open href="{{ $show }}?edit=renew#edit-renew">Renew now</a></span>@endif</x-domains::customer.row>
@endif
@if ($renewalRows)
<x-domains::customer.row label="Auto-renew" :setting="true" :help="$help('auto-renew', 'Auto-renew')">{{ \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::plain($renewalOn ? \Paymenter\Extensions\Servers\Domains\Presentation\Plain::On : \Paymenter\Extensions\Servers\Domains\Presentation\Plain::Off, \Paymenter\Extensions\Servers\Domains\Presentation\RenewalPayment::ownerLine($renewalOn, $complimentary)) }}
<span class="domains-row__a domains-card__a">@if ($domain->autoRenew)<button type="submit" form="form-auto-renew" class="domains-btn domains-btn--secondary domains-btn--sm">{{ $domain->autoRenew['enabled'] ? 'Turn off' : 'Turn on' }}</button>@elseif ($domain->renewal === 'Off' && $support)<a class="domains-a" href="{{ $support }}">Contact support</a>@endif</span>
</x-domains::customer.row>
{{-- Auto Pay is the account setting that pays renewal invoices; it has nothing to pay while Auto-renew is off or the domain is complimentary. Status and one link, never a second switch. --}}
@if (isset($domain->actions['autoPay']) && $renewalOn && !$complimentary)
<x-domains::customer.row label="Auto Pay"><span class="domains-card__v">{{ \Paymenter\Extensions\Servers\Domains\Presentation\RenewalPayment::autoPayState($domain->autoPay, $domain->autoPayMethod) }}</span>
<span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $domain->actions['autoPay'] }}">{{ \Paymenter\Extensions\Servers\Domains\Presentation\RenewalPayment::autoPayLink($domain->autoPay) }}</a></span>
</x-domains::customer.row>
@endif
@endif
@if ($domain->renewalPrice && !($domain->renewNow['late'] ?? false) && $domain->renewal === 'On' && !$domain->setupPending)
<x-domains::customer.row label="Renewal price"><span class="domains-card__v">{{ $domain->renewalPrice }}{{ $domain->renewalPrice === \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL ? '' : '/yr' }}</span></x-domains::customer.row>
@endif
@if ($invoice)
<x-domains::customer.row :label="$invoice['label'] ?? 'Next invoice'">@if ($invoice['notIssued'] ?? false)<x-domains::customer.unread :value="\Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::notIssued($invoice['cause'])" :fix="$invoice['fix'] ?? null" />@elseif ($invoice['missing'] ?? false)<x-domains::customer.unread :cause="$invoice['cause'] ?? null" :fix="$invoice['fix'] ?? null" />@elseif (isset($invoice['tone']))@if ($invoiceState = \Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::step($invoice['value'])){{ \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::state($invoiceState, \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer) }}@else @include('domains::partials.status-pill', ['pill' => ['label' => $invoice['value'], 'tone' => $invoice['tone']]])@endif <span class="domains-sub">{{ $invoice['note'] }}</span>@else<span class="domains-card__v">{{ $invoice['value'] }}</span>@if (isset($invoice['note']) && !isset($invoice['at'])) <span class="domains-sub">{{ $invoice['note'] }}</span>@endif @isset($invoice['at']) <span class="domains-sub">{{ $invoice['note'] }} @include('domains::partials.moment', ['at' => $invoice['at']]).</span>@endisset @isset($invoice['action']) <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $invoice['action']['url'] }}">{{ $invoice['action']['label'] }}</a></span>@endisset @endif</x-domains::customer.row>
@endif
@if ($authCode)
<x-domains::customer.row label="Auth code" :help="$help('transfer-auth-code', 'Auth code', 'The code from your current registrar. We need it to move this domain to us.')">@if ($authCode['missing'])<x-domains::customer.unread :fix="$authCode['fix']" />@else<span class="domains-card__v">Saved</span> <span class="domains-row__a domains-card__a"><a class="domains-a" href="{{ $authCode['action']['url'] }}">{{ $authCode['action']['label'] }}</a></span>@endif</x-domains::customer.row>
@endif
</x-domains::customer.rows>
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
@if (isset($renewalChoices['next_annual']))<div><dt>Next annual renewal</dt><dd>${{ $renewalChoices['next_annual'] }}/yr<span class="domains-sub">Auto-renew stays yearly. Renewal prices may change.</span></dd></div>@endif
</dl>
@if ($domain->renewNow['repeat_at'] ?? null)<p class="domains-hint" role="note" data-renewal-repeat>{{ \Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::NEXT }} @include('domains::partials.moment', ['at' => $domain->renewNow['repeat_at']]). The invoice is due now.</p>@endif
@if ($domain->renewNow['late'] ?? false)<p class="domains-hint">Late renewal @include('domains::partials.help', ['id' => 'late-renewal', 'label' => 'Late renewal', 'helpText' => \Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::HELP])</p>@endif
<div class="domains-buttons">@if ($renewOpen)<a href="{{ $show }}" class="domains-btn domains-btn--secondary">Cancel</a>@else<button type="button" class="domains-btn domains-btn--secondary" data-dialog-close>Cancel</button><noscript><a href="{{ $show }}">Cancel</a></noscript>@endif<button type="submit" class="domains-btn domains-btn--primary">{{ $domain->renewalPrice === \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL ? 'Confirm renewal' : 'Create invoice' }}</button></div></form>
</dialog>
@endif
</x-domains::customer.section>
@endif
