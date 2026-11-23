{{-- MIT, Copyright (c) 2026 Autonomic Engineering. DNS templates use the panel's shared switch. --}}
@php
    $arguments = $action->getArguments();
    $on = (bool) ($arguments['on'] ?? false);
    $blocked = (bool) ($arguments['blocked'] ?? false) || $action->isDisabled();
    $reason = $blocked ? $action->getTooltip() : null;
    $why = $reason ? 'template-enabled-why' : null;
@endphp
@include('domains::components.panel.switch', ['on' => $on, 'name' => 'Enabled', 'why' => $why])
@if ($reason)<span class="fi-sr-only" id="{{ $why }}">{{ $reason }}</span>@endif
