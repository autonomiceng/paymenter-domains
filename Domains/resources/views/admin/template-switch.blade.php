{{-- MIT, Copyright (c) 2026 Autonomic Engineering. DNS templates use the panel's shared switch. --}}
@php
    $arguments = $action->getArguments();
    $on = (bool) ($arguments['on'] ?? false);
    $blocked = (bool) ($arguments['blocked'] ?? false) || $action->isDisabled();
    $reason = $blocked ? $action->getTooltip() : null;
    $why = $reason ? 'template-monitoring-why' : null;
@endphp
@include('domains::components.panel.switch', ['on' => $on, 'name' => 'Monitoring', 'why' => $why])
@if ($reason)<span class="domains-panel-sub" id="{{ $why }}">{{ $reason }}</span>@endif
