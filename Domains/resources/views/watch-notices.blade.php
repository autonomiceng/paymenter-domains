@component('layouts.app', ['title' => $name, 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
@php
    $page = \Paymenter\Extensions\Servers\Domains\Presentation\WatchPage::class;
    $notice = $page::notice($summary, $notices, $portal);
    $expires = $page::expires($summary, $portal);
    $nameservers = $page::nameservers($summary);
    $history = $page::history($notices);
    $monitored = $editable && !array_intersect(['watch.paused', 'watch.retired'], $summary->stateKeys);
    $settings = \Illuminate\Support\Facades\Route::has('account.notifications') ? route('account.notifications') : null;
@endphp
<nav class="domains-crumb" aria-label="Breadcrumb"><a href="{{ route('domains.domains.index') }}">Domains</a> / {{ $name }}</nav>
<div class="domains-ph"><h1 class="domains-mono">{{ $name }}{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($summary->demo, $summary->testEnvironment) }}</h1>@if ($pill = \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($summary))<span class="domains-pill">@include('domains::partials.status-pill', ['pill' => $notice ? [...$pill, 'reason' => null] : $pill])@include('domains::partials.help', ['id' => 'status', 'label' => 'Status', 'helpText' => 'We check the public registry and nameservers daily.'])</span>@endif
@if ($summary->relatedServices)<p class="domains-ph__sub">Hosting: <a class="domains-a" href="{{ $summary->relatedServices[0]['url'] }}">{{ $summary->relatedServices[0]['label'] }}</a>@if (($more = count($summary->relatedServices) - 1 + $summary->relatedOverflow) > 0) and {{ $more }} more @endif</p>@endif</div>
@if (session('watch_saved'))<div class="domains-notice domains-notice--ok" role="status"><span class="domains-notice__tx">{{ session('watch_saved') }}</span></div>@endif
@error('preferences')<div class="domains-notice domains-notice--bad" role="alert"><span class="domains-notice__tx">{{ $message }}</span></div>@enderror
@if ($notice)
<div class="domains-notice domains-notice--{{ $notice['tone'] }}" role="status"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4M12 16h.01"/><circle cx="12" cy="12" r="10"/></svg><span class="domains-notice__tx">{{ $notice['text'] }}@isset($notice['link']) @if ($notice['link']['url'])<a class="domains-a" href="{{ $notice['link']['url'] }}">{{ $notice['link']['label'] }}</a>@else{{ $notice['link']['label'] }}@endif.@endisset</span></div>
@endif
<div class="domains-cols"><div>
<section class="domains-panel" aria-labelledby="heading-dns"><div class="domains-panel__h"><h2 id="heading-dns" class="domains-h2">DNS</h2></div>
<ul class="domains-rows"><li class="domains-row" id="nameservers"><span class="domains-row__l">Nameservers</span>
<span class="domains-row__v">@if ($nameservers['missing'])@include('domains::partials.missing', ['cause' => $nameservers['cause'], 'fix' => null, 'stack' => true])@else<span @class(['domains-mono' => $nameservers['mono']])>{{ $nameservers['values'][0] }}</span>@foreach (array_slice($nameservers['values'], 1) as $server) <span class="domains-sub domains-mono">{{ $server }}</span>@endforeach @if ($nameservers['sub']) <span class="domains-sub">{{ $nameservers['sub'] }}</span>@endif @endif</span></li></ul></section>
@if ($monitored)
<section class="domains-panel" aria-labelledby="heading-alerts"><div class="domains-panel__h"><h2 id="heading-alerts" class="domains-h2">Alerts</h2></div>
<ul class="domains-rows"><li class="domains-row" id="alerts"><span class="domains-row__l">Monitoring alerts</span>
@if (!$preferences['account']['enabled'])
<span class="domains-row__v">Off for all domains</span>@if ($settings)<span class="domains-row__a"><a class="domains-a" href="{{ $settings }}">Notification settings</a></span>@endif
@else
<span class="domains-row__v">{{ $preferences['target']['enabled'] ? 'On' : 'Off' }}</span><span class="domains-row__a"><button type="submit" form="form-alerts" class="domains-a domains-linkbtn">{{ $preferences['target']['enabled'] ? 'Turn off' : 'Turn on' }}</button></span>
<form id="form-alerts" method="POST" action="{{ url('/domains/checks/'.$kind.'/'.$id) }}" hidden>@csrf<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="account_revision" value="{{ $preferences['account']['revision'] }}"><input type="hidden" name="target_revision" value="{{ $preferences['target']['revision'] }}"><input type="hidden" name="email" value="1"><input type="hidden" name="notices" value="{{ $preferences['target']['enabled'] ? 0 : 1 }}"></form>
@endif
</li></ul></section>
@endif
<section class="domains-panel" id="history" aria-labelledby="heading-history"><div class="domains-panel__h"><h2 id="heading-history" class="domains-h2">History</h2></div>
@if (!$history)<div class="domains-panel__b"><p class="domains-muted">No changes yet.</p></div>@else
<div class="domains-tablewrap"><table class="domains-tbl"><thead><tr><th scope="col">Change</th><th scope="col">Date</th></tr></thead><tbody>
@foreach ($history as $row)<tr><td>{{ $row['label'] }}</td> <td>@if ($row['date'])@include('domains::partials.date', ['date' => $row['date']])@endif</td></tr>@endforeach
</tbody></table></div>
@endif
</section>
</div><aside>
<section class="domains-panel" id="renewal" aria-labelledby="heading-renewal"><div class="domains-panel__h"><h2 id="heading-renewal" class="domains-h2">Renewal</h2></div><div class="domains-panel__b">
<dl class="domains-card">
<div><dt>Expires @include('domains::partials.help', ['id' => 'expires', 'label' => 'Expires', 'helpText' => 'From the public registry. It can lag a few days after a renewal.'])</dt><dd>@if ($expires['missing'])@include('domains::partials.missing', ['cause' => $expires['cause'], 'fix' => null, 'stack' => true])@else<span class="domains-date{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $summary->expiry])</span>@if ($expires['sub']) <span class="domains-sub">{{ $expires['sub'] }}</span>@endif @endif</dd></div>
@if ($kind === 'watch')<div><dt>Renewal</dt><dd>At your registrar</dd></div>@endif
</dl>
@if ($kind === 'watch' && ($summary->urls['compareTransfer'] ?? null))<a class="domains-btn domains-btn--primary domains-btn--block" href="{{ $summary->urls['compareTransfer'] }}">Transfer to {{ \Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::transferTarget() }}</a>
@elseif ($kind === 'managed' && ($summary->urls['detail'] ?? null))<a class="domains-btn domains-btn--ghost domains-btn--block" href="{{ $summary->urls['detail'] }}">Open domain</a>@endif
</div></section>
</aside></div>
@include('domains::partials.client-script')
</div></div>
@endcomponent
