{{-- Enom 444f31c child-host layout and copy; MIT, Copyright (c) 2026 Autonomic Engineering. --}}
@php $retry = $section['retry'] ?? null; @endphp
<p>Needed only when your nameservers are named under this domain, like <span class="domains-inline-token" tabindex="0">ns1.{{ $domain->name }}</span>. The registry stores each one’s IP address.</p>
<p class="domains-hint">Only child nameservers added or changed here are listed. Others may already exist.</p>
@foreach ([...($section['records'] ?? []), ['host'=>'','ip'=>'']] as $index=>$host)
<details class="domains-more" @if ($retry && ($host['host'] ? $retry['values']['host'] === $host['host'] : $retry['action'] === 'glue_add')) open @endif><summary class="domains-record-value">{{ $host['host'] ?: 'Add a child nameserver' }}</summary>
@if ($host['host'])<p class="domains-record-value">{{ $host['ip'] }}</p>@endif
@foreach ($host['host']?['glue_update'=>'Save IP address','glue_delete'=>'Delete nameserver']:['glue_add'=>'Add child nameserver'] as $action=>$label)
@if (isset($section['actions'][$action]))
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@include('domains::partials.form-key')
@php $match = $retry && $retry['action'] === $action && ($action === 'glue_add' || $retry['values']['host'] === $host['host']); @endphp
@if ($host['host'])<input type="hidden" name="host" value="{{ $host['host'] }}">
@else
@include('domains::partials.record-field', ['key'=>'glue.host','id'=>'glue-'.$index.'-host','name'=>'host','label'=>'Hostname','current'=>$match ? $retry['values']['host'] : '', 'showError'=>$match, 'required'=>true,'limit'=>253,'options'=>null,'type'=>'text','hintId'=>'glue-'.$index.'-host-hint','hint'=>'Enter the full hostname, including this domain.'])
@endif
@if ($action!=='glue_delete')
@include('domains::partials.record-field', ['key'=>'glue.ip','id'=>'glue-'.$index.'-ip','name'=>'ip','label'=>'IP address','labelSuffix'=>$host['host']?' for '.$host['host']:'','current'=>$match ? $retry['values']['ip'] : $host['ip'], 'showError'=>$match, 'required'=>true,'limit'=>64,'options'=>null,'type'=>'text','hintId'=>'glue-'.$index.'-ip-hint','hint'=>'One public IPv4 or IPv6 address.'])
@endif
@include('domains::partials.owner-confirm', ['text'=>$action==='glue_delete'?'I understand that domains using this nameserver may stop working.':'I confirm this nameserver and IP address are correct.'])
<button type="submit" class="domains-btn domains-btn--secondary">{{ $label }}</button>
</form>
@endif
@endforeach
</details>
@endforeach
