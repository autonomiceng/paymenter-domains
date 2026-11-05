<div>
    <p>New eligible orders can be sent after activation. Review the selected actions for this account.</p>
    <p>{!! \Paymenter\Extensions\Servers\Domains\Admin\Support\HelpHint::beside(e($summary['text']), $summary['help'])->toHtml() !!}</p>
    @if (array_key_exists('retained_off', $summary))
        <dl class="domains-card"><div><dt>TLDs kept Off</dt><dd>
            @forelse (array_slice($summary['retained_off'], 0, 10) as $tld)
                <p>{{ $tld }} transfers<span class="domains-sub">Turned off by staff. Activation keeps this choice. Use Edit terms in What you sell to change it.</span></p>
            @empty
                None
            @endforelse
            @if (count($summary['retained_off']) > 10)
                <p>{{ count($summary['retained_off']) - 10 }} more TLDs kept Off. Review them in What you sell.</p>
            @endif
        </dd></div></dl>
    @endif
    @if ($summary['missing'])
        <ul class="domains-list domains-space">
            @foreach ($summary['missing'] as $cause)<li>{{ $cause }}</li>@endforeach
        </ul>
    @endif
    @if ($summary['failures'])
        <div tabindex="0" role="region" aria-label="Prices needing attention" class="domains-scroll domains-scroll-short domains-space">
            <ul class="domains-list">
                @foreach ($summary['failures'] as $failure)<li>.{{ $failure['tld'] }} {{ $failure['action'] }} ({{ $failure['term'] }} {{ $failure['term'] === 1 ? 'year' : 'years' }}): {{ $failure['note'] }}</li>@endforeach
            </ul>
        </div>
    @endif
    @if ($summary['costs'])
        <p class="domains-space">{{ $summary['tlds'] }} TLDs with readable costs: {{ $summary['counts']['register'] }} registration terms, {{ $summary['counts']['transfer'] }} transfers, {{ $summary['counts']['renew'] }} renewals.</p>
        <p>Cost per term: {{ $summary['range'][0] }} to {{ $summary['range'][1] }} USD.</p>
        <p>Exceptions: {{ $summary['exceptions'] ? count($summary['exceptions']).' TLDs with price warnings. See the notes below.' : 'None.' }}</p>
        @if ($summary['exceptions'])
            <div tabindex="0" role="region" aria-label="Price exceptions" class="domains-scroll domains-scroll-short">
                <ul class="domains-list">
                    @foreach ($summary['exceptions'] as $exception)<li>.{{ $exception['tld'] }}: {{ $exception['note'] }}</li>@endforeach
                </ul>
            </div>
        @endif
        <details class="domains-space">
            <summary class="domains-disclosure">Costs and prices by TLD</summary>
            <div tabindex="0" role="region" aria-label="Costs and prices by TLD" class="domains-scroll domains-table-wrap domains-space">
                <table class="domains-table">
                    <caption>USD totals per term. Renewal totals are for one year.</caption>
                    <thead><tr>
                        @foreach (['TLD','Action','Years','Cost','Price','Renewal cost','Renewal price','Notes'] as $label)<th scope="col" class="domains-nowrap">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($summary['costs'] as $row)
                        <tr>
                            <th scope="row">.{{ $row['tld'] }}</th>
                            @foreach ([ucfirst($row['action']),$row['term'],$row['cost'],$row['price'],$row['renewCost'] ?? 'n/a',$row['renewPrice'] ?? 'n/a'] as $value)<td class="domains-nowrap">{{ $value }}</td>@endforeach
                            <td>{{ $row['note'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
