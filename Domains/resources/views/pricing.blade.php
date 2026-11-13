@component('layouts.app', ['title' => 'Domain prices', 'sidebar' => !$guest, 'spa' => false, 'description' => 'Registration, renewal and transfer prices for every TLD we sell.'])
@php
    $termRange = static function (array $terms): string {
        if (!$terms) { return 'Not offered'; }
        if (count($terms) === 1) { return $terms[0].($terms[0] === 1 ? ' year' : ' years'); }
        if ($terms === range(min($terms), max($terms))) { return min($terms).' to '.max($terms).' years'; }
        $last = array_pop($terms);
        return implode(', ', $terms).' or '.$last.' years';
    };
    $popular = ['com' => 0, 'net' => 1, 'org' => 2];
    usort($rows, static fn($a, $b) => ($popular[$a['tld']] ?? 3) <=> ($popular[$b['tld']] ?? 3) ?: strcmp($a['tld'], $b['tld']));
    $showTerms = collect($rows)->contains(fn($row) => $termRange($row['terms']) !== '1 year');
    $firstYearDiffers = collect($rows)->contains(fn($row) => \Paymenter\Extensions\Servers\Domains\Presentation\FirstYearPrice::differs($row['register'] ?? null, $row['renew'] ?? null));
    $longerTerms = collect($rows)->contains(fn($row) => collect($row['terms'])->contains(fn($term) => $term > 1));
    // Not for sale is a state with its hollow gray mark, never bare words (DESIGN.md P-AVL-6).
    $notForSale = fn (?string $reason = null) => \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::state(\Paymenter\Extensions\Servers\Domains\Presentation\State::NotForSale, \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer, $reason);
    // The whole site in test mode already says so in the top bar; a Test tag after a TLD marks only that TLD (P-LAY-12).
    $tldTest = fn (array $row) => ($row['testEnvironment'] ?? false) && !$testEnvironment;
@endphp
<div class="container mt-14 mb-10">
<div class="domains">
    @include('domains::partials.styles')
    @include('domains::partials.test-banner', ['test' => $testEnvironment])
    @include('domains::partials.store-head', ['title' => 'Domain prices', 'lede' => 'Every TLD we sell, with its register, renew and transfer price per year.'])

    @if (!$rows)
        <div class="domains-notice domains-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain prices aren’t available right now. Please check back later.</div></div>
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
            <div id="domains-price-tools" hidden class="domains-panel__b">
                <label for="domains-price-filter" class="domains-label">Filter by TLD</label>
                <input id="domains-price-filter" class="domains-input" type="search" placeholder="e.g. .com" autocomplete="off" spellcheck="false" aria-controls="domains-price-rows">
                <p id="domains-price-count" role="status" aria-live="polite"></p>
                <button id="domains-price-all" class="domains-btn" type="button" hidden>Show all</button>
            </div>
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
                    <tbody id="domains-price-rows">
                        @foreach ($rows as $row)
                            <tr data-tld="{{ $row['tld'] }}">
                                <th scope="row" class="domains-domain domains-mono">.{{ $row['tld'] }}@if ($tldTest($row)){{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label(false, true) }}@endif</th>
                                <td class="domains-end domains-num" data-label="Register">@if (($row['register'] ?? null) !== null){{ $row['register'] }}@else<span data-not-for-sale>{{ $notForSale() }}</span>@endif</td>
                                <td class="domains-end domains-num" data-label="Renew">@if ($row['renew']){{ $row['renew'] }}@else<x-domains::customer.unread :cause="\Paymenter\Extensions\Servers\Domains\Presentation\Missing::ASK_FIRST" />@endif</td>
                                <td class="domains-end domains-num" data-label="Transfer">@if ($row['transfer'] !== null){{ $row['transfer'] }}@elseif ($row['transfersOn'] ?? false)<x-domains::customer.unread :cause="\Paymenter\Extensions\Servers\Domains\Presentation\Missing::ASK_FIRST" />@else<span data-not-for-sale>{{ $notForSale('We don’t take transfers for this TLD.') }}</span>@endif</td>
                                @if ($showTerms)<td class="domains-end" data-label="Register for">{{ $termRange($row['terms']) }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p id="domains-price-empty" role="status" class="domains-panel__b" hidden>No TLDs match your filter.</p>
        </section>
        @include('domains::partials.price-filter')
        <p class="domains-small domains-muted domains-fine">{{ \Paymenter\Extensions\Servers\Domains\Presentation\Fact::IN_USD }} Prices plus tax where applicable.{{ $firstYearDiffers ? ' '.\Paymenter\Extensions\Servers\Domains\Presentation\FirstYearPrice::LIST : '' }}@if ($longerTerms) Longer terms are priced per term; search for a name to see each total.@endif</p>
    @endif
</div>
</div>
@include('domains::partials.client-script')
@endcomponent
