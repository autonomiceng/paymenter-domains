{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One panel value (Panel\Value): a state with its mark, a plain word or a fact, then a sentence under it and, for staff, a line of times and codes. Without a skin it reads as stacked text. --}}
@props(['value'])
<div class="beco-val" data-beco-kind="{{ $value->kind }}">
@if ($value->tone)<span class="beco-st" data-beco-tone="{{ $value->tone->value }}" @if ($value->record !== null) data-beco-record="{{ $value->record }}" @endif>{{ $value->word }}</span>
@elseif ($value->fact)@include('domains::components.panel.fact', ['fact' => $value->fact])
@else<span class="beco-plain">{{ $value->word }}</span>@endif
@if ($value->sub !== null)<div class="beco-sub">{{ $value->sub }}@if ($value->subAt !== null), @include('domains::components.panel.fact', ['fact' => $value->subAt]).@endif</div>@endif
@if ($extra = $value->shownExtra())<div class="beco-sub beco-extra">@foreach ($extra as $fact)@include('domains::components.panel.fact', ['fact' => $fact])@if (!$loop->last), @endif @endforeach</div>@endif
</div>
