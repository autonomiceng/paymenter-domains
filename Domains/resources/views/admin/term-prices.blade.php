@if ($prices)
@php
    // Compact only consecutive missing terms. Each evidenced total keeps its own row.
    $groups=[];
    foreach($prices as $price){
        $missing=($price['current']===null&&$price['cost']===null);
        $last=array_key_last($groups);
        if($missing&&$last!==null&&$groups[$last]['missing']&&$groups[$last]['end']+1===$price['term']){$groups[$last]['end']=$price['term'];}
        else{$groups[]=['start'=>$price['term'],'end'=>$price['term'],'missing'=>$missing,'price'=>$price];}
    }
@endphp
<div class="domains-sec"><h3 class="domains-h3">Registration</h3><dl class="domains-card">
@foreach ($groups as $group)
@php $price=$group['price']; @endphp
<div><dt>{{ $group['start'] }}{{ $group['end']!==$group['start'] ? ' to '.$group['end'] : '' }} {{ $group['end']===1 ? 'year' : 'years' }}</dt><dd>
@if ($group['missing']){{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::html('No saved registrar total for these terms.') }}<span class="domains-sub">{{ \Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::refreshText($refresh ?? []) }}</span>
@else<strong>Customer total {{ $price['current']===null ? 'Missing' : '$'.$price['current'] }}</strong><span class="domains-sub">Registrar cost {{ $price['cost']===null ? 'Missing' : '$'.number_format((float)$price['cost'],2,'.','') }}.@if (!$price['synced'] && $price['cost']!==null) Out of date. Sync prices to refresh this cost.@endif</span>@endif
</dd></div>
@endforeach
</dl></div>
@endif
