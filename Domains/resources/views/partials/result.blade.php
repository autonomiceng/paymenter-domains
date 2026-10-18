{{-- Adapted from Enom 444f31c search + client-script; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $state = $result['status'];
    [$label, $tone] = match ($state) {
        'available' => ['Available', 'ok'], 'transferable', 'taken' => ['Taken', 'muted'],
        'premium' => ['Premium name', 'muted'], 'held' => ['Not available', 'muted'],
        'idle' => ['Checking', 'muted'], default => ['Couldn’t check', 'warn'],
    };
@endphp
<li class="domains-result {{ ($first ?? false) ? 'is-first' : '' }}" data-domains-result data-domain="{{ $result['domain'] }}" data-state="{{ $state }}">
    <div class="domains-result__name"><span>{{ $result['sld'] }}</span><wbr><span class="domains-tld">.{{ $result['tld'] }}</span></div>
    <div class="domains-result__status"><span class="{{ $state === 'idle' ? 'domains-checking' : 'domains-badge domains-tone-'.$tone }}">{{ $label }}</span></div>
    <div class="domains-result__price">@if (in_array($state, ['available', 'transferable'], true))<strong>{{ $state === 'transferable' ? 'Transfer for '.$result['price'] : $result['price'].' for '.$result['term'].($result['term'] === 1 ? ' year' : ' years') }}</strong><small>{{ $state === 'transferable' ? 'Adds 1 year' : 'Renews at '.$result['renew'].'/yr' }}</small>@elseif ($state === 'premium')
        Not sold here
    @endif</div>
    <div class="domains-result__action">
        @if (in_array($state, ['available', 'transferable'], true))
            <form method="POST" action="{{ route('domains.domains.order') }}">
                @include('domains::partials.form-key', ['keys' => ['order' => $result['key']], 'action' => 'order'])
                <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                <input type="hidden" name="action" value="{{ $state === 'available' ? 'register' : 'transfer' }}">
                @if ($state === 'available' && count($result['terms'] ?? []) > 1)
                    <label class="domains-sr" for="domains-term-{{ $result['domain'] }}">Register for</label>
                    <select class="domains-input" id="domains-term-{{ $result['domain'] }}" name="term">
                        @foreach ($result['terms'] as $years => $total)<option value="{{ $years }}" @selected($years === $result['term'])>{{ $total }} for {{ $years }} {{ $years === 1 ? 'year' : 'years' }}</option>@endforeach
                    </select>
                @else<input type="hidden" name="term" value="{{ $result['term'] }}">@endif
                <button type="submit" class="domains-btn domains-btn--{{ $state === 'available' ? 'primary' : 'secondary' }}" aria-label="{{ $state === 'available' ? 'Register '.$result['domain'] : 'Transfer '.$result['domain'].' to us' }}">{{ $state === 'available' ? 'Register' : 'Transfer' }}</button>
            </form>
        @elseif ($state === 'error')<button type="button" class="domains-btn domains-btn--secondary" data-domains-retry>Retry</button>@endif
    </div>
    <p class="domains-result__note domains-small domains-muted">{{ $result['message'] ?? '' }}</p>
</li>
