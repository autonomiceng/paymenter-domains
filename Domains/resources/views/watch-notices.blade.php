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
@php
    $dnsNotice = ($notice['section'] ?? null) === 'dns';
    $state = $page::state($summary);
    $support = $summary->urls['support'] ?? null;
    // A stopped watch's next step is a link on its status line (DESIGN.md P-LAY-12).
    if ($state && in_array('watch.retired', $summary->stateKeys, true) && $support) {
        $state = new \Illuminate\Support\HtmlString($state->toHtml().' <a class="domains-a" href="'.e($support).'">Contact support</a>');
    }
    $help = fn (string $id, string $label, string $text) => view('domains::partials.help', ['id' => $id, 'label' => $label, 'helpText' => $text]);
@endphp
<x-domains::customer.head :title="$name" :domain="true" :demo="$summary->demo" :test="$summary->testEnvironment" :crumbs="[['Domains', route('domains.domains.index')], [$name, null]]" :state="$state" />
@if (session('watch_saved'))<div class="domains-notice domains-notice--ok" role="status"><span class="domains-notice__tx">{{ session('watch_saved') }}</span></div>@endif
@error('preferences')<div class="domains-notice domains-notice--bad" role="alert"><span class="domains-notice__tx">{{ $message }}</span></div>@enderror
@if ($notice && !$dnsNotice)<x-domains::customer.notice :tone="$notice['tone']" :action="($notice['link']['url'] ?? null) ? ['label' => $notice['link']['label'], 'url' => $notice['link']['url']] : null">{{ $notice['text'] }}@if (isset($notice['link']) && !$notice['link']['url']) {{ $notice['link']['label'] }}.@endif</x-domains::customer.notice>@endif
<x-domains::customer.split>
<x-domains::customer.section id="dns" title="DNS">
@if ($dnsNotice)<div class="domains-notice domains-notice--sec domains-notice--{{ $notice['tone'] }}" role="status"><span class="domains-notice__mark domains-status domains-tone-{{ $notice['tone'] }}" aria-hidden="true"></span><span class="domains-notice__tx">{{ $notice['text'] }}@if (isset($notice['link'])) @if ($notice['link']['url'])<a class="domains-a" href="{{ $notice['link']['url'] }}">{{ $notice['link']['label'] }}</a>@else{{ $notice['link']['label'] }}@endif.@endif</span></div>@endif
<x-domains::customer.rows>
<x-domains::customer.row label="Nameservers">@if ($nameservers['missing'])<x-domains::customer.unread :cause="$nameservers['cause']" />@else<span @class(['domains-card__v', 'domains-mono' => $nameservers['mono']])>{{ $nameservers['values'][0] }}</span>@foreach (array_slice($nameservers['values'], 1) as $server) <span class="domains-sub domains-mono">{{ $server }}</span>@endforeach @if ($nameservers['sub']) <span class="domains-sub">{{ $nameservers['sub'] }}</span>@endif @endif</x-domains::customer.row>
@if ($summary->relatedServices)<x-domains::customer.row label="Hosting"><a class="domains-a domains-card__v" href="{{ $summary->relatedServices[0]['url'] }}">{{ $summary->relatedServices[0]['label'] }}</a>@if (($more = \Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::moreHosting($summary)) > 0) <span class="domains-sub">and {{ $more }} more</span>@endif</x-domains::customer.row>@endif
</x-domains::customer.rows></x-domains::customer.section>
@if ($monitored)
<x-domains::customer.section id="alerts" title="Alerts"><x-domains::customer.rows>
@if (!$preferences['account']['enabled'] && $preferences['account']['revision'] > 1)
<x-domains::customer.row label="Monitoring alerts" :setting="true"><span class="domains-card__v">Off for all domains</span><x-slot:actions>@if ($settings)<a class="domains-a" href="{{ $settings }}">Notification settings</a>@endif</x-slot:actions></x-domains::customer.row>
@else
<x-domains::customer.row label="Monitoring alerts" :setting="true">{{ \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::plain($alertsOn ? \Paymenter\Extensions\Servers\Domains\Presentation\Plain::On : \Paymenter\Extensions\Servers\Domains\Presentation\Plain::Off) }}<x-slot:actions><button type="submit" form="form-alerts" class="domains-btn domains-btn--secondary domains-btn--sm">{{ $alertsOn ? 'Turn off' : 'Turn on' }}</button></x-slot:actions></x-domains::customer.row>
@endif
</x-domains::customer.rows>
@unless (!$preferences['account']['enabled'] && $preferences['account']['revision'] > 1)<form id="form-alerts" method="POST" action="{{ url('/domains/checks/'.$kind.'/'.$id) }}" hidden>@csrf<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="account_revision" value="{{ $preferences['account']['revision'] }}"><input type="hidden" name="target_revision" value="{{ $preferences['target']['revision'] }}"><input type="hidden" name="email" value="1"><input type="hidden" name="notices" value="{{ $alertsOn ? 0 : 1 }}"></form>@endunless
</x-domains::customer.section>
@endif
<x-slot:rail>
<x-domains::customer.section id="renewal" title="Renewal"><x-domains::customer.rows>
<x-domains::customer.row label="Expires" :help="$help('expires', 'Expires', 'From the public registry. It can lag a few days after a renewal.')">@if ($expires['missing'])<x-domains::customer.unread :cause="$expires['cause']" />@else<span class="domains-date domains-card__v{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $summary->expiry])</span>@if ($expires['sub']) <span class="domains-sub">{{ $expires['sub'] }}</span>@endif @endif</x-domains::customer.row>
@if ($kind === 'watch')<x-domains::customer.row label="Renews"><span class="domains-card__v">At your registrar</span></x-domains::customer.row>@endif
</x-domains::customer.rows>
@if ($kind === 'watch' && ($summary->urls['compareTransfer'] ?? null))<div class="domains-panel__b"><a class="domains-btn domains-btn--primary domains-btn--block" href="{{ $summary->urls['compareTransfer'] }}">Transfer to {{ \Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::transferTarget() }}</a></div>
@elseif ($kind === 'managed' && ($summary->urls['detail'] ?? null))<div class="domains-panel__b"><a class="domains-btn domains-btn--ghost domains-btn--block" href="{{ $summary->urls['detail'] }}">Open domain</a></div>@endif
</x-domains::customer.section>
<x-domains::customer.section id="history" title="History">
@if (!$history)<x-domains::customer.list-empty text="No changes yet." />@else
<x-domains::customer.trace :steps="array_map(fn ($row) => ['label' => $row['label'], 'date' => $row['date']], $history)" />
@endif
</x-domains::customer.section>
</x-slot:rail>
</x-domains::customer.split>
@include('domains::partials.client-script')
</div></div>
@endcomponent
