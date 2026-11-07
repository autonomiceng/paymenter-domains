{{-- Registrar evidence, independent of public DNS and of DS mutation eligibility. --}}
@php $staff ??= false; @endphp
<div class="domains">
@if ($recordFamilies['stale'])<p class="domains-hint">{{ $staff ? 'Registrar records are stale. Last-good data and its time are retained.' : 'Registrar records are waiting for an update. Last confirmed data is retained.' }}</p>@endif
@if ($recordFamilies['at'])<p class="domains-hint">Last registrar read: @include('domains::partials.moment', ['at'=>$recordFamilies['at']])</p>@endif
@if ($staff && $recordFamilies['failed_family'])<p class="domains-hint">Failed family: {{ $recordFamilies['failed_family'] }}. {{ $recordFamilies['staff_hold'] }} {{ $recordFamilies['read_stopped'] ? 'Read stopped. Use Resume sync after fixing the cause.' : 'See the next read below.' }}</p>@endif
@if ($staff)
<p class="domains-hint">Next registrar read: @if ($recordFamilies['next_read'])@include('domains::partials.moment', ['at'=>gmdate(DATE_ATOM, $recordFamilies['next_read'])])@elseif ($recordFamilies['read_stopped'])Stopped. Fix the cause, then use Resume sync.@else No read queued. The domain must be active and its registrar read setup enabled.@endif</p>
@if ($recordFamilies['read_code'])<p class="domains-hint">{{ $recordFamilies['read_cause'] }} Cause: {{ $recordFamilies['read_code'] }}. {{ $recordFamilies['read_attempts'] }} attempts.@if ($recordFamilies['read_failed_at']) Last failure: @include('domains::partials.moment', ['at'=>$recordFamilies['read_failed_at']])@endif</p>@endif
@endif
@if ($staff)<p class="domains-hint">Last complete read: @if ($recordFamilies['last_complete_at'])@include('domains::partials.moment',['at'=>$recordFamilies['last_complete_at']])@else Unknown @endif</p>@endif
@if ($recordFamilies['cancelled_without_records'] ?? false)
<p class="domains-hint">Registration cancelled; no registrar records to check</p>
@else
<dl class="domains-card">
@foreach ($recordFamilies['rows'] as $family)
<div><dt>{{ $family['label'] }}</dt><dd>@include('domains::partials.record-family-status',['family'=>$family,'staff'=>$staff])</dd></div>
@endforeach
</dl>
@endif
@if ($recordFamilies['caa'])
<h3 class="domains-h3">CAA records</h3>
@if ($recordFamilies['caa_at'])<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment', ['at'=>$recordFamilies['caa_at']]). These are historical records.</p>@endif<p class="domains-hint">Read only. Saving other DNS records keeps these records and their TTL unchanged.</p>
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="Read-only CAA records"><table class="domains-tbl"><thead><tr><th scope="col">Host</th><th scope="col">Type</th><th scope="col">Value</th><th scope="col">TTL</th></tr></thead><tbody>
@foreach ($recordFamilies['caa'] as $record)<tr><td class="domains-mono">{{ $record['name'] }}</td><td>CAA</td><td>{{ $record['value'] }}</td><td>{{ $record['ttl'] ?? 'Unknown' }}</td></tr>@endforeach
</tbody></table></div>
@endif
@if ($staff)<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::EVIDENCE }}</p>@endif
</div>
