{{-- History as a trace: newest work first, the mark and the word say how each step ended. --}}
@php
    $tone = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::stepTone(...);
    $steps = [...array_map(fn ($row) => [...$row, 'moment' => true], $domain->progress), ...$domain->history];
    $all = request()->query('history') === 'all';
@endphp
<section class="domains-panel" id="history" aria-labelledby="heading-history"><div class="domains-panel__h"><h2 id="heading-history" class="domains-h2">History</h2>@if (count($steps) > 5 && !$all)<a class="domains-a" href="{{ $domain->actions['show'] }}?history=all#history">View all {{ count($steps) }}</a>@endif</div>
@if (!$steps)<div class="domains-panel__b"><p class="domains-muted">No changes yet.</p></div>@else
<ol class="domains-trace">@foreach ($all ? $steps : array_slice($steps, 0, 5) as $step)
@php($word = $step['state'] ?? $step['status'])
<li><span class="domains-status domains-tone-{{ $tone($word) }}" aria-hidden="true"></span><div>@if ($step['url'] ?? null)<a class="domains-a" href="{{ $step['url'] }}">Invoice #{{ $step['number'] }}</a>@else<b>{{ $step['label'] === 'WHOIS privacy' && !\Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN ? 'Domain change' : $step['label'] }}</b>@endif
@if ($step['by'] ?? null)<span class="domains-sub">By {{ $step['by'] }}</span>@endif
@if ($step['note'] ?? null)<span class="domains-sub">{{ $step['note'] }}@if ($word === 'Failed')@if ($step['retry'] ?? false) Try again. @include('domains::partials.contact-support', ['before' => 'Still stuck? '])@else @include('domains::partials.contact-support', ['after' => ' to make this change.'])@endif @endif</span>@endif
@if ($step['date'] ?? null)<span class="domains-sub">@if ($step['moment'] ?? false)@include('domains::partials.moment', ['at' => $step['date']])@else @include('domains::partials.date', ['date' => $step['date']])@endif</span>@endif</div><span class="domains-tone-{{ $tone($word) }}">{{ $word }}</span></li>
@endforeach</ol>
@endif
</section>
