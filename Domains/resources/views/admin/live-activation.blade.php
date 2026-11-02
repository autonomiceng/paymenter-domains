<div>
    <p>{{ $summary['text'] }}</p>
    <p style="margin-top:.75rem;white-space:pre-line">{{ $summary['help'] }}</p>
    @if ($summary['missing'])
        <ul style="margin-top:.75rem;list-style:disc;padding-inline-start:1.25rem">
            @foreach ($summary['missing'] as $cause)<li>{{ $cause }}</li>@endforeach
        </ul>
    @endif
    @if ($summary['failures'])
        <div tabindex="0" role="region" aria-label="Prices needing attention" style="max-height:8rem;overflow:auto;margin-top:.75rem">
            <ul style="list-style:disc;padding-inline-start:1.25rem">
                @foreach ($summary['failures'] as $failure)<li>.{{ $failure['tld'] }} {{ $failure['action'] }} ({{ $failure['term'] }} {{ $failure['term'] === 1 ? 'year' : 'years' }}): {{ $failure['note'] }}</li>@endforeach
            </ul>
        </div>
    @endif
    @if ($summary['costs'])
        <p style="margin-top:.75rem">{{ $summary['tlds'] }} TLDs with readable costs: {{ $summary['counts']['register'] }} registration terms, {{ $summary['counts']['transfer'] }} transfers, {{ $summary['counts']['renew'] }} renewals.</p>
        <p>Cost per term: {{ $summary['range'][0] }} to {{ $summary['range'][1] }} USD.</p>
        <p>Exceptions: {{ $summary['exceptions'] ? count($summary['exceptions']).' TLDs with price warnings. See the notes below.' : 'None.' }}</p>
        @if ($summary['exceptions'])
            <div tabindex="0" role="region" aria-label="Price exceptions" style="max-height:8rem;overflow:auto">
                <ul style="list-style:disc;padding-inline-start:1.25rem">
                    @foreach ($summary['exceptions'] as $exception)<li>.{{ $exception['tld'] }}: {{ $exception['note'] }}</li>@endforeach
                </ul>
            </div>
        @endif
        <details style="margin-top:.75rem">
            <summary style="cursor:pointer">Costs and prices by TLD</summary>
            <div tabindex="0" role="region" aria-label="Costs and prices by TLD" style="max-height:24rem;overflow:auto;margin-top:.5rem">
                <table style="width:100%;border-collapse:collapse;text-align:left;font-size:.875rem">
                    <caption>USD totals per term. Renewal totals are for one year.</caption>
                    <thead><tr>
                        @foreach (['TLD','Action','Years','Cost','Price','Renewal cost','Renewal price','Notes'] as $label)<th scope="col" style="padding:6px 10px;white-space:nowrap">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($summary['costs'] as $row)
                        <tr style="border-top:1px solid rgb(127 127 127 / .2)">
                            <th scope="row" style="padding:6px 10px">.{{ $row['tld'] }}</th>
                            @foreach ([ucfirst($row['action']),$row['term'],$row['cost'],$row['price'],$row['renewCost'] ?? 'n/a',$row['renewPrice'] ?? 'n/a'] as $value)<td style="padding:6px 10px;white-space:nowrap">{{ $value }}</td>@endforeach
                            <td style="padding:6px 10px">{{ $row['note'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
