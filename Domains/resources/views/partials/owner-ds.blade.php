{{-- Enom 444f31c DNSSEC layout and copy; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php $draft = ($section['retry']['action'] ?? '') === 'ds_add' ? $section['retry']['values']['ds'] : []; @endphp
<p>Add the DS record your DNS provider gives you.</p>
@if ($section['records'] === null)<p class="domains-hint">Your current DS records can’t be shown right now. You can still add one.</p>
@elseif (!$section['records'])<p class="domains-hint">No DS records.</p>@endif
@foreach ($section['records'] ?? [] as $index => $ds)
<div class="domains-record-card">
<p><strong>Key tag {{ $ds['key_tag'] }}</strong> · algorithm {{ $ds['algorithm'] }} · digest type {{ $ds['digest_type'] }}</p>
<p class="domains-record-value" tabindex="0">{{ $ds['digest'] }}</p>
@if (isset($section['actions']['ds_delete']))
<form method="POST" action="{{ $section['actions']['ds_delete'] }}" class="domains-owner-form" data-confirm>
@include('domains::partials.form-key', ['action'=>'ds_delete'])
@foreach ($ds as $field => $value)<input type="hidden" name="ds[{{ $field }}]" value="{{ $value }}">@endforeach
<button type="submit" class="domains-btn domains-btn--secondary">Remove</button>
@include('domains::partials.owner-confirm', ['id'=>'ds-'.$index, 'open'=>($section['retry']['action'] ?? '') === 'ds_delete' && ($section['retry']['confirm'] ?? false) && ($section['retry']['values']['ds']['digest'] ?? '') === (string) $ds['digest'] && ($section['retry']['values']['ds']['key_tag'] ?? '') === (string) $ds['key_tag'], 'cancel'=>$domain->actions['show'].'#ds', 'title'=>'Remove DS record?', 'button'=>'Remove',
    'facts'=>[['label'=>'Key tag','value'=>(string) $ds['key_tag']], ['label'=>'Digest','value'=>$ds['digest']]],
    'risk'=>'Removing the wrong DS record can make '.$domain->name.' unreachable.'])
</form>
@endif
</div>
@endforeach
@if (isset($section['actions']['ds_add']))
<details class="domains-more" @if (($section['retry']['action'] ?? 'ds_add') === 'ds_add' && collect($errors->keys())->contains(fn($key)=>str_starts_with($key,'ds.'))) open @endif><summary>Add a DS record</summary>
<form method="POST" action="{{ $section['actions']['ds_add'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action'=>'ds_add'])
<div class="domains-fields">
@foreach (['key_tag'=>'Key tag','algorithm'=>'Algorithm','digest_type'=>'Digest type','digest'=>'Digest'] as $field=>$label)
@include('domains::partials.record-field', ['key'=>'ds.'.$field, 'id'=>'ds-'.$field, 'name'=>'ds['.$field.']', 'label'=>$label, 'current'=>$draft[$field] ?? '', 'showError'=>($section['retry']['action'] ?? 'ds_add') === 'ds_add', 'required'=>true, 'type'=>$field==='key_tag'?'number':'text', 'limit'=>$field==='digest'?64:5, 'options'=>match($field){'algorithm'=>[13=>'13 · ECDSA P-256/SHA-256',8=>'8 · RSA/SHA-256',14=>'14 · ECDSA P-384/SHA-384',10=>'10 · RSA/SHA-512',7=>'7 · RSA/SHA-1 NSEC3',5=>'5 · RSA/SHA-1',3=>'3 · DSA/SHA-1',12=>'12 · GOST'],'digest_type'=>[2=>'2 · SHA-256',1=>'1 · SHA-1'],default=>null}])
@endforeach
</div>
<button type="submit" class="domains-btn domains-btn--primary">Add DS record</button></form></details>
@endif
