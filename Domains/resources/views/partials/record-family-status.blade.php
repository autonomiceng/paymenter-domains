@php $staff ??= false; @endphp
<span data-record-family="{{ $family['kind'] }}">
@if ($family['state'] === 'unknown')
    @if ($family['read_checking'] ?? false)
        @include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'muted']])
    @else
        @include('domains::partials.missing', ['cause'=>null])
    @endif
@else
    @include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>$staff?'warn':'info',default=>'muted'}]])
@endif
</span>
{{-- An unread family shows only Missing or Checking. Why is the same for every family, so record-read-note says it once above the rows. --}}
@if ($family['state'] !== 'unknown')
<span class="domains-sub">{{ $family[$staff ? 'staff' : 'customer'] }} @if ($family['at'])Checked @include('domains::partials.moment',['at'=>$family['at']]).@endif</span>
@endif
@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment',['at'=>$family['last_good_at']])</span>@endif
