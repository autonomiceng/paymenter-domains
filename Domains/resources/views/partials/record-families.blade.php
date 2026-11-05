{{-- Registrar evidence, independent of public DNS and of DS mutation eligibility. --}}
@php $staff ??= false; @endphp
<div class="domains">
@if ($recordFamilies['stale'])<p class="domains-hint">{{ $staff ? 'Registrar records are stale. Last-good data and its time are retained.' : 'These records are waiting for an update. We’re checking them.' }}</p>@endif
@if ($recordFamilies['at'])<p class="domains-hint">Last registrar update: @include('domains::partials.moment', ['at'=>$recordFamilies['at']])</p>@endif
@if ($staff && $recordFamilies['failed_family'])<p class="domains-hint">Failed family: {{ $recordFamilies['failed_family'] }}. {{ $recordFamilies['staff_hold'] }} {{ $recordFamilies['resume'] ? 'Resume refresh, then sync this domain.' : 'Use Sync now.' }}</p>@endif
@if ($staff)<p class="domains-hint">Last complete read: @if ($recordFamilies['last_complete_at'])@include('domains::partials.moment',['at'=>$recordFamilies['last_complete_at']])@else Unknown @endif</p>@endif
@if ($recordFamilies['cancelled_without_records'] ?? false)
<p class="domains-hint">Registration cancelled; no registrar records to check</p>
@else
<dl class="domains-card">
@foreach ($recordFamilies['rows'] as $family)
<div><dt>{{ $family['label'] }}</dt><dd><span data-record-family="{{ $family['kind'] }}">@if ($family['state'] === 'unknown'){{ $family['value'] }}@else @include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>$staff?'warn':'info',default=>'muted'}]])@endif</span><span class="domains-sub">{{ $family[$staff ? 'staff' : 'customer'] }}</span>@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment', ['at'=>$family['last_good_at']])</span>@endif</dd></div>
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
