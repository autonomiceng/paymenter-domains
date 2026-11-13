{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A customer page head, the customer side of Panel\Head: crumbs or a back link,
     the title with its Demo or Test tag (a back link names the domain, so it is mono), the page's own actions, then one status line with the record's state (a Panel\Value).
     $crumbs is a list of [label, url] ending at the page itself (its url null). $back is [label, url]. --}}
@props(['title', 'domain' => false, 'demo' => false, 'test' => false, 'crumbs' => [], 'back' => null, 'state' => null, 'actions' => null])
<header class="domains-head domains-head--page" data-piece="head">
@if ($crumbs)<nav class="domains-crumb" aria-label="Breadcrumb">@foreach ($crumbs as [$label, $url])@if ($url)<a href="{{ $url }}">{{ $label }}</a> <svg class="domains-crumb__sep" viewBox="0 0 16 16" aria-hidden="true"><path d="m6 4 4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg> @else{{ $label }}@endif @endforeach</nav>@endif
@if ($back)<div class="domains-head-back"><a class="domains-back domains-mono" href="{{ $back[1] }}">{{ $back[0] }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($demo, $test) }}</div>@endif
<div class="domains-head-main"><h1 class="domains-title{{ $domain ? ' domains-mono' : '' }}" data-piece="head-title">{{ $title }}@unless ($back){{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($demo, $test) }}@endunless</h1>
@if ($actions !== null && trim((string) $actions) !== '')<div class="domains-row domains-head-actions">{{ $actions }}</div>@endif</div>
@if ($state !== null)<div class="domains-head-line" data-piece="head-line">{{ $state }}</div>@endif
</header>
