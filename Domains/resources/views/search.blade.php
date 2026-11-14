@component('layouts.app', ['title' => $guest ? 'Find a domain' : 'Register a domain', 'sidebar' => !$guest, 'spa' => false, 'description' => 'Search for a domain name and see prices for every TLD we sell.'])
@php
    $tldNames = array_column($endings, 'tld');
    $nameValue = $sld ?? (string) old('domain', $name);
    $termLabel = fn (int $n) => $n.' '.($n === 1 ? 'year' : 'years');
@endphp
<div class="container mt-14 mb-10">
<div class="domains">
    @include('domains::partials.styles')
    @include('domains::partials.test-banner', ['test' => $testEnvironment])
    @include('domains::partials.store-head', ['title' => 'Get a domain', 'lede' => 'Type the name you want. We check it across every TLD we sell.'] + ((\Illuminate\Support\Facades\Route::has('domains.domains.transfer') && ($transfersOffered ?? collect($endings)->contains(fn($row) => $row['transfer'] !== null))) ? ['tabs' => 'register'] : []))
    @include('domains::partials.notices', ['fieldKeys' => ['domain'], 'summary' => false])
    @include('domains::partials.order-closed')
    @if ($replace ?? null)
        <div class="domains-notice" role="status" data-domains-replacing><p>Replacing {{ $replace['name'] }}.</p> <a class="domains-a" href="{{ route('cart') }}">Cancel</a></div>
    @endif
    @if (!$endings)
        <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain registration isn’t available right now. Please check back later.</div></div>
    @else
        <form method="GET" action="{{ route('domains.domains.search') }}" role="search">
            @if ($replace ?? null)<input type="hidden" name="replace" value="{{ $replace['id'] }}">@endif
            <div class="domains-searchbar">
                <label for="domains-name" class="domains-sr">Domain name</label>
                <input id="domains-name" name="name" type="text" value="{{ $nameValue }}" placeholder="yourbusiness" autocomplete="off" autocapitalize="none"
                    spellcheck="false" maxlength="253" required @if ($error || $errors->has('domain')) aria-invalid="true" aria-describedby="domains-name-error" @endif @if (!$results) autofocus @endif>
                <label for="domains-tld" class="domains-sr">TLD</label>
                <select id="domains-tld" name="tld" class="domains-input">
                    @foreach ($endings as $ending)
                        <option value="{{ $ending['tld'] }}" @selected($ending['tld'] === $preferred)>.{{ $ending['tld'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="domains-btn domains-btn--primary"><x-ri-search-line class="domains-i" aria-hidden="true" />Search</button>
            </div>
            @if ($error || $errors->has('domain'))<p id="domains-name-error" class="domains-error" role="alert">{{ $error ?? $errors->first('domain') }}</p>@endif
            <p class="domains-endings" aria-label="First-year prices">
                @foreach ($endings as $ending)
                    <span><b class="domains-mono">.{{ $ending['tld'] }}</b>@if ($ending['price']) {{ $ending['price'] }} first year @endif</span>
                @endforeach
                <a class="domains-a domains-tap" href="{{ route('domains.domains.pricing') }}">All prices</a>
            </p>
        </form>

        @if ($notice)
            <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>{{ $notice }}@if ($sld) Here’s <strong>{{ $sld }}</strong> with each of them.@endif</div></div>
        @endif

        @if ($results)
            <section aria-labelledby="domains-results-heading">
                <h2 id="domains-results-heading" class="domains-sr">Results for {{ $sld }}</h2>
                <ul class="domains-results" data-support-url="{{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl() }}" data-domains-results="{{ route('domains.domains.check') }}" data-request-key="{{ $checkKey }}" @if ($replace ?? null) data-replace="{{ $replace['id'] }}" @endif @if ($notice) data-instead="1" @endif @if ($years) data-years="{{ $years }}" @endif>
                    @foreach ($results as $index => $result)
                        @include('domains::partials.result', ['first' => $index === 0])
                    @endforeach
                </ul>
                <p class="domains-small domains-muted domains-fine" style="margin-top:.75rem">
                    All amounts in USD.
                    @if ($guest)
                        Prices plus tax where applicable. You’ll sign in or create an account at checkout.
                    @else
                        Prices plus tax where applicable.
                    @endif
                    Premium names aren’t available here.
                </p>
            </section>
        @endif
    @endif
</div>
</div>
@include('domains::partials.client-script')
@endcomponent
