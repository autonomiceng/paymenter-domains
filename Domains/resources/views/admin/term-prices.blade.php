@if ($prices)
<div class="domains-tablewrap" role="region" aria-label="Registration term totals" tabindex="0"><table class="domains-tbl"><thead><tr><th>Term</th><th>Customer total</th><th>Registrar cost</th></tr></thead><tbody>
@foreach ($prices as $price)<tr><td>{{ $price['term'] }} {{ $price['term'] === 1 ? 'year' : 'years' }}</td><td class="domains-money">{{ $price['current'] !== null && $price['synced'] ? '$'.$price['current'] : 'Missing — Sync prices' }}</td><td class="domains-money">{{ $price['cost'] !== null && $price['synced'] ? '$'.number_format((float)$price['cost'],2,'.','') : 'Missing — Sync prices' }}</td></tr>@endforeach
</tbody></table></div>
@endif
