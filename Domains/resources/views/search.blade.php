@component('layouts.app', ['title' => $guest ? 'Find a domain' : 'Register a domain', 'sidebar' => !$guest, 'spa' => false, 'description' => 'Search for a domain name and see prices for every ending we offer.'])
@php
    $tldNames = array_column($endings, 'tld');
    $nameValue = $sld ?? (string) old('domain', $name);
    $termLabel = fn (int $n) => $n.' '.($n === 1 ? 'year' : 'years');
@endphp
<div class="container mt-14 mb-10">
<div class="domains">
    @include('domains::partials.styles')
    @include('domains::partials.test-banner', ['test' => $testEnvironment])
    <div>
        @if (!$guest && \Illuminate\Support\Facades\Route::has('domains.domains.index'))<a class="domains-back" href="{{ route('domains.domains.index') }}"><x-ri-arrow-left-s-line class="domains-i" aria-hidden="true" />Domains</a>@endif
        <div class="domains-head">
            <div>
                <h1 class="domains-title">{{ $guest ? 'Find a domain' : 'Register a domain' }}</h1>
                <p class="domains-lede">Type the name you want. We’ll check it across every ending we offer.</p>
            </div>
            @if (\Illuminate\Support\Facades\Route::has('domains.domains.transfer'))<a class="domains-btn domains-btn--ghost" href="{{ route('domains.domains.transfer') }}">Transfer a domain instead</a>@else
                <p class="domains-small domains-muted">Already own a domain? Search for it to see if you can transfer it to us.</p>
            @endif
        </div>
    </div>
    @include('domains::partials.notices', ['fieldKeys' => ['domain'], 'summary' => false])
    @if (!$endings)
        <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain registration isn’t open yet. Check back soon.</div></div>
    @else
        <form method="GET" action="{{ route('domains.domains.search') }}" role="search">
            <div class="domains-searchbar">
                <label for="domains-name" class="domains-sr">Domain name</label>
                <input id="domains-name" name="name" type="text" value="{{ $nameValue }}" placeholder="yourbusiness" autocomplete="off" autocapitalize="none"
                    spellcheck="false" maxlength="253" required @if ($error || $errors->has('domain')) aria-invalid="true" aria-describedby="domains-name-error" @endif @if (!$results) autofocus @endif>
                <label for="domains-tld" class="domains-sr">Ending</label>
                <select id="domains-tld" name="tld" class="domains-input">
                    @foreach ($endings as $ending)
                        <option value="{{ $ending['tld'] }}" @selected($ending['tld'] === $preferred)>.{{ $ending['tld'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="domains-btn domains-btn--primary"><x-ri-search-line class="domains-i" aria-hidden="true" />Search</button>
            </div>
            @if ($error || $errors->has('domain'))<p id="domains-name-error" class="domains-error" role="alert">{{ $error ?? $errors->first('domain') }}</p>@endif
            <p class="domains-endings" aria-label="Endings and first-year prices">
                @foreach ($endings as $ending)
                    <span><b>.{{ $ending['tld'] }}</b>@if ($ending['price']) {{ $ending['price'] }}/yr @endif</span>
                @endforeach
                <a class="domains-a" href="{{ route('domains.domains.pricing') }}">All prices</a>
            </p>
        </form>

        @if ($notice)
            <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>{{ $notice }} Here’s <strong>{{ $sld }}</strong> with each of them.</div></div>
        @endif

        @if ($results)
            <section aria-labelledby="domains-results-heading">
                <h2 id="domains-results-heading" class="domains-sr">Results for {{ $sld }}</h2>
                <ul class="domains-results" data-domains-results="{{ route('domains.domains.check') }}" data-request-key="{{ $checkKey }}" @if ($years) data-years="{{ $years }}" @endif>
                    @foreach ($results as $index => $result)
                        @include('domains::partials.result', ['first' => $index === 0])
                    @endforeach
                </ul>
                <p class="domains-small domains-muted" style="margin-top:.75rem">
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
