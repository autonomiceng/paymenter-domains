@if ($closed = session('domains.closed'))
    <div class="domains-notice domains-notice--warn" role="status" data-domains-order-closed>
        <x-ri-information-fill aria-hidden="true" />
        <div>
            {{ $closed['text'] }} <a class="domains-a" href="#domains-name">{{ $closed['link'] }}</a>
            @if ($closed['also'])<p>We also closed {{ implode(', ', $closed['also']) }} because it was on the same invoice. You can order it again.</p>@endif
            @if ($closed['invoice'])<p><a class="domains-a" href="{{ route('invoices.show', $closed['invoice']) }}">Pay for the rest of your order</a></p>@endif
        </div>
    </div>
@endif
