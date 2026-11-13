{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Things that happened in order, the customer side of Panel\Trace: each step's mark
     and word come from its State for customers; a step with no state has no mark and keeps its own word, if any. A step named for its state (Paid) says the word once. A step's label may be a link. Under it,
     who, the reason sentence and the date. $steps is a list of [label, url, state, by, reason, date, moment]. --}}
@props(['steps'])
<ol class="domains-trace" data-piece="trace">@foreach ($steps as $step)
@php($state = $step['state'] ?? null)
@php($tone = $state?->tone(\Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer)->value)
<li @if ($tone) data-domains-tone="{{ $tone }}" @endif><span class="domains-trace-mark" aria-hidden="true"></span><div>@if ($step['url'] ?? null)<a class="domains-a" href="{{ $step['url'] }}">{{ $step['label'] }}</a>@else<b>{{ $step['label'] }}</b>@endif
@if ($step['by'] ?? null)<span class="domains-sub">By {{ $step['by'] }}</span>@endif
@if ($step['reason'] ?? null)<span class="domains-sub">{{ $step['reason'] }}</span>@endif
@if ($step['date'] ?? null)<span class="domains-sub">@if ($step['moment'] ?? false)@include('domains::partials.moment', ['at' => $step['date']])@else @include('domains::partials.date', ['date' => $step['date']])@endif</span>@endif</div>@if ($state && $state->label() !== $step['label'])<span class="domains-trace-word">{{ $state->label() }}</span>@elseif ($state)@elseif ($step['word'] ?? null)<span class="domains-trace-word">{{ $step['word'] }}</span>@endif</li>
@endforeach</ol>
