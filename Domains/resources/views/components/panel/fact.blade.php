{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One Fact. A skin sets host and code in mono and numbers in tabular figures from data-beco-fact. --}}
@props(['fact'])
@if ($fact->kind === 'date')<time class="beco-fact" data-beco-fact="date" datetime="{{ $fact->datetime }}" @if ($fact->title !== null) title="{{ $fact->title }}" @endif>{{ $fact->text }}</time>@else<span class="beco-fact" data-beco-fact="{{ $fact->kind }}">{{ $fact->text }}</span>@endif
