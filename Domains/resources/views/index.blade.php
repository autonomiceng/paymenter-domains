@component('layouts.app', ['title' => 'Domains', 'sidebar' => true])
@php
    $search=$search??''; $filter=$filter??''; $sort=$sort??'expiry'; $perPage=$perPage??25; $filterCounts=$filterCounts??[''=>$domains->total()];
    $total=array_sum($filterCounts); $params=array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort==='expiry'?null:$sort,'per_page'=>$perPage===25?null:$perPage],fn($v)=>$v!==null&&$v!=='');
    $url=fn($changes)=>route('domains.domains.index',array_filter([...$params,...$changes],fn($v)=>$v!==null&&$v!==''));
    $order=fn($key)=>$sort===$key?'ascending':($sort==='-'.$key?'descending':'none');
    $sorted=fn($key)=>$url(['sort'=>$sort===$key?'-'.$key:($key==='expiry'?null:$key),'page'=>null]);
    $row=\Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::class; $values=\Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::class;
    $value=\Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::class; $fact=\Paymenter\Extensions\Servers\Domains\Presentation\Fact::class; $plain=\Paymenter\Extensions\Servers\Domains\Presentation\Plain::class;
    $noMatch='No domains match '.($search!==''?'"'.$search.'"':'this filter').'.';
    $tabs=[['All domains',$filterCounts['']??0,$url(['status'=>null,'page'=>null]),$filter==='']];
    foreach (\Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::SET as $key => [$label]) {
        if (($filterCounts[$key]??0)>0 || $filter === $key) { $tabs[]=[$label,$filterCounts[$key]??0,$url(['status'=>$key,'page'=>null]),$filter===$key]; }
    }
@endphp
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<x-domains::customer.head title="Domains">
<x-slot:actions>@if ($total > 0 && ($transfersOffered ?? false))<a class="domains-btn domains-btn--secondary" href="{{ route('domains.domains.transfer') }}">Transfer a domain</a>@endif @if ($total > 0)<a class="domains-btn domains-btn--primary" href="{{ route('domains.domains.search') }}">Register a domain</a>@endif</x-slot:actions>
</x-domains::customer.head>
@include('domains::partials.notices')
@if ($total === 0)
<x-domains::customer.table label="Your domains"><x-domains::customer.list-empty text="No domains yet."><a class="domains-a" href="{{ route('domains.domains.search') }}">Register a domain</a>@if ($transfersOffered ?? false) or <a class="domains-a" href="{{ route('domains.domains.transfer') }}">transfer one you own</a>@endif</x-domains::customer.list-empty></x-domains::customer.table>
@else
<x-domains::customer.table label="Your domains">
<x-slot:toolbar><x-domains::customer.tabs label="Filter by status" :tabs="$tabs" />
<form method="GET" action="{{ route('domains.domains.index') }}" class="domains-filter" role="search">@foreach (array_diff_key($params,['q'=>1]) as $name=>$v)<input type="hidden" name="{{ $name }}" value="{{ $v }}">@endforeach
<label for="domains-q" class="domains-sr">Search your domains</label><input id="domains-q" class="domains-input" type="search" name="q" value="{{ $search }}" placeholder="Search domains" maxlength="253" autocomplete="off"></form></x-slot:toolbar>
@if ($domains->isEmpty() && ($filterCounts['']??0) === 0 && $search === '' && $filter === '')<x-domains::customer.list-empty text="Only cancelled domains here."><a class="domains-a" href="{{ $url(['status'=>'cancelled','page'=>null]) }}">Show cancelled domains</a></x-domains::customer.list-empty>
@elseif ($domains->isEmpty())<x-domains::customer.list-empty :text="$noMatch"><a class="domains-a" href="{{ route('domains.domains.index') }}">Show all domains</a></x-domains::customer.list-empty>@else
<div class="domains-tablewrap"><table class="domains-tbl domains-stackable domains-list"><thead><tr><th scope="col" aria-sort="{{ $order('name') }}"><a href="{{ $sorted('name') }}">Domain</a></th><th scope="col">Status</th><th scope="col" aria-sort="{{ $order('expiry') }}"><a href="{{ $sorted('expiry') }}">Expires</a></th><th scope="col">Auto-renew</th></tr></thead><tbody>
@foreach ($domains as $domain)
@php
    $hosting=$row::hosting($domain); $expires=$row::expires($domain); $renewal=$row::renewal($domain);
    $status=$values::status($domain->statusState, $domain->statusReason);
    $expiresValue=match (true) {
        $expires['missing'] => $values::unread($expires['cause']),
        isset($expires['value']) => null,
        $fact::day($domain->expiry) !== null => $value::fact($fact::day($domain->expiry), $expires['note']),
        default => null,
    };
    $invoice=match (true) {
        $renewal['invoiceMissing'] => $values::notIssued(null),
        (bool) ($renewal['noteState'] ?? null) => $value::state(\Paymenter\Extensions\Servers\Domains\Presentation\State::UnderReview, \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer),
        default => null,
    };
    $fix=$expires['missing'] ? ($expires['fix'] ?? null) : null;
@endphp
<tr class="domains-linkrow"><td class="domains-name-cell"><a class="domains-domain domains-row-link domains-wrap" href="{{ $domain->urls['detail'] }}">{{ $domain->name }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}@if ($hosting) <span class="domains-cell-sub">{{ $hosting }}</span>@endif</td>
<td class="domains-cell-wide" data-label="Status">{{ $status }}</td>
<td class="domains-cell-wide" data-label="Expires">@if ($expiresValue){{ $expiresValue }}@elseif (isset($expires['value']))<span class="domains-cell-main">{{ $expires['value'] }}</span>@endif @if (is_array($fix) && ($fix['url'] ?? null)) <a class="{{ ($fix['button'] ?? false) ? 'domains-btn domains-btn--primary domains-btn--sm' : 'domains-a' }}" href="{{ $fix['url'] }}">{{ $fix['label'] }}</a>@elseif (is_array($fix) && ($fix['label'] ?? null)) <span class="domains-cell-sub">{{ $fix['label'] }}</span>@endif</td>
<td class="domains-cell-wide" data-label="Auto-renew">@if ($renewal['value'] !== ''){{ $value::plain($renewal['value'] === 'Off' ? $plain::Off : $plain::On, $invoice === null && $renewal['note'] && !($renewal['at'] ?? null) ? $renewal['note'] : null) }}@endif
@if ($invoice) <span class="domains-cell-sub">Next invoice:</span>{{ $invoice }}@if ($renewal['invoiceMissing'] && ($renewal['invoiceFix']['url'] ?? null)) <a class="domains-a" href="{{ $renewal['invoiceFix']['url'] }}">{{ $renewal['invoiceFix']['label'] }}</a>@endif
@elseif ($renewal['at'] ?? null) <span class="domains-cell-sub">{{ $renewal['note'] }} @include('domains::partials.moment', ['at' => $renewal['at']]).</span>@endif</td></tr>
@endforeach
</tbody></table></div>
<x-domains::customer.pager :page="$domains" :per-page="$perPage" :hidden="array_diff_key($params, ['per_page' => 1])" :action="route('domains.domains.index')" />
@endif
</x-domains::customer.table>
@endif
@include('domains::partials.client-script')
</div></div>
@endcomponent
