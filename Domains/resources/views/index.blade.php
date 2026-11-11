@component('layouts.app', ['title' => 'Domains', 'sidebar' => true])
@php
    $search=$search??''; $filter=$filter??''; $sort=$sort??'expiry'; $perPage=$perPage??25; $filterCounts=$filterCounts??[''=>$domains->total()];
    $total=array_sum($filterCounts); $params=array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort==='expiry'?null:$sort,'per_page'=>$perPage===25?null:$perPage],fn($v)=>$v!==null&&$v!=='');
    $url=fn($changes)=>route('domains.domains.index',array_filter([...$params,...$changes],fn($v)=>$v!==null&&$v!==''));
    $order=fn($key)=>$sort===$key?'ascending':($sort==='-'.$key?'descending':'none');
    $sorted=fn($key)=>$url(['sort'=>$sort===$key?'-'.$key:($key==='expiry'?null:$key),'page'=>null]);
    $row=\Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::class; $missing=\Paymenter\Extensions\Servers\Domains\Presentation\Missing::class;
@endphp
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<div class="domains-head"><h1 class="domains-title">Domains</h1>
@if ($total > 0)<div class="domains-row domains-head-actions">@if ($transfersOffered ?? false)<a class="domains-btn domains-btn--secondary" href="{{ route('domains.domains.transfer') }}">Transfer a domain</a>@endif<a class="domains-btn domains-btn--primary" href="{{ route('domains.domains.search') }}">Register a domain</a></div>@endif</div>
@include('domains::partials.notices')
@if ($total === 0)
<section class="domains-panel" aria-label="Your domains">
<div class="domains-empty domains-section"><h2 class="domains-h2">No domains yet</h2><p>{{ ($transfersOffered ?? false) ? 'Register a new name or transfer one you own.' : 'Register a new name.' }}</p>
<form method="GET" action="{{ route('domains.domains.search') }}" class="domains-searchbar" role="search"><label for="domains-empty-q" class="domains-sr">Domain name</label><input id="domains-empty-q" name="name" type="text" placeholder="example.com" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required><button type="submit" class="domains-btn domains-btn--primary">Search</button></form>
@if ($transfersOffered ?? false)<p class="domains-empty-alt">Already own one? <a class="domains-a" href="{{ route('domains.domains.transfer') }}">Transfer a domain</a></p>@endif</div>
</section>
@else
<div class="domains-toolbar"><nav class="domains-tabs" aria-label="Filter by status"><a class="domains-tab" href="{{ $url(['status'=>null,'page'=>null]) }}" @if ($filter === '') aria-current="true" @endif>All <small>{{ $filterCounts[''] }}</small></a>
@foreach (\Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::SET as $key => [$label])
@if (($filterCounts[$key]??0)>0 || $filter === $key)<a class="domains-tab" href="{{ $url(['status'=>$key,'page'=>null]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }} <small>{{ $filterCounts[$key]??0 }}</small></a>@endif
@endforeach</nav>
<form method="GET" action="{{ route('domains.domains.index') }}" class="domains-filter" role="search">@foreach (array_diff_key($params,['q'=>1]) as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
<label for="domains-q" class="domains-sr">Search your domains</label><input id="domains-q" class="domains-input" type="search" name="q" value="{{ $search }}" placeholder="Search domains" maxlength="253" autocomplete="off"></form></div>
<section class="domains-panel" aria-label="Your domains">
@if ($domains->isEmpty() && ($filterCounts['']??0) === 0 && $search === '' && $filter === '')<div class="domains-empty domains-section"><p>Only cancelled domains here.</p><a class="domains-a" href="{{ $url(['status'=>'cancelled','page'=>null]) }}">Show cancelled domains</a></div>
@elseif ($domains->isEmpty())<div class="domains-empty domains-section"><p>No domains match {{ $search !== '' ? '"'.$search.'"' : 'this filter' }}.</p><a class="domains-a" href="{{ route('domains.domains.index') }}">Show all domains</a></div>@else
<div class="domains-tablewrap"><table class="domains-tbl domains-stackable domains-list"><thead><tr><th scope="col" aria-sort="{{ $order('name') }}"><a href="{{ $sorted('name') }}">Domain</a></th><th scope="col">Status</th><th scope="col" aria-sort="{{ $order('expiry') }}"><a href="{{ $sorted('expiry') }}">Expires</a></th><th scope="col">Renewal invoices</th></tr></thead><tbody>
@foreach ($domains as $domain)
@php($hosting=$row::hosting($domain))
@php($expires=$row::expires($domain))
@php($renewal=$row::renewal($domain))
<tr class="domains-linkrow"><td class="domains-name-cell"><a class="domains-domain domains-row-link domains-wrap" href="{{ $domain->urls['detail'] }}">{{ $domain->name }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}@if ($hosting) <span class="domains-cell-sub">{{ $hosting }}</span>@endif</td>
<td class="domains-cell-wide" data-label="Status">@include('domains::partials.status-pill', ['pill' => \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($domain)])</td>
<td class="domains-cell-wide" data-label="Expires">@if ($expires['missing']){{ $missing::html($expires['cause'], $expires['fix']) }}@elseif (isset($expires['value']))<span class="domains-cell-main">{{ $expires['value'] }}</span>@else<span class="domains-cell-main{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $domain->expiry, 'fallback' => 'None'])</span>@if ($expires['note']) <span class="domains-cell-sub">{{ $expires['note'] }}</span>@endif @endif</td>
<td class="domains-cell-wide" data-label="Renewal invoices"><span class="domains-cell-main">{{ $renewal['value'] }}</span>@if ($renewal['invoiceMissing']) <span class="domains-cell-sub">Next invoice: {{ $missing::html($renewal['invoiceCause'], $renewal['invoiceFix']) }}</span>@elseif ($renewal['noteState'] ?? null) <span class="domains-cell-sub">Next invoice: <span class="domains-tone-{{ $renewal['noteState']['tone'] }}">{{ $renewal['noteState']['label'] }}</span></span>@elseif ($renewal['note']) <span class="domains-cell-sub">{{ $renewal['note'] }}@if ($renewal['at'] ?? null) @include('domains::partials.moment', ['at' => $renewal['at']]).@endif</span>@endif</td></tr>
@endforeach
</tbody></table></div>
@include('domains::partials.pager', ['page' => $domains, 'perPage' => $perPage, 'hidden' => array_diff_key($params, ['per_page' => 1]), 'action' => route('domains.domains.index')])
@endif
</section>
@endif
@include('domains::partials.client-script')
</div></div>
@endcomponent
