@php $staff ??= false; @endphp
<span data-record-family="{{ $family['kind'] }}">
@if ($family['state'] === 'unknown')
    @if ($family['read_checking'] ?? false)
        @include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'muted']])
    @elseif ($family['records_paused'] ?? false)
        @include('domains::partials.status-pill', ['pill'=>['label'=>'Paused','tone'=>'muted']])
    @else
        @include('domains::partials.missing', ['cause'=>null])
    @endif
@else
    @include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>$staff?'warn':'info',default=>'muted'}]])
@endif
</span>
<span class="domains-sub">
@if ($family['state'] === 'unknown')
    @if ($family['read_checking'] ?? false)
        Registrar read queued for @include('domains::partials.moment',['at'=>gmdate(DATE_ATOM,$family['next_read'])]).
    @elseif ($family['records_paused'] ?? false)
        Record reads were paused or not configured at the last read.
    @else
        {{ $family['read_cause'] ?? 'No registrar read is queued for this family.' }}
        {{ $staff ? 'The next successful complete registrar read fills this family.' : 'These records will appear after a successful registrar read.' }}
    @endif
@else
    {{ $family[$staff ? 'staff' : 'customer'] }} @if ($family['at'])Checked @include('domains::partials.moment',['at'=>$family['at']]).@endif
@endif
</span>
@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment',['at'=>$family['last_good_at']])</span>@endif
