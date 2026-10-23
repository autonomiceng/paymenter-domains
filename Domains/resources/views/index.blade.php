@component('layouts.app', ['title' => 'Domains', 'sidebar' => true])
@php
    $search=$search??''; $filter=$filter??''; $sort=$sort??'expiry'; $filterCounts=$filterCounts??[''=>$domains->total()];
    $total=$filterCounts['']; $params=array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort==='expiry'?null:$sort],fn($v)=>$v!==null&&$v!=='');
    $url=fn($changes)=>route('domains.domains.index',array_filter([...$params,...$changes],fn($v)=>$v!==null&&$v!==''));
@endphp
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<div class="domains-head"><div><h1 class="domains-title">Domains</h1>@if ($total)<p class="domains-lede">{{ $total }} {{ $total === 1 ? 'domain' : 'domains' }} in your account.</p>@endif</div>
<div class="domains-row"><a class="domains-btn domains-btn--secondary" href="{{ route('domains.domains.transfer') }}">Transfer a domain</a><a class="domains-btn domains-btn--primary" href="{{ route('domains.domains.search') }}">Register a domain</a></div></div>
@include('domains::partials.notices')
<section class="domains-panel" aria-label="Your domains">
@if ($total === 0)
<div class="domains-empty domains-section"><h2 class="domains-h2">Find your domain name</h2><p>Search for a new name, or move a domain you already own from another registrar.</p>
<form method="GET" action="{{ route('domains.domains.search') }}" class="domains-searchbar" role="search" style="max-width:34rem;margin-inline:auto"><label for="domains-empty-q" class="domains-sr">Domain name</label><input id="domains-empty-q" name="name" type="text" placeholder="Search for a name" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required><button type="submit" class="domains-btn domains-btn--primary">Search</button></form>
<p class="domains-small">Already own a domain elsewhere? <a class="domains-a" href="{{ route('domains.domains.transfer') }}">Transfer it here</a></p></div>
@else
<div class="domains-toolbar"><nav class="domains-chips" aria-label="Filter by status"><a class="domains-chip" href="{{ $url(['status'=>null,'page'=>null]) }}" @if ($filter === '') aria-current="true" @endif>All <small>{{ $total }}</small></a>
@foreach (\Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::SET as $key => [$label])
@if (($filterCounts[$key]??0)>0 || $filter === $key)<a class="domains-chip" href="{{ $url(['status'=>$key,'page'=>null]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }} <small>{{ $filterCounts[$key]??0 }}</small></a>@endif
@endforeach</nav>
<form method="GET" action="{{ route('domains.domains.index') }}" class="domains-filter" role="search">@if ($filter !== '')<input type="hidden" name="status" value="{{ $filter }}">@endif
<label for="domains-q" class="domains-sr">Search your domains</label><input id="domains-q" class="domains-input" type="search" name="q" value="{{ $search }}" placeholder="Search domains" maxlength="253" autocomplete="off">
<label for="domains-sort" class="domains-sr">Sort by</label><select id="domains-sort" name="sort" class="domains-input">@foreach (['expiry'=>'Expiry, soonest first','-expiry'=>'Expiry, latest first','name'=>'Name, A to Z','-name'=>'Name, Z to A'] as $value=>$label)<option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>@endforeach</select><button type="submit" class="domains-btn domains-btn--secondary domains-btn--sm">Apply</button></form></div>
@if ($domains->isEmpty())<div class="domains-empty domains-section"><p>No domains match {{ $search !== '' ? '“'.$search.'”' : 'this filter' }}.</p><a class="domains-a" href="{{ route('domains.domains.index') }}">Show all domains</a></div>@else
<div class="domains-tablewrap"><table class="domains-tbl domains-stackable"><thead><tr><th scope="col">Domain</th><th scope="col">Status</th><th scope="col">Expires</th><th scope="col">Renewal</th><th scope="col"><span class="domains-sr">Actions</span></th></tr></thead><tbody>
@foreach ($domains as $domain)
<tr><td class="domains-name-cell"><a class="domains-domain domains-wrap" href="{{ $domain->urls['detail'] }}">{{ $domain->name }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}@if($domain->displayName)<p class="domains-small domains-wrap">{{ $domain->displayName }}</p>@endif
@if($domain->relatedServices)<p class="domains-small">Related services</p><ul>@foreach(array_slice($domain->relatedServices,0,3) as $service)<li><a class="domains-a domains-small" href="{{ $service['url'] }}">{{ $service['label'] }}</a></li>@endforeach</ul>@if($domain->relatedOverflow)<span class="domains-small">And more related services</span>@endif@endif</td>
<td class="domains-cell-wide" data-label="Status">@include('domains::partials.status-pill', ['pill' => \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($domain)])</td>
<td class="domains-cell-wide" data-label="Expires">@include('domains::partials.date', ['date' => $domain->expiry, 'fallback' => 'Expiry unknown'])</td>
<td class="domains-cell-wide" data-label="Renewal">{{ in_array('adopt.pending',$domain->stateKeys,true)?'Pending verification':($domain->renewalOffShown()?'Renewal off':($domain->renewalDueAt?($domain->renewalDueProjected?'Planned due ':'Due ').\Carbon\CarbonImmutable::parse($domain->renewalDueAt)->format('M j, Y'):'See details')) }}@if($domain->renewalPrice && !$domain->renewalOff && !in_array('adopt.pending',$domain->stateKeys,true))<p class="domains-small">{{ $domain->renewalPrice }}/yr</p>@endif</td>
<td class="domains-end"><a class="domains-btn domains-btn--secondary domains-btn--sm" href="{{ $domain->urls['detail'] }}" aria-label="Manage {{ $domain->name }}">Manage</a></td></tr>
@endforeach
</tbody></table></div>
@endif
@endif
</section>
@if ($domains->hasPages())<div>{{ $domains->links() }}</div>@endif
@isset($watches)@include('domains::partials.related')@endisset
</div></div>
@endcomponent
