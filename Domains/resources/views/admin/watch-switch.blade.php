{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php($arguments = $action->getArguments())
@include('domains::admin.switch', ['on' => (bool) ($arguments['on'] ?? false), 'label' => 'Monitor '.($arguments['name'] ?? '')])
