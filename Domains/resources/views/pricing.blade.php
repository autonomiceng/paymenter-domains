@component('layouts.app', ['title' => 'Domain prices', 'sidebar' => !$guest, 'spa' => false, 'description' => 'Registration, renewal and transfer prices for every TLD we sell.'])
@php
    $termRange = static function (array $terms): string {
        if (!$terms) { return 'Not offered'; }
        if (count($terms) === 1) { return $terms[0].($terms[0] === 1 ? ' year' : ' years'); }
        if ($terms === range(min($terms), max($terms))) { return min($terms).' to '.max($terms).' years'; }
        $last = array_pop($terms);
        return implode(', ', $terms).' or '.$last.' years';
    };
    $showTerms = collect($rows)->contains(fn($row) => $termRange($row['terms']) !== '1 year');
    $longerTerms = collect($rows)->contains(fn($row) => collect($row['terms'])->contains(fn($term) => $term > 1));
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
                <p class="domains-lede">Every TLD we sell, with its register, renew and transfer price per year.</p>
            </div>
        </div>
    </div>

    @if (!$rows)
        <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>No domain prices are available right now. Please check back later.</div></div>
    @else
        <form method="GET" action="{{ route('domains.domains.search') }}" role="search">
            <div class="domains-searchbar">
                <label for="domains-price-name" class="domains-sr">Domain name</label>
                <input id="domains-price-name" name="name" type="text" placeholder="Search for a name" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="253" required>
                <button type="submit" class="domains-btn domains-btn--primary"><x-ri-search-line class="domains-i" aria-hidden="true" />Search</button>
            </div>
        </form>


        <section class="domains-panel" aria-labelledby="domains-prices-heading">
            <h2 id="domains-prices-heading" class="domains-sr">Prices by TLD</h2>
            <div class="domains-tablewrap">
                <table class="domains-tbl domains-stackable domains-pricelist">
                    <thead>
                        <tr>
                            <th scope="col">TLD</th>
                            <th scope="col" class="domains-end">Register</th>
                            <th scope="col" class="domains-end">Renew</th>
                            <th scope="col" class="domains-end">Transfer</th>
                            @if ($showTerms)<th scope="col" class="domains-end">Register for</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row" class="domains-domain domains-cell-wide">.{{ $row['tld'] }}@if ($row['testEnvironment'] ?? false) <span class="domains-badge domains-tone-muted">Test</span>@endif</th>
                                <td class="domains-end domains-num" data-label="Register">{{ $row['register'] ?? 'Not offered' }}</td>
                                <td class="domains-end domains-num" data-label="Renew">{{ $row['renew'] ?: 'Not offered' }}</td>
                                <td class="domains-end domains-num" data-label="Transfer">{{ $row['transfer'] ?? 'Not offered' }}</td>
                                @if ($showTerms)<td class="domains-end" data-label="Register for">{{ $termRange($row['terms']) }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <p class="domains-small domains-muted">Prices plus tax where applicable.@if ($longerTerms) Longer terms are priced per term; search for a name to see each total.@endif</p>
    @endif
</div>
</div>
@include('domains::partials.client-script')
@endcomponent
