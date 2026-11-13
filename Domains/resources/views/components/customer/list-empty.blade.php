{{-- MIT, Copyright (c) 2026 Autonomic Engineering. An empty list (DESIGN.md Panels, Tables, Empty): one line that says what is
     missing, then the next step as the slot. No icon or column headers. It sits inside the list's own surface. --}}
@props(['text'])
<p class="domains-list-empty" data-piece="list-empty">{{ $text }}@if (trim((string) $slot) !== ''){{ ' ' }}{{ $slot }}@endif</p>
