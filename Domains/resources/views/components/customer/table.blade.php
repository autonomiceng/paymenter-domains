{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A customer table surface (DESIGN.md Panels, Tables): its toolbar, the table or
     its empty line, then the one pager. Rows stack on phones through domains-stackable on the table itself. --}}
@props(['label', 'toolbar' => null])
@if ($toolbar !== null)<div class="domains-toolbar">{{ $toolbar }}</div>@endif
<section class="domains-panel" data-piece="table" aria-label="{{ $label }}">{{ $slot }}</section>
