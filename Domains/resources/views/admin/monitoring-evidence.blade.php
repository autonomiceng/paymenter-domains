{{-- withRecords: the page already shows the registrar records and their last read, so their state and refresh rows are not repeated. --}}
@php $withRecords ??= false; @endphp
<div class="domains">
<dl class="domains-card">@unless ($withRecords)<div><dt>Registrar records</dt><dd>{{ $evidence['state'] }}</dd></div>@endunless<div><dt>Billing and ownership</dt><dd>Separate checks<span class="domains-sub">{{ $evidence['separation'] }}</span></dd></div></dl>
<h3 class="domains-h3">Evidence</h3><p class="domains-hint">Latest saved observations. Each source keeps its own values and check time.</p>
<div class="domains-tablewrap" role="region" aria-label="Public DNS and registrar evidence" tabindex="0"><table class="domains-tbl"><thead><tr><th>Source</th><th>Value</th><th>Checked</th></tr></thead><tbody>
@foreach (['public'=>'Public DNS','registrar'=>'Registrar records'] as $source=>$label)<tr><td>{{ $label }}</td><td>{{ $evidence[$source]?implode('; ',$evidence[$source]):'Unknown' }}</td><td>@if ($evidence[$source.'_at'])@include('domains::partials.moment',['at'=>is_int($evidence[$source.'_at'])?gmdate('c',$evidence[$source.'_at']):$evidence[$source.'_at']])@else Unknown @endif</td></tr>@endforeach
</tbody></table></div>
@unless ($withRecords)<h3 class="domains-h3">Latest checks</h3><dl class="domains-card"><div><dt>Registrar refresh</dt><dd>{{ $evidence['state'] }}<span class="domains-sub">@if ($evidence['at'])@include('domains::partials.moment',['at'=>$evidence['at']])@else No registrar read yet. @endif</span></dd></div></dl>@endunless
</div>
