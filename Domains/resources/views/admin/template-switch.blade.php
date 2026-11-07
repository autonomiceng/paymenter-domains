{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $arguments = $action->getArguments();
    $on = (bool) ($arguments['on'] ?? false);
    $blocked = (bool) ($arguments['blocked'] ?? false) || $action->isDisabled();
    $click = $blocked ? null : $action->getLivewireClickHandler();
    $colors = \Illuminate\Support\Arr::toCssClasses(\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, $on ? 'primary' : 'gray'));
@endphp
<span class="domains-switch">
    <button type="button" role="switch" aria-label="Monitoring" aria-checked="{{ $on ? 'true' : 'false' }}"
        @if ($blocked) aria-disabled="true" @elseif ($click) wire:click="{{ $click }}" wire:loading.attr="disabled" @endif
        class="fi-toggle {{ $on ? 'fi-toggle-on' : 'fi-toggle-off' }} {{ $colors }}">
        <div><div aria-hidden="true"></div><div aria-hidden="true"></div></div>
    </button>
</span>
