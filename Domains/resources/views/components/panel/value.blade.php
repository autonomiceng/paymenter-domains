{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One panel value (Panel\Value): a state with its mark, a plain word or a fact, then a sentence under it (ending with any sentence the row adds) and, for staff, a line of times and codes. Without a skin it reads as stacked text. --}}
@props(['value'])
<div class="domains-panel-val" data-piece="value" data-domains-kind="{{ $value->kind }}">
@if ($value->tag !== null)<div>@endif
@if ($value->tone)<span class="domains-panel-st" data-domains-tone="{{ $value->tone->value }}" @if ($value->record !== null) data-domains-record="{{ $value->record }}" @endif>{{ $value->word }}</span>
@elseif ($value->fact)@if ($value->fact->kind === 'code')<div class="domains-panel-sub domains-panel-extra">@endif@include('domains::components.panel.fact', ['fact' => $value->fact])@if ($value->fact->kind === 'code')</div>@endif
@else<span class="domains-panel-plain">{{ $value->word }}</span>@endif
@if ($value->tag !== null) {{ $value->tag }}</div>@endif
@if ($value->sub !== null || $value->then !== null)<div class="domains-panel-sub">@if ($value->sub !== null){{ $value->sub }}@if ($value->subAt !== null && $value->kind === 'fact') @include('domains::components.panel.fact', ['fact' => $value->subAt])@elseif ($value->subAt !== null), @include('domains::components.panel.fact', ['fact' => $value->subAt]).@endif{{ $value->then !== null ? ' ' : '' }}@endif{{ $value->then }}</div>@endif
@if ($extra = $value->shownExtra())<div class="domains-panel-sub domains-panel-extra">@foreach ($extra as $fact)@include('domains::components.panel.fact', ['fact' => $fact])@if (!$loop->last), @endif @endforeach</div>@endif
</div>
