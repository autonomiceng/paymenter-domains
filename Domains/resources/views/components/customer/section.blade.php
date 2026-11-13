{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One section of a sheet or rail (DESIGN.md Panels, Layout): its name, one optional
     sentence for all its rows, and a link only when its rows have no room for it. $id names it for links and its heading.
     The head slot holds lines about the whole section, such as when it was last checked. With no title, the content names
     itself (a form with its own heading) and $padded gives it the section's inner space. --}}
@props(['id', 'title' => null, 'note' => null, 'link' => null, 'head' => null, 'padded' => false])
<section class="domains-panel{{ $padded ? ' domains-section' : '' }}" id="{{ $id }}" data-piece="section" @if ($title !== null) aria-labelledby="heading-{{ $id }}" @endif>@if ($title !== null)<div class="domains-panel__h"><h2 id="heading-{{ $id }}" class="domains-h2">{{ $title }}</h2>@if ($link){{ $link }}@endif
@if ($note !== null)<p class="domains-hint">{{ $note }}</p>@endif{{ $head ?? '' }}</div>@endif
{{ $slot }}</section>
