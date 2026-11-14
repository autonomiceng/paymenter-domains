{{-- MIT, Copyright (c) 2026 Autonomic Engineering. In the customer editor's header actions: the one domain price action that applies, and its confirm. --}}
<div class="domains-comp">
    @if ($allowed)
    {{ $this->turnOnAction }}{{ $this->turnOffAction }}
    <x-filament-actions::modals />
    @endif
</div>
