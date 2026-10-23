<section class="domains-panel domains-section" aria-labelledby="domains-activity"><h2 id="domains-activity" class="domains-h2">History</h2>
@if (!$domain->progress)<p class="domains-small domains-muted">Nothing yet. Changes you make appear here.</p>@else
<div class="domains-tablewrap"><table class="domains-tbl"><thead><tr><th scope="col">Change</th><th scope="col">Status</th></tr></thead><tbody>
@foreach ($domain->progress as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['state'] }}</td></tr>@endforeach
</tbody></table></div>
@endif
</section>
