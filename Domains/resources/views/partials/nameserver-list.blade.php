{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A nameserver value as in the approved mock: the count, then the names in one grey line that wraps to 2 lines at most. --}}
{{ count($servers) }} {{ count($servers) === 1 ? 'nameserver' : 'nameservers' }} <span class="domains-sub domains-ns-list" title="{{ implode(', ', $servers) }}">{{ implode(', ', $servers) }}</span>
