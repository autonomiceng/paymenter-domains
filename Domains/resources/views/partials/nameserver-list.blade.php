{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A nameserver value: one name per line, all shown. The record row above already gives the count. --}}
<span class="domains-ns-list">@foreach ($servers as $server)<span class="domains-mono">{{ $server }}</span>@endforeach</span>
