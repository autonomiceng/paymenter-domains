@php
$inventory ??= \Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::dnsInventory($section);
$reportsTtl ??= collect($inventory)->contains(fn ($record) => isset($record['ttl']));
$readOnly=static fn(array $record)=>$record['type']==='CAA' || (isset($section['types']) && !in_array($record['type'], $section['types'], true)) || empty($section['actions']);
// A column every row would fill with the same word says nothing: a row says Read-only only when some rows can be edited and some can't,
// and TTL shows only when the registrar reported one. The note below says the rest once (DESIGN.md P-WRD-3, P-AVL-1).
$mixed=count(array_filter($inventory,$readOnly))>0 && count(array_filter($inventory,$readOnly))<count($inventory);
@endphp
@if ($inventory)
@if ($section['caa_at'] ?? null)<p class="domains-hint">Last confirmed CAA: @include('domains::partials.moment', ['at'=>$section['caa_at'], 'dateOnly' => true]). These are historical records.</p>@endif
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="DNS records"><table class="domains-tbl domains-dns-inventory"><thead><tr><th>Host</th><th>Type</th><th>Value</th>@if ($reportsTtl)<th>TTL</th>@endif</tr></thead><tbody>
{{-- Editable rows are the form above; in a mixed list only a read-only row says so, under its value (no Access column). --}}
@foreach ($inventory as $record)<tr @if ($readOnly($record)) data-read-only @endif><td class="domains-mono">{{ $record['name']??'@' }}</td><td data-label="Type">{{ $record['type'] }}</td><td class="domains-cell-wide domains-mono">{!! str_replace('.', '.<wbr>', e($record['address'])) !!}@if ($mixed && $readOnly($record))<span class="domains-sub">Read-only</span>@endif</td>@if ($reportsTtl)<td>{{ $record['ttl'] ?? 'Not reported' }}</td>@endif</tr>@endforeach
</tbody></table></div>
@if (array_filter($inventory, $readOnly))
<p class="domains-hint">{{ $mixed ? 'Read-only records are read from the registrar.' : 'Read from the registrar.' }}@include('domains::partials.contact-support', ['before' => ' To change '.($mixed ? 'one' : 'a record').', ', 'supportLabel' => 'contact support', 'after' => '.'])</p>
@endif
@if ($section['caa']??[])<p class="domains-hint">Saving other DNS records keeps CAA values and TTL unchanged.</p>@endif
@endif
