@php
$inventory=array_values(array_filter($section['inventory']??[],fn($r)=>($r['type']??null)!=='CAA'));
foreach($section['caa']??[] as $caa){$inventory[]=['name'=>$caa['name'],'type'=>'CAA','address'=>$caa['value'],'ttl'=>$caa['ttl']];}
@endphp
@if ($inventory)
@if ($section['caa_at'] ?? null)<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment',['at'=>$section['caa_at']]). These are historical records.</p>@endif
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="DNS records"><table class="domains-tbl domains-stackable domains-dns-inventory"><thead><tr><th>Host</th><th>Type</th><th>Value</th><th>TTL</th><th>Access</th></tr></thead><tbody>
@foreach ($inventory as $record)<tr><td data-label="Host" class="domains-mono">{{ $record['name']??'@' }}</td><td data-label="Type">{{ $record['type'] }}</td><td data-label="Value" class="domains-cell-wide">{!! str_replace('.', '.<wbr>', e($record['address'])) !!}</td><td data-label="TTL">{{ $record['ttl']??'Unknown' }}</td><td data-label="Access">{{ $record['type']==='CAA'?'Read-only':($section['actions']?'Edit above':'Read-only') }}</td></tr>@endforeach
</tbody></table></div>
@if ($section['caa']??[])<p class="domains-hint">Saving other DNS records keeps CAA values and TTL unchanged.</p>@endif
@endif
