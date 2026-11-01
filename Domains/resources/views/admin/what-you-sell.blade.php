<x-filament-panels::page>
    <div style="display:grid;gap:1.5rem">
        @if(count($accounts = $this->accounts()) > 1)
            <x-filament::tabs>
                @foreach($accounts as $id => $name)
                    <x-filament::tabs.item tag="a" :href="static::getUrl(['account' => $id])" :active="$id === $this->account">{{ $name }}</x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        @endif
        @if($this->canEdit() && $this->binding() !== null && !$this->readable())
            <form wire:submit="addEnding" style="display:flex;gap:.75rem;align-items:center;max-width:24rem">
                <x-filament::input.wrapper style="flex:1"><x-filament::input type="text" wire:model="newEnding" placeholder=".com" aria-label="TLD" /></x-filament::input.wrapper>
                <x-filament::button type="submit" color="gray">Add TLD</x-filament::button>
            </form>
        @endif
        {{ $this->content }}
    </div>
</x-filament-panels::page>
