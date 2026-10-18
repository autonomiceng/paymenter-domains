{{-- Native service consumers must obtain the current authenticated owner's safe DTO. --}}
<div class="domains">@include('domains::partials.styles')
@include('domains::partials.test-banner', ['test' => $domain->testEnvironment ? true : null])
<div class="domains-panel domains-section domains-row" style="justify-content:space-between"><div style="min-width:0"><p class="domains-domain">{{ $domain->name }}</p><span class="domains-badge domains-tone-{{ $domain->tone }}">{{ $domain->status }}</span>@if ($domain->expiry)<span>Expires @include('domains::partials.date', ['date' => $domain->expiry])</span>@endif</div><a class="domains-btn domains-btn--primary" href="{{ $domain->actions['show'] }}">Manage domain</a></div></div>
