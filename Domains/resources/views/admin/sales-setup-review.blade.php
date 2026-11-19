{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
    use Paymenter\Extensions\Servers\Domains\Admin\Support\{RegistrarFacts, SalesSetupReview, SettingsActions, Staff, TldFacts};
    use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value;
    use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader, State};
    [$review, $result, $problem, $previous] = [$page->review, $page->outcome, $page->problem, $page->previousReview];
    $rows = $result['outcomes'] ?? $review['rows'] ?? [];
    $server = $review['server'] ?? (reset($rows)['server_id'] ?? null);
    $money = static fn (?string $value): string => Fact::money($value)?->text ?? 'None';
@endphp

<div class="domains-template-editor">
@if ($previous !== [])
    @php $changes = SalesSetupReview::changes($previous, $rows, Staff::can(Staff::COSTS)); @endphp
    <div class="fi-prose domains-space" wire:key="sales-setup-review-changed-{{ hash('sha256', $review['review_key'] ?? '') }}">
        <div role="alert" tabindex="-1" x-data
            x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'center' }) })">
            {{ Value::state(State::NeedsReview, Reader::Staff, 'Nothing was saved. Prices or settings changed, or the review expired.') }}
        </div>
        @if ($changes !== [])
            <ul>
                @foreach ($changes as $suffix => $groups)
                    @foreach ($groups as $change => $terms)
                        <li><span class="fi-font-mono">.{{ $suffix }}</span> {{ implode(', ', $terms) }} {{ $change }}.</li>
                    @endforeach
                @endforeach
            </ul>
        @endif
        @if ($problem)
            <p>{{ $problem }}</p>
        @elseif (isset($review['review_key']))
            <p>Check the updated review before saving again.</p>
        @endif
    </div>
@elseif ($problem)
    <p role="alert" class="domains-warn" tabindex="-1" wire:key="sales-setup-problem-{{ md5($problem) }}" x-data
        x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'center' }) })">{{ $problem }}</p>
@endif

@if ($rows === [])
    <x-filament::button type="button" wire:click="reviewChanges" wire:loading.attr="disabled">Review changes</x-filament::button>
@else
    @php
        $names = \App\Models\Server::whereIn('id', [$server, ...array_filter(array_column($rows, 'seller_before')), ...array_column($rows, 'problem_account')])->pluck('name', 'id');
        $account = $names[$server] ?? 'Selected registrar account';
        $accountLink = RegistrarFacts::accountLink($server, 'Open '.$account);
        $canSync = in_array('Seller price missing.', array_column($rows, 'reason'), true) && SettingsActions::syncAll($server)->isVisible();
        $status = ['ready' => 'Ready to save', 'saved' => 'Saved', 'blocked' => 'Refused', 'unchanged' => 'Unchanged'];
    @endphp
    <div class="fi-prose" wire:key="{{ $result ? 'sales-setup-result' : 'sales-setup-review' }}">
        <p @if ($previous === []) tabindex="-1" x-data
            x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'center' }) })" @endif>
            @if ($result)
                Saved {{ count($result['rows']) }} of {{ count($rows) }} {{ count($rows) === 1 ? 'TLD' : 'TLDs' }}.
            @else
                Registrar for new orders: {{ $account }}.
            @endif
            Existing invoices keep their prices.
        </p>
        @foreach ($rows as $suffix => $row)
            @php
                $blocked = $row['status'] === 'blocked';
                $state = SalesSetupReview::saleState($row);
                $sellerName = $names[$row['seller_before']] ?? 'the current registrar';
                $oldPrices = $previous[$suffix]['prices'] ?? [];
                $longer = array_diff_key(array_filter($row['prices'] + $oldPrices, static fn ($price, $term) =>
                    $price['after'] !== null || $price['before'] !== null || ($oldPrices[$term]['after'] ?? null) !== null,
                    ARRAY_FILTER_USE_BOTH), SalesSetupReview::ANNUAL);
            @endphp
            <section aria-label=".{{ $suffix }}">
                <h3><span class="fi-font-mono">.{{ $suffix }}</span> {{ $status[$row['status']] }}</h3>
                @if ($blocked)
                    {{ Value::state($state, Reader::Staff, $state === State::ForSale ? 'New orders stay with '.$sellerName.'.' : null) }}
                    @if ($state === State::ForSale && !$result)
                        @php
                            $currentPrices = array_intersect_key($row['previous_sales'], SalesSetupReview::ANNUAL)
                                ?: array_slice($row['previous_sales'], 0, 1, true);
                        @endphp
                        @foreach ($currentPrices as $term => $amount)
                            <div class="domains-sub">{{ SalesSetupReview::termLabel($term) }} {{ $money($amount) }}</div>
                        @endforeach
                    @endif
                @elseif (($row['facts_state'] ?? null) === 'waiting_for_evidence')
                    {{ Value::state(State::NotForSale, Reader::Staff, TldFacts::TERMS_EVIDENCE_MISSING.'.') }}
                @elseif ($state === State::Checking)
                    {{ $result
                        ? Value::state($state, Reader::Staff, SalesSetupReview::termsCheck($row, true))
                        : Value::fact(Fact::entered('Checks after saving'), SalesSetupReview::termsCheck($row, false)) }}
                @else
                    {{ Value::state($state, Reader::Staff, match (true) {
                        $result !== null => null,
                        $row['previous_sales'] === [] && $state === State::ForSale => 'Starts selling after saving.',
                        $row['previous_sales'] !== [] && $state === State::NotForSale => 'Stops selling after saving.',
                        default => null,
                    }) }}
                @endif
                <p>
                    @if (!$blocked && isset($row['seller_before']) && $row['seller_before'] !== $server)
                        New orders {{ $result ? 'moved' : 'move' }} from {{ $sellerName }}.
                    @endif
                    @if ($blocked && !$result)
                        Draft prices for {{ $account }}.
                    @endif
                    @if ($blocked)
                        {{ SalesSetupReview::blocked($row['reason'], $names[$row['problem_account'] ?? $server] ?? 'the registrar account') }}
                        @if ($canSync && $row['reason'] === 'Seller price missing.')
                            Use Sync prices on this account to check for new prices. Registrar terms are checked separately.
                        @endif
                    @elseif ($row['orders_paused'])
                        Orders are paused on {{ $account }}.
                    @endif
                    @if ($row['orders_paused'] || in_array($row['reason'], ['Seller price missing.', 'Seller price unreadable.', 'Seller account off.'], true))
                        {{ $accountLink }}
                    @endif
                </p>
                @if ($result && in_array($suffix, $result['facts_hint_failed'], true))
                    <p role="status">The changes saved. The registrar terms check could not be scheduled. {{ $accountLink }}</p>
                @endif
                @if (!$blocked && isset($row['longest_after'], $row['pricing_after']))
                    <p>
                        {{ match ($row['actions_after']) {
                            ['register', 'transfer'] => 'Registrations and transfers',
                            ['register'] => 'Registrations only',
                            ['transfer'] => 'Transfers only',
                            default => 'No new orders',
                        } }}.
                        @if (in_array('register', $row['actions_after'], true))
                            Longest term {{ $row['longest_after'] }} {{ $row['longest_after'] === 1 ? 'year' : 'years' }}.
                        @endif
                        {{ $row['pricing_after'] === 'custom' ? 'Custom pricing' : 'Default pricing' }}.
                    </p>
                @endif
                @foreach ([array_keys(SalesSetupReview::ANNUAL), array_keys($longer)] as $terms)
                    @continue($terms === [] || ($result && $blocked))
                    @if (!$loop->first)
                        <details>
                            <summary>2 to 10 year registrations for .{{ $suffix }}</summary>
                            @if ($blocked)
                                <p>These draft prices are for reference.</p>
                            @elseif (isset($row['longest_after']) && $row['longest_after'] < 10)
                                <p>Registration limit is {{ $row['longest_after'] }} {{ $row['longest_after'] === 1 ? 'year' : 'years' }}.
                                    Prices for longer terms are shown for reference.</p>
                            @endif
                    @endif
                    <div class="domains-preview">
                        <table aria-label="{{ $blocked ? 'Draft prices' : 'Prices' }} for .{{ $suffix }}">
                            <thead><tr>
                                <th scope="col">Price</th>
                                @if (!$result)<th scope="col">Saved</th>@endif
                                <th scope="col">{{ $blocked ? 'Draft amount' : ($result ? 'Amount' : 'After saving') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($terms as $term)
                                    @php
                                        $price = $row['prices'][$term] ?? [];
                                        $amount = Fact::money($price['after'] ?? null);
                                        $was = Fact::money($oldPrices[$term]['after'] ?? null);
                                    @endphp
                                    <tr>
                                        <th scope="row">{{ SalesSetupReview::termLabel($term) }}</th>
                                        @if (!$result)<td class="domains-money">{{ Fact::money($price['before'] ?? null)?->text ?? 'No price saved' }}</td>@endif
                                        <td>
                                            @if (isset($previous[$suffix]) && $was?->text !== $amount?->text)
                                                <div class="domains-sub">Previously proposed {{ $was?->text ?? 'no price' }}</div>
                                            @endif
                                            <span class="domains-money">{{ $amount?->text ?? SalesSetupReview::missingDraft($row, $price) }}</span>
                                            @if ($blocked)
                                                <div class="domains-sub">Not sold</div>
                                            @elseif ($amount !== null && !$price['available'])
                                                <div class="domains-sub">{{ SalesSetupReview::priceNote($term, $price['reason'], $row['orders_paused']) ?? 'Not sold' }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (!$loop->first)</details>@endif
                @endforeach
                @if (!$blocked && $row['retained_before'] !== $row['retained_after'])
                    <p>Annual renewal for existing domains on this account
                        {{ $result ? $money($row['retained_after']) : $money($row['retained_before']).' to '.$money($row['retained_after']) }}.
                    </p>
                @endif
                @if ($result && $row['tld_id'] !== null)
                    <x-filament::link :href="TldResource::getUrl('view', ['record' => $row['tld_id']])" size="sm">Open .{{ $suffix }}</x-filament::link>
                @endif
            </section>
        @endforeach
        @if ($result === null && !isset($review['review_key']) && in_array('blocked', array_column($rows, 'status'), true))
            <x-filament::button type="button" wire:click="reviewChanges" wire:loading.attr="disabled">Review again</x-filament::button>
        @endif
        @if ($result)<x-filament::link :href="$page->catalogueUrl()">Back to TLDs &amp; pricing</x-filament::link>@endif
    </div>
@endif
@if (isset($review['review_key']))
    <div class="domains-space">{{ $page->confirmationForm }}</div>
@endif
</div>
