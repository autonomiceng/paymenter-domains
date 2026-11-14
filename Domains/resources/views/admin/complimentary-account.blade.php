{{-- MIT, Copyright (c) 2026 Autonomic Engineering. In the customer editor's header actions: the one domain price action that applies, and its confirm. --}}
<div class="domains-comp">
    @if ($allowed)
    {{-- A directly printed action renders even when hidden, so print only the visible one. --}}
    @foreach ([$this->turnOnAction, $this->turnOffAction] as $action)
        @if ($action->isVisible()){{ $action }}@endif
    @endforeach
    <x-filament-actions::modals />
    @endif
</div>
