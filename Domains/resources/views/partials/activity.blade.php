<section class="domains-panel" id="history" aria-labelledby="heading-history"><div class="domains-panel__h"><h2 id="heading-history" class="domains-h2">History</h2></div>
@if (!$domain->progress && !$domain->history)<div class="domains-panel__b"><p class="domains-muted">No changes yet.</p></div>@else
<div class="domains-tablewrap"><table class="domains-tbl domains-stackable"><thead><tr><th scope="col">Change</th><th scope="col">Status</th><th scope="col">Date</th></tr></thead><tbody>
@foreach ($domain->progress as $row)<tr><td class="domains-cell-wide" data-label="Change">{{ $row['label'] }}</td> <td class="domains-cell-wide" data-label="Status">{{ $row['state'] }}@if ($row['note'] ?? null)<span class="domains-sub">{{ $row['note'] }} Try again. Still stuck? @include('domains::partials.contact-support')</span>@endif</td> <td class="domains-cell-wide" data-label="Date">@if ($row['date'] ?? null)@include('domains::partials.date', ['date' => $row['date']])@endif</td></tr>@endforeach
@foreach ($domain->history as $invoice)<tr><td class="domains-cell-wide" data-label="Change"><a class="domains-a" href="{{ $invoice['url'] }}">Renewal invoice {{ $invoice['number'] }}</a></td> <td class="domains-cell-wide" data-label="Status">{{ $invoice['status'] }}</td> <td class="domains-cell-wide" data-label="Date">@if ($invoice['date'] ?? null)@include('domains::partials.date', ['date' => $invoice['date']])@endif</td></tr>@endforeach
</tbody></table></div>
@endif
</section>
