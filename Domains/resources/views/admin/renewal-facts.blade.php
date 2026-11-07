<div class="domains-sec"><dl class="domains-card">
@foreach ($facts as $label=>$fact)
<div><dt>{{ $label }}</dt><dd @class(['domains-mono'=>$label==='Domain'])>@if ($label==='Total')<strong class="domains-total">{{ $fact['value'] }}</strong>@else{{ $fact['value'] }}@endif @if ($fact['sub']??null)<span class="domains-sub">{{ $fact['sub'] }}</span>@endif</dd></div>
@endforeach
</dl></div>
