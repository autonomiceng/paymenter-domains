{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Steps on one line (Panel\Trace): each step's mark and word come from its state; under the label, when, who and why. Without a skin it is a plain ordered list. --}}
<ol class="domains-panel-trace" data-piece="trace">
@foreach ($steps as $step)
<li class="domains-panel-step" data-domains-tone="{{ $step['tone']->value }}" @if ($step['record'] !== null) data-domains-record="{{ $step['record'] }}" @endif><div class="domains-panel-step-tx"><div class="domains-panel-step-t">{{ $step['label'] }}</div>@if ($step['at'] !== null || $step['by'] !== null || $step['reason'] !== null)<div class="domains-panel-step-d">@if ($step['at'] !== null)@include('domains::components.panel.fact', ['fact' => $step['at']])@endif{{ $step['by'] !== null ? ($step['at'] !== null ? ', by ' : 'By ').$step['by'] : '' }}{{ $step['reason'] !== null ? ($step['at'] !== null || $step['by'] !== null ? '. ' : '').$step['reason'] : '' }}</div>@endif</div><span class="domains-panel-step-w">{{ $step['word'] }}</span></li>
@endforeach
</ol>
