{{-- Registrar evidence, independent of public DNS and of DS mutation eligibility. --}}
@php $staff ??= false; @endphp
<div class="domains">
@if ($recordFamilies['stale'])<p class="domains-hint">{{ $staff ? 'Registrar records are stale. Last-good data and its time are retained.' : 'These records are waiting for an update. We’re checking them.' }}</p>@endif
@if ($recordFamilies['at'])<p class="domains-hint">Last registrar read: @include('domains::partials.moment', ['at'=>$recordFamilies['at']])</p>@endif
@if ($staff && $recordFamilies['failed_family'])<p class="domains-hint">Failed family: {{ $recordFamilies['failed_family'] }}. {{ $recordFamilies['staff_hold'] }} {{ $recordFamilies['read_stopped'] ? 'Read stopped. Use Resume sync after fixing the cause.' : 'See the next read below.' }}</p>@endif
@if ($staff)
<p class="domains-hint">Next registrar read: @if ($recordFamilies['next_read'])@include('domains::partials.moment', ['at'=>gmdate(DATE_ATOM, $recordFamilies['next_read'])])@elseif ($recordFamilies['read_stopped'])Stopped. Fix the cause, then use Resume sync.@else No read queued. The domain must be active and its registrar read setup enabled.@endif</p>
@if ($recordFamilies['read_code'])<p class="domains-hint">{{ $recordFamilies['read_cause'] }} Cause: {{ $recordFamilies['read_code'] }}. {{ $recordFamilies['read_attempts'] }} attempts.@if ($recordFamilies['read_failed_at']) Last failure: @include('domains::partials.moment', ['at'=>$recordFamilies['read_failed_at']])@endif</p>@endif
@endif
@if ($staff)<p class="domains-hint">Last complete read: @if ($recordFamilies['last_complete_at'])@include('domains::partials.moment',['at'=>$recordFamilies['last_complete_at']])@else Unknown @endif</p>@endif
<dl class="domains-card">
@foreach ($recordFamilies['rows'] as $family)
<div><dt>{{ $family['label'] }}</dt><dd><span data-record-family="{{ $family['kind'] }}">@if ($family['state'] === 'unknown')@include('domains::partials.missing', ['cause'=>null])@else @include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>$staff?'warn':'info',default=>'muted'}]])@endif</span><span class="domains-sub">{{ $staff && $family['state']==='unknown' ? 'No current complete read for this family. '.($recordFamilies['next_read'] ? 'The next scheduled registrar read will try to fill it.' : ($recordFamilies['read_stopped'] ? 'Fix the read cause and use Resume sync.' : 'No read queued; check the active domain and registrar read setup.')) : $family[$staff ? 'staff' : 'customer'] }}</span>@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment', ['at'=>$family['last_good_at']])</span>@endif</dd></div>
@endforeach
</dl>
@if ($recordFamilies['caa'])
<h3 class="domains-h3">CAA records</h3>
@if ($recordFamilies['caa_at'])<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment', ['at'=>$recordFamilies['caa_at']]). These are historical records.</p>@endif<p class="domains-hint">Read only. Saving other DNS records keeps these records and their TTL unchanged.</p>
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="Read-only CAA records"><table class="domains-tbl"><thead><tr><th scope="col">Host</th><th scope="col">Type</th><th scope="col">Value</th><th scope="col">TTL</th></tr></thead><tbody>
@foreach ($recordFamilies['caa'] as $record)<tr><td class="domains-mono">{{ $record['name'] }}</td><td>CAA</td><td>{{ $record['value'] }}</td><td>{{ $record['ttl'] ?? 'Unknown' }}</td></tr>@endforeach
</tbody></table></div>
@endif
@if ($staff)<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::EVIDENCE }}</p>@endif
</div>
