{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The one line under a page title (Panel\Head): the record's state with its mark, facts, one link, then a sentence for the whole page. Spans only, since Filament puts it in a paragraph. --}}
<span class="domains-panel-head-line" data-piece="head-line">@if ($word !== null)<span class="domains-panel-st" data-domains-tone="{{ $tone->value }}" data-domains-record="{{ $record }}">{{ $word }}</span>@endif
@foreach ($facts as $item) <span class="domains-panel-head-fact">{{ $item['label'] }} @include('domains::components.panel.fact', ['fact' => $item['fact']])</span>@endforeach
@if ($link) <a class="domains-panel-link" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endif
@if ($note ?? null) <span class="domains-panel-head-note">{{ $note }}</span>@endif</span>
