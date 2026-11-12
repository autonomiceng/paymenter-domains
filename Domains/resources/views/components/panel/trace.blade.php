{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Steps on one line (Panel\Trace): each step's mark and word come from its state; under the label, when, who and why. Without a skin it is a plain ordered list. --}}
<ol class="beco-trace">
@foreach ($steps as $step)
<li class="beco-step" data-beco-tone="{{ $step['tone']->value }}" @if ($step['record'] !== null) data-beco-record="{{ $step['record'] }}" @endif><div class="beco-step-tx"><div class="beco-step-t">{{ $step['label'] }}</div>@if ($step['at'] !== null || $step['by'] !== null || $step['reason'] !== null)<div class="beco-step-d">@if ($step['at'] !== null)@include('domains::components.panel.fact', ['fact' => $step['at']])@endif{{ $step['by'] !== null ? ($step['at'] !== null ? ', by ' : 'By ').$step['by'] : '' }}{{ $step['reason'] !== null ? ($step['at'] !== null || $step['by'] !== null ? '. ' : '').$step['reason'] : '' }}</div>@endif</div><span class="beco-step-w">{{ $step['word'] }}</span></li>
@endforeach
</ol>
