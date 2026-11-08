{{-- Registrar evidence, independent of public DNS and of DS mutation eligibility. --}}
{{-- part: head (notes above the rows) or tail (CAA and evidence). The staff page draws the rows itself, with their edit buttons. --}}
@php $staff ??= false; $part ??= null; @endphp
<div class="domains">
@if ($part !== 'tail')
@if ($recordFamilies['stale'])<p class="domains-hint">Registrar records are waiting for an update. Last confirmed data is retained.</p>@endif
@if ($recordFamilies['at'])<p class="domains-hint">Last registrar read: @include('domains::partials.moment', ['at'=>$recordFamilies['at']])</p>@endif
{{-- What to do next is on the Next registrar read line below. When the read stopped, the page notice names the cause and Resume sync, so this line keeps only the attempt count and time. --}}
@if ($staff && ($readScheduling ?? true) && $recordFamilies['failed_family'])<p class="domains-hint">Last read failed for {{ collect($recordFamilies['rows'])->firstWhere('kind', $recordFamilies['failed_family'])['label'] ?? $recordFamilies['failed_family'] }}. {{ $recordFamilies['staff_hold'] }}</p>@endif
{{-- A cancelled registration with no records won't be read again, so it has no next read. --}}
@if ($staff && ($readScheduling ?? true) && !($recordFamilies['cancelled_without_records'] ?? false))
<p class="domains-hint">Next registrar read: @if ($recordFamilies['next_read'] !== null)@include('domains::partials.moment', ['at'=>gmdate(DATE_ATOM, $recordFamilies['next_read'])])@elseif ($recordFamilies['read_stopped'])Stopped after {{ $recordFamilies['read_attempts'] }} {{ $recordFamilies['read_attempts'] === 1 ? 'attempt' : 'attempts' }}.@if ($recordFamilies['read_failed_at'])Last failure: @include('domains::partials.moment', ['at'=>$recordFamilies['read_failed_at']])@endif @else No read queued. The domain must be active and its registrar read setup enabled.@endif</p>
@if ($recordFamilies['read_code'] && !$recordFamilies['read_stopped'])<p class="domains-hint">{{ $recordFamilies['read_cause'] }} {{ $recordFamilies['read_attempts'] }} {{ $recordFamilies['read_attempts'] === 1 ? 'attempt' : 'attempts' }}.@if ($recordFamilies['read_failed_at']) Last failure: @include('domains::partials.moment', ['at'=>$recordFamilies['read_failed_at']])@endif</p>@endif
@endif
@if ($readScheduling ?? true)
@include('domains::partials.record-read-note', ['recordFamilies' => $recordFamilies, 'staff' => $staff])
@endif
@if ($staff && !($recordFamilies['cancelled_without_records'] ?? false))<p class="domains-hint">Last complete read: @if ($recordFamilies['last_complete_at'])@include('domains::partials.moment',['at'=>$recordFamilies['last_complete_at']])@else Unknown @endif</p>@endif
@if ($recordFamilies['cancelled_without_records'] ?? false)
<p class="domains-hint">Registration cancelled; no registrar records to check</p>
@elseif ($part === null)
<dl class="domains-card">
@foreach ($recordFamilies['rows'] as $family)
<div><dt>{{ $family['label'] }}</dt><dd>@include('domains::partials.record-family-status',['family'=>$family,'staff'=>$staff,'readAt'=>$recordFamilies['at']])</dd></div>
@endforeach
</dl>
@endif
@endif
@if ($part !== 'head')
@if ($recordFamilies['caa'])
<h3 class="domains-h3">CAA records</h3>
@if ($recordFamilies['caa_at'])<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment', ['at'=>$recordFamilies['caa_at']]). These are historical records.</p>@endif<p class="domains-hint">Read only. Saving other DNS records keeps these records and their TTL unchanged.</p>
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="Read-only CAA records"><table class="domains-tbl"><thead><tr><th scope="col">Host</th><th scope="col">Type</th><th scope="col">Value</th><th scope="col">TTL</th></tr></thead><tbody>
@foreach ($recordFamilies['caa'] as $record)<tr><td class="domains-mono">{{ $record['name'] }}</td><td>CAA</td><td>{{ $record['value'] }}</td><td>{{ $record['ttl'] ?? 'Unknown' }}</td></tr>@endforeach
</tbody></table></div>
@endif
{{-- The staff domain page says this once, in its Monitoring section. --}}
@if ($staff && $part === null)<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::EVIDENCE }}</p>@endif
@endif
</div>
