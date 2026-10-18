@component('layouts.app', ['title' => 'Domain prices', 'sidebar' => !$guest, 'spa' => false, 'description' => 'Registration, renewal and transfer prices for every domain ending we offer.'])
@php
    $termRange = static function (array $terms): string {
        if (!$terms) { return '—'; }
        if (count($terms) === 1) { return $terms[0].($terms[0] === 1 ? ' year' : ' years'); }
        if ($terms === range(min($terms), max($terms))) { return min($terms).' to '.max($terms).' years'; }
        $last = array_pop($terms);
        return implode(', ', $terms).' or '.$last.' years';
    };
@endphp
<div class="container mt-14 mb-10">
<div class="domains">
    @include('domains::partials.styles')
    @include('domains::partials.test-banner', ['test' => $testEnvironment])
    <div>
        @if (!$guest && \Illuminate\Support\Facades\Route::has('domains.domains.index'))<a class="domains-back" href="{{ route('domains.domains.index') }}"><x-ri-arrow-left-s-line class="domains-i" aria-hidden="true" />Domains</a>@endif
        <div class="domains-head">
            <div>
                <h1 class="domains-title">Domain prices</h1>
                <p class="domains-lede">Every ending we offer, with its registration, renewal and transfer price.</p>
            </div>
        </div>
    </div>

    @if (!$rows)
        <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain registration isn’t open yet. Check back soon.</div></div>
    @else
        <form method="GET" action="{{ route('domains.domains.search') }}" role="search">
            <div class="domains-searchbar">
                <label for="domains-price-name" class="domains-sr">Domain name</label>
                <input id="domains-price-name" name="name" type="text" placeholder="Search for a name" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required>
                <button type="submit" class="domains-btn domains-btn--primary"><x-ri-search-line class="domains-i" aria-hidden="true" />Search</button>
            </div>
        </form>


        <section class="domains-panel" aria-labelledby="domains-prices-heading">
            <h2 id="domains-prices-heading" class="domains-sr">Prices by ending</h2>
            <div class="domains-tablewrap">
                <table class="domains-tbl domains-stackable domains-pricelist">
                    <thead>
                        <tr>
                            <th scope="col">Ending</th>
                            <th scope="col" class="domains-end">Register, first year</th>
                            <th scope="col" class="domains-end">Renew, per year</th>
                            <th scope="col" class="domains-end">Transfer, adds 1 year</th>
                            <th scope="col" class="domains-end">Register for</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row" class="domains-domain domains-cell-wide">.{{ $row['tld'] }}</th>
                                <td class="domains-end domains-num" data-label="Register">{{ $row['register'] ?? '—' }}</td>
                                <td class="domains-end domains-num" data-label="Renew">{{ $row['renew'] ? $row['renew'].'/yr' : '—' }}</td>
                                <td class="domains-end domains-num" data-label="Transfer">{{ $row['transfer'] ?? '—' }}</td>
                                <td class="domains-end" data-label="Register for">{{ $termRange($row['terms']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <p class="domains-small domains-muted">Prices plus tax where applicable. Each registration term has its own total. Search for a name to see the price for each term.</p>
    @endif
</div>
</div>
@include('domains::partials.client-script')
@endcomponent
