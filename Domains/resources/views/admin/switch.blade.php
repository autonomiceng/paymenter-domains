{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $click = isset($action) && ! $action->isDisabled() ? $action->getLivewireClickHandler() : null;
    $colors = \Illuminate\Support\Arr::toCssClasses(\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, $on ? 'success' : 'gray'));
@endphp
<span style="display:inline-flex;align-items:center;gap:.5rem">
    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" @if ($label !== '') aria-label="{{ $label }}" @endif
        @if ($click) wire:click="{{ $click }}" wire:loading.attr="disabled" @else disabled @endif
        class="fi-toggle {{ $on ? 'fi-toggle-on' : 'fi-toggle-off' }} {{ $colors }}">
        <div><div aria-hidden="true"></div><div aria-hidden="true"></div></div>
    </button>
    <span @style(['display:inline-block', 'min-width:1.75rem', 'font-size:.875rem', 'font-weight:600' => $on])>{{ $on ? 'On' : 'Off' }}</span>
</span>
