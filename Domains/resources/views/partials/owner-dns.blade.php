{{-- Enom 444f31c DNS/SRV layout and copy; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php
    $draft = $section['retry']['values'] ?? null;
    $isDns = $cap === 'dns';
    $fields = $isDns ? ['name'=>'Host','type'=>'Type','address'=>'Value','mx_pref'=>'MX priority'] : ['service'=>'Service','protocol'=>'Protocol','priority'=>'Priority','weight'=>'Weight','port'=>'Port','target'=>'Target'];
    $types = [''=>'Type','A'=>'A (IPv4)','AAAA'=>'AAAA (IPv6)','CNAME'=>'CNAME (alias)','MX'=>'MX (mail)','MXE'=>'MXE (mail IP)','TXT'=>'TXT (text)','URL'=>'URL (redirect)','FRAME'=>'FRAME (masked redirect)'];
@endphp
@if ($section['records'] === null)
<p class="domains-hint">Editing isn’t available yet because we haven’t confirmed your current records.</p>
@else
<p>{{ $isDns ? 'Used only while the domain uses the registrar’s nameservers. Use @ for the domain itself. Alias and mail targets end with a dot.' : 'Enter the service without the leading underscore, such as sip. The target ends with a dot.' }}</p>
<form method="POST" action="{{ $section['actions'][$cap] }}" class="domains-owner-form domains-record-form">
@include('domains::partials.form-key', ['action' => $cap])
<input type="hidden" name="preimage_digest" value="{{ $draft['digest'] ?? $section['digest'] }}">
@foreach ($errors->keys() as $errorKey)
@if (preg_match('/^'.$cap.'\.records(?:\.(?:count|preimage|[0-9]+))?$/D', $errorKey))<p class="domains-error" role="alert">{{ $errors->first($errorKey) }}</p>@endif
@endforeach
<p class="domains-hint domains-scroll-hint" id="{{ $cap }}-scroll-hint">Scroll sideways to see all columns.</p>
<div class="domains-tablewrap" tabindex="0" role="region" aria-label="{{ $section['label'] }}" aria-describedby="{{ $cap }}-scroll-hint">
<table class="domains-tbl domains-records"><caption class="domains-sr">{{ $section['label'] }}. Blank rows add records.</caption>
<thead><tr>@foreach ($fields as $fieldLabel)<th scope="col">{{ $fieldLabel }}</th>@endforeach<th scope="col">Delete</th></tr></thead>
<tbody>
@foreach ($draft['records'] ?? array_slice([...$section['records'], [], []], 0, 50) as $index => $record)
<tr>
@foreach ($fields as $field => $fieldLabel)
<td>
@include('domains::partials.record-field', ['key'=>$cap.'.records.'.$index.'.'.$field, 'id'=>$cap.'-'.$index.'-'.$field, 'name'=>'records['.$index.']['.$field.']', 'label'=>'Record '.($index+1).' '.($field==='mx_pref'?'MX priority':strtolower($fieldLabel)), 'current'=>$record[$field] ?? '', 'required'=>false, 'type'=>in_array($field,['mx_pref','priority','weight','port'])?'number':'text', 'limit'=>match($field){'address'=>2048,'service'=>14,'target'=>254,default=>253}, 'options'=>$field==='type'?$types:($field==='protocol'?[''=>'Protocol','tcp'=>'TCP','udp'=>'UDP']:null)])
</td>
@endforeach
<td>
@if (!$isDns && (($draft !== null && ($record['_source'] ?? '') !== '') || ($draft === null && $index < count($section['records']))))<input type="hidden" name="records[{{ $index }}][_source]" value="{{ $draft !== null ? $record['_source'] : $index }}">@endif
<label class="domains-checkbox"><input type="checkbox" name="records[{{ $index }}][_delete]" value="1" @checked(($record['_delete'] ?? '') === '1')><span class="domains-sr">Delete record {{ $index + 1 }}</span></label></td>
</tr>
@endforeach
</tbody></table></div>
<p class="domains-hint">{{ count($draft['records'] ?? $section['records']) === 50 ? '50 records is the maximum. To add one, edit or delete an existing record. Saving replaces the whole list. Keep at least one record.' : 'Up to 50 records. Fill a blank row to add one. Saving replaces the whole list. Keep at least one record.' }}</p>
@include('domains::partials.owner-confirm', ['text'=>'I understand that changing these records may stop my website or email working.'])
<button type="submit" class="domains-btn domains-btn--primary">Save {{ $section['label'] }}</button>
</form>
@endif
