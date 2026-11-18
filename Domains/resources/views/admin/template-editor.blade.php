{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
<form wire:submit="save" class="domains-template-editor">
    {{ $editor->form }}
    <x-filament::actions class="domains-template-editor-footer">
        <x-filament::button tag="a" :href="$cancelUrl" color="gray">Cancel</x-filament::button>
        <x-filament::button type="submit">Save</x-filament::button>
    </x-filament::actions>
</form>
