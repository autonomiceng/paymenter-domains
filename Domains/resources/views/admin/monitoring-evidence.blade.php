{{-- MIT, Copyright (c) 2026 Autonomic Engineering. What monitoring last saw, as sheet rows (MonitoringEvidence::rows): the
     website addresses public DNS answered and the registrar holds, each with when it was checked. withRecords: the page
     already shows the registrar records and their last read, and its section says the separation sentence. --}}
@php $withRecords ??= false; @endphp
<div class="domains-monitoring" data-piece="monitoring">
@unless ($withRecords)<p class="domains-panel-sub">{{ $evidence['separation'] }}</p>@endunless
@foreach (\Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence::rows($evidence, $withRecords) as $label => $value)
@include('domains::components.panel.row', ['kind' => 'fact', 'id' => 'domains-panel-monitoring-'.\Illuminate\Support\Str::slug($label), 'label' => $label, 'value' => $value, 'help' => null, 'live' => null, 'result' => null, 'waits' => false, 'blocked' => null, 'fix' => null, 'why' => null, 'inline' => null, 'actions' => null, 'count' => 0])
@endforeach
</div>
