{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A nameserver value. With $count (the Nameservers row), the value is the count as the domain mock shows it ("5 nameservers"), with the names on one wrapping line under it; otherwise one name per line, all shown. --}}
@php($sorted = collect($servers)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values())
@if ($count ?? false)<span class="domains-card__v">{{ $sorted->count() }} {{ $sorted->count() === 1 ? 'nameserver' : 'nameservers' }}</span> <span class="domains-sub domains-ns-names">@foreach ($sorted as $server)<span class="domains-mono">{{ $server }}</span>@if (!$loop->last), @endif @endforeach</span>
@else<span class="domains-ns-list">@foreach ($sorted as $server)<span class="domains-mono">{{ $server }}</span> @endforeach</span>@endif
