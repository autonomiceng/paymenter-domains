{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $click = isset($action) && ! $action->isDisabled() ? $action->getLivewireClickHandler() : null;
    $colors = \Illuminate\Support\Arr::toCssClasses(\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, $on ? 'success' : 'gray'));
@endphp
<span class="domains-switch">
    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" @if ($label !== '') aria-label="{{ $label }}" @endif
        @if ($click) wire:click="{{ $click }}" wire:loading.attr="disabled" @else disabled @endif
        class="fi-toggle {{ $on ? 'fi-toggle-on' : 'fi-toggle-off' }} {{ $colors }}">
        <div><div aria-hidden="true"></div><div aria-hidden="true"></div></div>
    </button>
    @if ($word ?? true) <span class="domains-switch-word">{{ $on ? 'On' : 'Off' }}</span> @endif
</span>
