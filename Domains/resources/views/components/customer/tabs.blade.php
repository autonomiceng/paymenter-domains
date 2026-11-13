{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One tab row (DESIGN.md Panels, Tabs): each tab is a link with its count, the
     current one marked. $tabs is a list of [label, count, url, current]. It scrolls sideways when it doesn't fit. --}}
@props(['tabs', 'label'])
<nav class="domains-tabs" data-piece="tabs" aria-label="{{ $label }}">@foreach ($tabs as [$name, $count, $url, $current])<a class="domains-tab" href="{{ $url }}" @if ($current) aria-current="true" @endif>{{ $name }} <small>{{ $count }}</small></a>@endforeach</nav>
