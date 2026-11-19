{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
    use Paymenter\Extensions\Servers\Domains\Admin\Support\{RegistrarFacts, SalesSetupReview, SettingsActions};
    use Paymenter\Extensions\Servers\Domains\Presentation\Fact;
    [$review, $result, $problem] = [$page->review, $page->outcome, $page->problem];
    $rows = $result['outcomes'] ?? $review['rows'] ?? [];
    $server = $review['server'] ?? (reset($rows)['server_id'] ?? null);
    $money = static fn (?string $value): string => Fact::money($value)?->text ?? 'None';
    $amounts = static fn (array $row) => array_filter($row['prices'], static fn ($price) => $price['after'] !== null
        || ($row['status'] !== 'blocked' && $price['before'] !== null));
@endphp

<div class="domains-template-editor">
@if ($problem)
    <p role="alert" class="domains-warn" tabindex="-1" wire:key="sales-setup-problem-{{ md5($problem) }}" x-data
        x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'center' }) })">{{ $problem }}</p>
@endif

@if ($rows === [])
    <x-filament::button type="button" wire:click="reviewChanges" wire:loading.attr="disabled">Review changes</x-filament::button>
@else
    @php
        $names = \App\Models\Server::whereIn('id', [$server, ...array_filter(array_column($rows, 'seller_before'))])->pluck('name', 'id');
        $account = $names[$server] ?? 'Selected registrar account';
        $accountLink = RegistrarFacts::accountLink($server, 'Open '.$account);
        $canSync = in_array('Seller price missing.', array_column($rows, 'reason'), true) && SettingsActions::syncAll($server)->isVisible();
        $status = ['ready' => 'Ready to save', 'saved' => 'Saved', 'blocked' => 'Refused', 'unchanged' => 'Unchanged'];
    @endphp
    <div class="fi-prose" wire:key="{{ $result ? 'sales-setup-result' : 'sales-setup-review' }}">
        <p tabindex="-1" x-data x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'center' }) })">
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
                $sellsBefore = $row['previous_sales'] !== [];
                $sellsAfter = $blocked ? $sellsBefore : SalesSetupReview::sells($row['prices']);
                $prices = $amounts($row);
                $yearly = array_intersect_key(SalesSetupReview::ANNUAL, $prices);
                $longer = array_diff_key($prices, SalesSetupReview::ANNUAL);
            @endphp
            <section aria-label=".{{ $suffix }}">
                <h3><span class="fi-font-mono">.{{ $suffix }}</span> {{ $status[$row['status']] }}</h3>
                <p>
                    @if ($result || $sellsBefore === $sellsAfter)
                        {{ $sellsAfter ? 'For sale' : 'Not for sale' }}.
                    @else
                        {{ $sellsAfter ? 'Starts selling after saving.' : 'Stops selling after saving.' }}
                    @endif
                    @if (!$blocked && isset($row['seller_before']) && $row['seller_before'] !== $server)
                        New orders {{ $result ? 'moved' : 'move' }} from {{ $names[$row['seller_before']] ?? 'the previous account' }}.
                    @endif
                    @if ($blocked)
                        {{ SalesSetupReview::blocked($row['reason']) }}
                        @if ($canSync && $row['reason'] === 'Seller price missing.')
                            Use Sync prices on this account to check for new prices. Registrar terms are checked separately.
                        @endif
                    @elseif ($row['orders_paused'])
                        Orders are paused on {{ $account }}.
                    @elseif (in_array($row['facts_state'] ?? null, ['stale', 'unknown'], true))
                        Registrar terms need checking.
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
                @foreach ([$yearly, array_combine(array_keys($longer), array_map(
                    static fn (string $term) => 'Register, '.substr($term, strlen('register:')).' years', array_keys($longer)
                ))] as $terms)
                    @continue($terms === [] || ($result && $blocked))
                    @if (!$loop->first)
                        <details>
                            <summary>2 to 10 year registrations for .{{ $suffix }}</summary>
                            @if (!$blocked && isset($row['longest_after']) && $row['longest_after'] < 10)
                                <p>Registration limit is {{ $row['longest_after'] }} {{ $row['longest_after'] === 1 ? 'year' : 'years' }}.
                                    Prices for longer terms are shown for reference.</p>
                            @endif
                    @endif
                    <div class="domains-preview">
                        <table aria-label="{{ $blocked ? 'Draft prices' : 'Prices' }} for .{{ $suffix }}">
                            <thead><tr>
                                <th scope="col">Price</th>
                                @if (!$result && !$blocked)<th scope="col">Saved</th>@endif
                                <th scope="col">{{ $blocked ? 'Draft amount' : ($result ? 'Amount' : 'After saving') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($terms as $term => $label)
                                    @php $price = $prices[$term]; @endphp
                                    <tr>
                                        <th scope="row">{{ $label }}</th>
                                        @if (!$result && !$blocked)<td>{{ $money($price['before']) }}</td>@endif
                                        <td>
                                            {{ $money($price['after']) }}
                                            @if (!$blocked && !$price['available'])
                                                <span class="domains-sub">{{ SalesSetupReview::priceNote($term, $price['reason'], $row['orders_paused']) ?? 'Not for sale' }}</span>
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
        @if ($result)<x-filament::link :href="$page->catalogueUrl()">Back to TLDs &amp; pricing</x-filament::link>@endif
    </div>
@endif
@if (isset($review['review_key']))
    <div class="domains-space">{{ $page->confirmationForm }}</div>
@endif
</div>
