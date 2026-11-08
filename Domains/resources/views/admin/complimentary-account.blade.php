{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Under the customer's title: the domain price setting and the one button that changes it, then its last change. --}}
<div class="domains-comp">
    @if ($allowed)
    <div class="domains-comp-line">
        @if ($on)
            {{ \Paymenter\Extensions\Servers\Domains\Admin\Support\StatusMark::head('success', 'Complimentary domains', 'customer-'.$this->customer) }}
        @else
            <span class="domains-comp-state">Standard domain prices</span>
        @endif
        {{ $on ? $this->turnOffAction : $this->turnOnAction }}
    </div>
    {{-- Always present: on narrow screens it keeps two lines, so the header buttons below never move when history appears. --}}
    <p class="domains-comp-last">{{ $last ?? 'No changes yet.' }}</p>
    <x-filament-actions::modals />
    @endif
</div>
