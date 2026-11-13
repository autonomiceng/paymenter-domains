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
    $alertsOn = $preferences['account']['enabled'] && $preferences['target']['enabled'];
    $settings = \Illuminate\Support\Facades\Route::has('account.notifications') ? route('account.notifications') : null;
@endphp
{{-- A detail page (DESIGN.md Panels, Layout): what you can change on the sheet (DNS, Alerts), how it is doing on the bare
     rail (Renewal, History). A nameserver notice is about DNS, so it is the first row of that section; any other notice
     is about the whole page and sits under the head. --}}
@php($dnsNotice = ($notice['section'] ?? null) === 'dns')
<x-domains::customer.head :title="$name" :demo="$summary->demo" :test="$summary->testEnvironment" :crumbs="[['Domains', route('domains.domains.index')], [$name, null]]">
<x-slot:state>@if ($off = $page::offState($summary))<span class="domains-pill">{{ $off }}@if (in_array('watch.retired', $summary->stateKeys, true) && ($summary->urls['support'] ?? null)) <a class="domains-a" href="{{ $summary->urls['support'] }}">Contact support</a>@endif</span>@else @if ($pill = \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($summary))<span class="domains-pill">@include('domains::partials.status-pill', ['pill' => $notice ? [...$pill, 'reason' => null] : $pill])@include('domains::partials.help', ['id' => 'status', 'label' => 'Status', 'helpText' => 'We check the public registry and nameservers daily.'])</span>@endif @endif
@if ($summary->relatedServices)<p class="domains-ph__sub">Hosting: <a class="domains-a" href="{{ $summary->relatedServices[0]['url'] }}">{{ $summary->relatedServices[0]['label'] }}</a>@if (($more = \Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::moreHosting($summary)) > 0) and {{ $more }} more @endif</p>@endif</x-slot:state>
</x-domains::customer.head>
@if (session('watch_saved'))<div class="domains-notice domains-notice--ok" role="status"><span class="domains-notice__tx">{{ session('watch_saved') }}</span></div>@endif
@error('preferences')<div class="domains-notice domains-notice--bad" role="alert"><span class="domains-notice__tx">{{ $message }}</span></div>@enderror
@php($noticeText = $notice ? new \Illuminate\Support\HtmlString(e($notice['text']).(isset($notice['link']) ? ' '.($notice['link']['url'] ? '<a class="domains-a" href="'.e($notice['link']['url']).'">'.e($notice['link']['label']).'</a>' : e($notice['link']['label'])).'.' : '')) : null)
@if ($notice && !$dnsNotice)<x-domains::customer.notice :tone="$notice['tone']">{{ $noticeText }}</x-domains::customer.notice>@endif
<x-domains::customer.split>
<x-domains::customer.section id="dns" title="DNS">
@if ($dnsNotice)<div class="domains-notice domains-notice--sec domains-notice--{{ $notice['tone'] }}" role="status"><span class="domains-notice__mark domains-status domains-tone-{{ $notice['tone'] }}" aria-hidden="true"></span><span class="domains-notice__tx">{{ $noticeText }}</span></div>@endif
<ul class="domains-rows"><li class="domains-row" id="nameservers"><span class="domains-row__l">Nameservers</span>
<span class="domains-row__v">@if ($nameservers['missing'])<x-domains::customer.unread :cause="$nameservers['cause']" />@else<span @class(['domains-mono' => $nameservers['mono']])>{{ $nameservers['values'][0] }}</span>@foreach (array_slice($nameservers['values'], 1) as $server) <span class="domains-sub domains-mono">{{ $server }}</span>@endforeach @if ($nameservers['sub']) <span class="domains-sub">{{ $nameservers['sub'] }}</span>@endif @endif</span></li></ul>
</x-domains::customer.section>
@if ($monitored)
<x-domains::customer.section id="alerts-section" title="Alerts">
<ul class="domains-rows"><li class="domains-row" id="alerts"><span class="domains-row__l">Monitoring alerts</span>
@if (!$preferences['account']['enabled'] && $preferences['account']['revision'] > 1)
<span class="domains-row__v">Off for all domains</span>@if ($settings)<span class="domains-row__a"><a class="domains-a" href="{{ $settings }}">Notification settings</a></span>@endif
@else
<span class="domains-row__v">{{ $alertsOn ? 'On' : 'Off' }}</span><span class="domains-row__a"><button type="submit" form="form-alerts" class="domains-a domains-linkbtn">{{ $alertsOn ? 'Turn off' : 'Turn on' }}</button></span>
<form id="form-alerts" method="POST" action="{{ url('/domains/checks/'.$kind.'/'.$id) }}" hidden>@csrf<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="account_revision" value="{{ $preferences['account']['revision'] }}"><input type="hidden" name="target_revision" value="{{ $preferences['target']['revision'] }}"><input type="hidden" name="email" value="1"><input type="hidden" name="notices" value="{{ $alertsOn ? 0 : 1 }}"></form>
@endif
</li></ul>
</x-domains::customer.section>
@endif
<x-slot:rail>
<x-domains::customer.section id="renewal" title="Renewal">
<dl class="domains-card">
<div><dt>Expires @include('domains::partials.help', ['id' => 'expires', 'label' => 'Expires', 'helpText' => 'From the public registry. It can lag a few days after a renewal.'])</dt><dd>@if ($expires['missing'])<x-domains::customer.unread :cause="$expires['cause']" />@else<span class="domains-date{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $summary->expiry])</span>@if ($expires['sub']) <span class="domains-sub">{{ $expires['sub'] }}</span>@endif @endif</dd></div>
@if ($kind === 'watch')<div><dt>Renewal</dt><dd>At your registrar</dd></div>@endif
</dl>
@if ($kind === 'watch' && ($summary->urls['compareTransfer'] ?? null))<a class="domains-btn domains-btn--primary domains-btn--block domains-rail-action" href="{{ $summary->urls['compareTransfer'] }}">Transfer to {{ \Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::transferTarget() }}</a>
@elseif ($kind === 'managed' && ($summary->urls['detail'] ?? null))<a class="domains-btn domains-btn--ghost domains-btn--block domains-rail-action" href="{{ $summary->urls['detail'] }}">Open domain</a>@endif
</x-domains::customer.section>
<x-domains::customer.section id="history" title="History">
@if (!$history)<x-domains::customer.list-empty text="No changes yet." />@else
<dl class="domains-card">@foreach ($history as $row)<div><dt>{{ $row['label'] }}</dt> <dd>@if ($row['date'])@include('domains::partials.date', ['date' => $row['date']])@endif</dd></div>@endforeach</dl>
@endif
</x-domains::customer.section>
</x-slot:rail>
</x-domains::customer.split>
@include('domains::partials.client-script')
</div></div>
@endcomponent
