{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One sheet row (Panel\Row, Panel\SettingsRow): the label with its "?" on the last word, the value with any line under it, then the action column. A notice has no label. Without a skin it reads as stacked lines. --}}
@php preg_match('/^(.*\s)?(\S+)$/su', $label, $words); @endphp
<div class="beco-row" id="{{ $id }}" data-beco-row="{{ $kind }}" @if ($waits) data-beco-wait @endif>
@if ($kind !== 'notice')<div class="beco-row-k">@if ($help !== null){{ $words[1] ?? '' }}<span class="beco-nw">{{ $words[2] ?? $label }}{!! $help !!}</span>@else{{ $label }}@endif</div>@endif
<div class="beco-row-v">{{ $value }}@if ($result !== null){{ $result }}@endif
@if ($blocked !== null)<div class="beco-sub" id="{{ $why }}">{{ $blocked }}@if ($fix !== null) {!! $fix !!}@endif</div>@endif</div>
<div class="beco-row-a">@if ($live !== null){{ $live }}@elseif ($actions !== null){!! $actions !!}@endif</div>
</div>
