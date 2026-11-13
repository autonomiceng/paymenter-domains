{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A detail page body (DESIGN.md Panels, Layout): one sheet of sections on the left
     for what you can change, and the bare rail on the right for how it is doing and what happened. Below 1100px the rail drops
     under the sheet. Without a rail the sheet stands alone. --}}
@props(['rail' => null])
<div class="domains-cols" data-piece="split"><div class="domains-sheet" data-piece="sheet">{{ $slot }}</div>
@if ($rail !== null && trim((string) $rail) !== '')<aside class="domains-rail" data-piece="rail">{{ $rail }}</aside>@endif</div>
