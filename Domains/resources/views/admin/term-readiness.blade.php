<div class="domains-sec"><h3 class="domains-h3">Renewal</h3><dl class="domains-card">
@foreach ($readiness as [$label,$value,$reason])
@if ($label!=='Registration')<div><dt>{{ match($label){'Early renewal'=>'Ordinary','Grace renewal'=>'Grace',default=>$label} }}</dt><dd>{{ $value }}@if ($reason)<span class="domains-sub">{{ $reason }}</span>@endif</dd></div>@endif
@endforeach
</dl></div>
