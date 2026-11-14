{{-- Adapted from Enom 444f31c search + client-script; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $state = $result['status'];
    [$label, $tone] = match ($state) {
        'available' => ['Available', 'ok'], 'transferable' => [($result['transferPage'] ?? false) ? 'Can transfer' : 'Taken', ($result['transferPage'] ?? false) ? 'ok' : 'muted'],
        'taken' => ['Taken', 'muted'], 'transfer_refused' => ['Can’t transfer', $result['refusalTone'] ?? 'muted'],
        'transfer_wait' => ['Wait to transfer', $result['refusalTone'] ?? 'warn'], 'transfer_unconfirmed' => ['Couldn’t check', 'muted'],
        'premium' => ['Premium name', 'muted'], 'held' => ['Not available', 'muted'],
        'busy' => ['Try again in a moment', 'warn'],
        'idle' => ['Checking', 'muted'], 'in_cart' => ['In your cart', 'ok'], default => ['Couldn’t check', 'warn'],
    };
    $statusClass = 'domains-badge domains-tone-'.$tone;
    if ($result['transferPage'] ?? false) {
        $statusClass = in_array($state, ['error', 'transfer_unconfirmed'], true)
            ? 'domains-result-label' : 'domains-badge domains-status domains-tone-'.$tone;
    }
    if ($state === 'idle') { $statusClass = 'domains-checking'; }
    // Replace mode (Change on the cart): the chosen name takes over that cart line.
    $replace ??= null;
    // The ending asked for is not sold here, so this row is a different name.
    $instead ??= false;
@endphp
<li class="domains-result {{ ($first ?? false) ? 'is-first' : '' }}" data-domains-result data-domain="{{ $result['domain'] }}" data-state="{{ $state }}">
    <div class="domains-result__name"><span>{{ $result['sld'] }}</span><wbr><span class="domains-tld">.{{ $result['tld'] }}</span>@if ($result['testEnvironment'] ?? false) <span class="domains-badge domains-tone-muted">Test</span>@endif</div>
    <div class="domains-result__status"><span class="{{ $statusClass }}">{{ $label }}</span></div>
    <div class="domains-result__price">{{ $state==='premium' ? 'Not sold here' : '' }}</div>
    <div class="domains-result__action">
        @if (in_array($state, ['available', 'transferable'], true))
            <form method="POST" action="{{ route('domains.domains.order') }}">
                @include('domains::partials.form-key', ['keys' => ['order' => $result['key']], 'action' => 'order'])
                @if ($replace)<input type="hidden" name="replace" value="{{ $replace['id'] }}">@endif
                <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                <input type="hidden" name="action" value="{{ $state === 'available' ? 'register' : 'transfer' }}">
                <dl class="domains-term-rows">
                <div><dt>@if ($state==='available' && count($result['terms']??[])>1)<label for="domains-term-{{ $result['domain'] }}">Years</label>@else Years @endif</dt><dd>
                @if ($state === 'available' && count($result['terms'] ?? []) > 1)
                    <select class="domains-input domains-term-select" id="domains-term-{{ $result['domain'] }}" name="term">
                        @foreach ($result['terms'] as $years => $total)<option value="{{ $years }}" data-total="{{ $total }}" @selected($years === $result['term'])>{{ \Paymenter\Extensions\Servers\Domains\Presentation\TermChoice::label($years, $total) }}</option>@endforeach
                    </select>
                @else<input type="hidden" name="term" value="{{ $result['term'] }}">{{ \Paymenter\Extensions\Servers\Domains\Presentation\TermChoice::years((int) $result['term']) }}@endif
                </dd></div>
                <div><dt>Total</dt><dd class="domains-result__price"><strong>{{ $result['price'] }}</strong></dd></div>
                @php $firstYear = $state === 'available' ? \Paymenter\Extensions\Servers\Domains\Presentation\FirstYearPrice::note($result['terms'][1] ?? null, $result['renew'] ?? null) : null; @endphp
                <div><dt>Annual renewal</dt><dd>{{ $result['renew'] }}/yr @if ($firstYear)<span class="domains-sub" data-first-year-note>{{ $firstYear }}</span>@endif</dd></div>
                </dl>
                @if ($instead && !$replace && $state === 'available')
                <button type="submit" class="domains-btn domains-btn--secondary">Add {{ $result['domain'] }} instead</button>
                @else
                <button type="submit" class="domains-btn domains-btn--{{ $state === 'available' ? 'primary' : 'secondary' }}" aria-label="{{ $replace ? 'Use '.$result['domain'].($replace['name'] ? ' instead of '.$replace['name'] : '') : ($state === 'available' ? 'Add '.$result['domain'].' to cart' : 'Transfer '.$result['domain'].' to us') }}">{{ $replace ? 'Use this name' : ($state === 'available' ? 'Add to cart' : 'Transfer') }}</button>
                @endif
            </form>
        @elseif ($state === 'in_cart')<a class="domains-btn domains-btn--secondary" href="{{ route('cart') }}">Go to cart</a>
        @elseif (in_array($state, ['error', 'busy', 'transfer_unconfirmed'], true))<button type="button" class="domains-btn domains-btn--secondary" data-domains-retry>Retry</button>@endif
    </div>
    <p class="domains-result__note domains-small domains-muted">{{ $result['message'] ?? '' }}@if ($result['register'] ?? false) <a class="domains-a" href="{{ route('domains.domains.search', ['name' => $result['domain']]) }}">Register this domain</a>@endif@if ($state === 'taken' && ($result['transferEnding'] ?? false)) Own it? <a class="domains-a" href="{{ route('domains.domains.transfer', ['name' => $result['sld'], 'tld' => $result['tld']]) }}">Transfer it here</a>@endif
        @if ($result['support'] ?? false)@php($support = \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl())@if ($support) <a class="domains-a" href="{{ $support }}">Contact support</a>@endif @endif</p>
</li>
