{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The product editor's Domains section (Admin\Support\ProductMonitoring): the Monitor domains switch saves on change. --}}
<div><x-filament::section heading="Domains">
    <div class="domains-panel-row" data-domains-row="setting" data-piece="settings-row">
        <div class="domains-panel-row-k" id="product-monitoring-k">Monitor domains</div>
        <div class="domains-panel-row-v" id="product-monitoring-v"><span>{{ $on ? 'On' : 'Off' }}</span>
            @if (! $on && $active !== null)<span class="domains-panel-sub domains-hp-line">{{ number_format($active) }} active {{ $active === 1 ? 'service' : 'services' }} not monitored, unless another monitored product has their domain.</span>@endif
            <span class="domains-panel-sub domains-hp-line"><x-filament::link :href="$settings">Open Monitoring settings</x-filament::link> to choose all products at once.</span></div>
        <div class="domains-panel-row-a">
            @php $colors = \Illuminate\Support\Arr::toCssClasses(\Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class, $on ? 'primary' : 'gray')); @endphp
            <button type="button" role="switch" data-piece="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-labelledby="product-monitoring-k" aria-describedby="product-monitoring-v"
                @if ($can) wire:click="toggle" wire:loading.attr="disabled" @else disabled @endif
                class="fi-toggle domains-panel-switch {{ $on ? 'fi-toggle-on' : 'fi-toggle-off' }} {{ $colors }}"><div><div aria-hidden="true"></div><div aria-hidden="true"></div></div></button>
        </div>
    </div>
</x-filament::section></div>
