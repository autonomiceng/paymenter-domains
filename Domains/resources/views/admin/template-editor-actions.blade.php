{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
<x-filament::actions class="domains-template-editor-footer">
    <x-filament::button tag="a" :href="$cancelUrl" color="gray">Cancel</x-filament::button>
    <x-filament::button type="submit" form-id="template-editor" wire:target="save">Save</x-filament::button>
</x-filament::actions>
