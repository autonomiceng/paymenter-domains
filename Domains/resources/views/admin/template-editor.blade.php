{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
<form wire:submit="save">
    {{ $form }}
    <x-filament::actions alignment="end">
        <x-filament::button tag="a" :href="$cancelUrl" color="gray">Cancel</x-filament::button>
        <x-filament::button type="submit">Save</x-filament::button>
    </x-filament::actions>
</form>
