@component('layouts.app', ['title' => 'Domains', 'sidebar' => true])
@php
    $search=$search??''; $filter=$filter??''; $sort=$sort??'expiry'; $filterCounts=$filterCounts??[''=>$domains->total()];
    $total=$filterCounts['']; $params=array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort==='expiry'?null:$sort],fn($v)=>$v!==null&&$v!=='');
    $url=fn($changes)=>route('domains.domains.index',array_filter([...$params,...$changes],fn($v)=>$v!==null&&$v!==''));
    $row=\Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::class; $missing=\Paymenter\Extensions\Servers\Domains\Presentation\Missing::class;
@endphp
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<div class="domains-head"><h1 class="domains-title">Domains</h1>
<div class="domains-row">@if ($transfersOffered ?? false)<a class="domains-btn domains-btn--secondary" href="{{ route('domains.domains.transfer') }}">Transfer a domain</a>@endif<a class="domains-btn domains-btn--primary" href="{{ route('domains.domains.search') }}">Register a domain</a></div></div>
@include('domains::partials.notices')
<section class="domains-panel" aria-label="Your domains">
@if ($total === 0)
<div class="domains-empty domains-section"><h2 class="domains-h2">No domains yet</h2><p>{{ ($transfersOffered ?? false) ? 'Register a new name or transfer one you own.' : 'Register a new name.' }}</p>
<form method="GET" action="{{ route('domains.domains.search') }}" class="domains-searchbar" role="search" style="max-width:34rem;margin-inline:auto"><label for="domains-empty-q" class="domains-sr">Domain name</label><input id="domains-empty-q" name="name" type="text" placeholder="yourbusiness.com" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required><button type="submit" class="domains-btn domains-btn--primary">Search</button></form></div>
@else
<div class="domains-toolbar"><nav class="domains-chips" aria-label="Filter by status"><a class="domains-chip" href="{{ $url(['status'=>null,'page'=>null]) }}" @if ($filter === '') aria-current="true" @endif>All <small>{{ $total }}</small></a>
@foreach (\Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::SET as $key => [$label])
@if (($filterCounts[$key]??0)>0 || $filter === $key)<a class="domains-chip" href="{{ $url(['status'=>$key,'page'=>null]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }} <small>{{ $filterCounts[$key]??0 }}</small></a>@endif
@endforeach</nav>
<form method="GET" action="{{ route('domains.domains.index') }}" class="domains-filter" role="search" data-domains-autosubmit="auto">@if ($filter !== '')<input type="hidden" name="status" value="{{ $filter }}">@endif
<label for="domains-q" class="domains-sr">Search your domains</label><input id="domains-q" class="domains-input" type="search" name="q" value="{{ $search }}" placeholder="Search domains" maxlength="253" autocomplete="off">
<label for="domains-sort" class="domains-sr">Sort by</label><select id="domains-sort" name="sort" class="domains-input">@foreach (['expiry'=>'Expires, soonest first','-expiry'=>'Expires, latest first','name'=>'Name, A to Z','-name'=>'Name, Z to A'] as $value=>$label)<option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>@endforeach</select></form></div>
@if ($domains->isEmpty())<div class="domains-empty domains-section"><p>No domains match {{ $search !== '' ? '"'.$search.'"' : 'this filter' }}.</p><a class="domains-a" href="{{ route('domains.domains.index') }}">Show all domains</a></div>@else
<div class="domains-tablewrap"><table class="domains-tbl domains-stackable domains-list"><thead><tr><th scope="col">Domain</th><th scope="col">Status</th><th scope="col">Expires</th><th scope="col">Renewal</th></tr></thead><tbody>
@foreach ($domains as $domain)
@php($hosting=$row::hosting($domain))
@php($expires=$row::expires($domain))
@php($renewal=$row::renewal($domain))
<tr class="domains-linkrow"><td class="domains-name-cell"><a class="domains-domain domains-row-link domains-wrap" href="{{ $domain->urls['detail'] }}">{{ $domain->name }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}@if ($hosting) <span class="domains-cell-sub">{{ $hosting }}</span>@endif</td>
<td class="domains-cell-wide" data-label="Status">@include('domains::partials.status-pill', ['pill' => \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($domain)])</td>
<td class="domains-cell-wide" data-label="Expires">@if ($expires['waiting'] ?? false)<span class="domains-cell-main domains-muted">Not yet</span> <span class="domains-cell-sub">{{ $expires['cause'] }}</span>@elseif ($expires['missing']){{ $missing::html($expires['cause'], $expires['fix']) }}@else<span class="domains-cell-main{{ $expires['tone'] ? ' domains-tone-text-'.$expires['tone'] : '' }}">@include('domains::partials.date', ['date' => $domain->expiry])</span>@if ($expires['note']) <span class="domains-cell-sub">{{ $expires['note'] }}</span>@endif @endif</td>
<td class="domains-cell-wide" data-label="Renewal"><span class="domains-cell-main">{{ $renewal['value'] }}</span>@if ($renewal['invoiceMissing']) <span class="domains-cell-sub">Next invoice: {{ $missing::html() }}</span>@elseif ($renewal['note']) <span class="domains-cell-sub">{{ $renewal['note'] }}</span>@endif</td></tr>
@endforeach
</tbody></table></div>
@endif
@endif
</section>
@if ($domains->hasPages())<div>{{ $domains->links() }}</div>@endif
@include('domains::partials.client-script')
</div></div>
@endcomponent
