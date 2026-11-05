@php
$inventory=array_values(array_filter($section['inventory']??[],fn($r)=>($r['type']??null)!=='CAA'));
foreach($section['caa']??[] as $caa){$inventory[]=['name'=>$caa['name'],'type'=>'CAA','address'=>$caa['value'],'ttl'=>$caa['ttl']];}
@endphp
@if ($inventory)
@if ($section['caa_at'] ?? null)<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment',['at'=>$section['caa_at']]). These are historical records.</p>@endif
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="DNS records"><table class="domains-tbl"><thead><tr><th>Host</th><th>Type</th><th>Value</th><th>TTL</th><th>Access</th></tr></thead><tbody>
@foreach ($inventory as $record)<tr><td class="domains-mono">{{ $record['name']??'@' }}</td><td>{{ $record['type'] }}</td><td>{{ $record['address'] }}</td><td>{{ $record['ttl']??'Unknown' }}</td><td>{{ $record['type']==='CAA'?'Read-only':($section['actions']?'Edit above':'Read-only') }}</td></tr>@endforeach
</tbody></table></div>
@if ($section['caa']??[])<p class="domains-hint">Saving other DNS records keeps CAA values and TTL unchanged.</p>@endif
@endif
