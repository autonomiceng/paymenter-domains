{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The page notice row (DESIGN.md Panels, Notices): one sentence under the head and
     its one action, after the state mark of its tone (a hexagon, as in every status). $tone is the notice tone (info, warn, bad); $action is [label, url, button] or null. --}}
@props(['tone' => 'info', 'action' => null])
<div class="domains-notice domains-notice--{{ $tone }}" data-piece="notice" role="status"><span class="domains-notice__mark domains-status domains-tone-{{ $tone }}" aria-hidden="true"></span><span class="domains-notice__tx">{{ $slot }}</span>@if ($action) <a class="{{ ($action['button'] ?? false) ? 'domains-btn domains-btn--primary domains-btn--sm' : 'domains-a' }}" href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endif</div>
