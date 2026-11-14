@php
$inventory=array_values(array_filter($section['inventory']??[],fn($r)=>($r['type']??null)!=='CAA'));
foreach($section['caa']??[] as $caa){$inventory[]=['name'=>$caa['name'],'type'=>'CAA','address'=>$caa['value'],'ttl'=>$caa['ttl']];}
$readOnly=static fn(array $record)=>$record['type']==='CAA' || (isset($section['types']) && !in_array($record['type'], $section['types'], true)) || empty($section['actions']);
// A column every row would fill with the same word says nothing: a row says Read-only only when some rows can be edited and some can't,
// and TTL shows only when the registrar reported one. The note below says the rest once (DESIGN.md P-WRD-3, P-AVL-1).
$mixed=count(array_filter($inventory,$readOnly))>0 && count(array_filter($inventory,$readOnly))<count($inventory);
$ttls=count(array_filter($inventory,fn($r)=>($r['ttl']??null)!==null))>0;
@endphp
@if ($inventory)
@if ($section['caa_at'] ?? null)<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment', ['at'=>$section['caa_at']]). These are historical records.</p>@endif
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="DNS records"><table class="domains-tbl domains-stackable domains-dns-inventory"><thead><tr><th>Host</th><th>Type</th><th>Value</th>@if ($ttls)<th>TTL</th>@endif</tr></thead><tbody>
{{-- Editable rows are the form above; in a mixed list only a read-only row says so, under its value (no Access column). --}}
@foreach ($inventory as $record)<tr @if ($readOnly($record)) data-read-only @endif><td data-label="Host" class="domains-mono">{{ $record['name']??'@' }}</td><td data-label="Type">{{ $record['type'] }}</td><td data-label="Value" class="domains-cell-wide">{!! str_replace('.', '.<wbr>', e($record['address'])) !!}@if ($mixed && $readOnly($record))<span class="domains-sub">Read-only</span>@endif</td>@if ($ttls)<td data-label="TTL">{{ $record['ttl'] ?? 'Not reported' }}</td>@endif</tr>@endforeach
</tbody></table></div>
@if (array_filter($inventory, $readOnly))<p class="domains-hint">{{ $mixed ? 'Read-only records are shown as the registrar reports them.' : 'These records are shown as the registrar reports them.' }} You can’t change them on this page. Contact support if one needs to change.@unless ($ttls) The registrar doesn’t report TTLs.@endunless</p>@endif
@if ($section['caa']??[])<p class="domains-hint">Saving other DNS records keeps CAA values and TTL unchanged.</p>@endif
@endif
