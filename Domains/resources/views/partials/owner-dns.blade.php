{{-- Enom 444f31c DNS/SRV layout and copy; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $draft = $section['retry']['values'] ?? null;
    $isDns = $cap === 'dns';
    $fields = $isDns ? ['name'=>'Host','type'=>'Type','address'=>'Value','mx_pref'=>'MX priority'] : ['service'=>'Service','protocol'=>'Protocol','priority'=>'Priority','weight'=>'Weight','port'=>'Port','target'=>'Target'];
    $describe = static fn (array $r): string => trim($isDns ? ($r['name'] ?? '').' '.($r['type'] ?? '').' '.($r['address'] ?? '') : '_'.($r['service'] ?? '').'._'.($r['protocol'] ?? '').' '.($r['target'] ?? '').' port '.($r['port'] ?? ''));
    $types = [''=>'Type','A'=>'A (IPv4)','AAAA'=>'AAAA (IPv6)','CNAME'=>'CNAME (alias)','MX'=>'MX (mail)','MXE'=>'MXE (mail IP)','TXT'=>'TXT (text)','URL'=>'URL (redirect)','FRAME'=>'FRAME (masked redirect)'];
@endphp
<p class="domains-hint">{{ $isDns ? 'Use @ for the domain itself. Alias and mail targets end with a dot.' : 'Enter the service without the leading underscore, such as sip. The target ends with a dot.' }}</p>
<form method="POST" action="{{ $section['actions'][$cap] }}" class="domains-owner-form domains-record-form" data-confirm data-confirm-when="records-removed" data-current="{{ count($section['records']) }}">
@include('domains::partials.form-key', ['action' => $cap])
<input type="hidden" name="preimage_digest" value="{{ $draft['digest'] ?? $section['digest'] }}">
@foreach ($errors->keys() as $errorKey)
@if (preg_match('/^'.$cap.'\.records(?:\.(?:count|preimage|[0-9]+))?$/D', $errorKey))<p class="domains-error" role="alert">{{ $errors->first($errorKey) }}</p>@endif
@endforeach
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="{{ $section['label'] }}">
<table class="domains-tbl domains-records"><caption class="domains-sr">{{ $section['label'] }}. Blank rows add records.</caption>
<thead><tr>@foreach ($fields as $fieldLabel)<th scope="col">{{ $fieldLabel }}</th>@endforeach<th scope="col"><span class="domains-sr">Delete</span></th></tr></thead>
<tbody>
@foreach ($draft['records'] ?? array_slice([...$section['records'], [], []], 0, 50) as $index => $record)
<tr @isset($section['records'][$index]) data-record="{{ $describe($section['records'][$index]) }}" @endisset>
@foreach ($fields as $field => $fieldLabel)
<td>
@include('domains::partials.record-field', ['key'=>$cap.'.records.'.$index.'.'.$field, 'id'=>$cap.'-'.$index.'-'.$field, 'name'=>'records['.$index.']['.$field.']', 'label'=>'Record '.($index+1).' '.($field==='mx_pref'?'MX priority':strtolower($fieldLabel)), 'current'=>$record[$field] ?? '', 'required'=>false, 'type'=>in_array($field,['mx_pref','priority','weight','port'])?'number':'text', 'limit'=>match($field){'address'=>2048,'service'=>14,'target'=>254,default=>253}, 'options'=>$field==='type'?$types:($field==='protocol'?[''=>'Protocol','tcp'=>'TCP','udp'=>'UDP']:null)])
</td>
@endforeach
<td>
@if (!$isDns && (($draft !== null && ($record['_source'] ?? '') !== '') || ($draft === null && $index < count($section['records']))))<input type="hidden" name="records[{{ $index }}][_source]" value="{{ $draft !== null ? $record['_source'] : $index }}">@endif
@if (($record['_delete'] ?? '') === '1' && isset($section['records'][$index]))<input type="hidden" name="records[{{ $index }}][_delete]" value="1">@elseif (isset($section['records'][$index]))<button type="submit" name="records[{{ $index }}][_delete]" value="1" formnovalidate class="domains-a domains-linkbtn" data-delete-record>Delete<span class="domains-sr"> record {{ $index + 1 }}</span></button>@endif</td>
</tr>
@endforeach
</tbody></table></div>
<p class="domains-hint">{{ count($draft['records'] ?? $section['records']) === 50 ? 'Up to 50 records. Saving replaces the list. To add one, edit or delete a record first.' : 'Up to 50 records. Saving replaces the list. Fill a blank row to add one.' }}</p>
<button type="submit" class="domains-btn domains-btn--primary">Save</button>
@php($confirmOpen = ($section['retry']['confirm'] ?? false) && $section['retry']['action'] === $cap)
@include('domains::partials.owner-confirm', ['id'=>$cap, 'open'=>$confirmOpen, 'cancel'=>$domain->actions['show'].'?edit='.$cap.'#edit-'.$cap, 'title'=>'Delete records?', 'button'=>'Save and delete',
    'facts'=>[['label'=>'Records to delete', 'slot'=>'removed', 'value'=>$confirmOpen ? collect($draft['records'] ?? [])->keys()->filter(fn ($i) => \Paymenter\Extensions\Servers\Domains\Services\OwnerRecordInput::removed($draft['records'][$i]) && isset($section['records'][$i]))->map(fn ($i) => $describe($section['records'][$i]))->values()->all() : []]],
    'risk'=>'Anything that uses these records, like your website or email, can stop working.'])
</form>
