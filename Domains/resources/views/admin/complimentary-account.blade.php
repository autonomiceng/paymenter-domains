{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One line under the customer's title: the domain price setting, the one button that changes it and its last change. --}}
<div class="domains-comp">
    @if ($allowed)
    <div class="domains-comp-line">
        @if ($on)
            {{ \Paymenter\Extensions\Servers\Domains\Admin\Support\StatusMark::head('success', 'Complimentary domains', 'customer-'.$this->customer) }}
        @else
            <span class="domains-comp-state">Standard domain prices</span>
        @endif
        <span class="domains-comp-act">{{ $on ? $this->turnOffAction : $this->turnOnAction }}</span>
        @if ($last)
            <span class="domains-comp-sub">{{ $last }}</span>
        @endif
    </div>
    <x-filament-actions::modals />
    @endif
</div>
