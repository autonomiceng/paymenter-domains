<span data-record-family="{{ $family['kind'] }}">@include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>'info',default=>'muted'}]])</span>
<span class="domains-sub">{{ $family['customer'] }} @if ($family['at'])Checked @include('domains::partials.moment',['at'=>$family['at']]).@endif</span>
@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment',['at'=>$family['last_good_at']])</span>@endif
