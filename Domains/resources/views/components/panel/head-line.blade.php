{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The one line under a page title (Panel\Head): the record's state with its mark, facts, then one link. Spans only, since Filament puts it in a paragraph. --}}
<span class="beco-head-line">@if ($word !== null)<span class="beco-st" data-beco-tone="{{ $tone->value }}" data-beco-record="{{ $record }}">{{ $word }}</span>@endif
@foreach ($facts as $item) <span class="beco-head-fact">{{ $item['label'] }} @include('domains::components.panel.fact', ['fact' => $item['fact']])</span>@endforeach
@if ($link) <a class="beco-link" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endif</span>
