@php $lines = \Paymenter\Extensions\Servers\Domains\Services\WatchProducts::lines(); $manage = $this->canManageProducts(); @endphp
@if ($lines !== [])
    <x-filament::section heading="Hosting products" collapsible>
        <ul>
            @foreach ($lines as $line)
                <li wire:key="watch-product-{{ $line['product'] }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;padding:.375rem 0">
                    <span style="flex:1 1 12rem;overflow-wrap:anywhere">{{ $line['name'] }}</span>
                    <x-filament::badge :color="match ($line['state']) { 'Monitored' => 'success', 'No domain found' => 'warning', default => 'gray' }">{{ $line['state'] }}</x-filament::badge>
                    @if ($manage && $line['pick'])
                        {{ ($this->chooseFieldAction)(['product_id' => $line['product'], 'map' => $line['map'], 'revision' => $line['revision']]) }}
                    @endif
                    @if ($manage && $line['map'])
                        {{ ($this->productSwitchAction)(['map' => $line['map'], 'revision' => $line['revision'], 'on' => $line['on'], 'name' => $line['name']]) }}
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>
@endif
