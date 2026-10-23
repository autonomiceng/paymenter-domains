{{-- Confirm step for actions that can take a site offline or move the domain. Script opens it as a modal; without script the server renders it open after the first submit. --}}
@php($confirmId = 'confirm-'.$id)
<dialog class="domains-confirm" id="{{ $confirmId }}" aria-labelledby="{{ $confirmId }}-title" aria-describedby="{{ $confirmId }}-risk" data-confirm-dialog @if ($open ?? false) open @endif>
<h3 id="{{ $confirmId }}-title" class="domains-h3">{{ $title }}</h3>
@if ($facts ?? [])
<dl class="domains-confirm__facts">
@foreach ($facts as $fact)
<div @if (isset($fact['slot'])) data-confirm-row="{{ $fact['slot'] }}" @endif @if (!$fact['value']) hidden @endif><dt>{{ $fact['label'] }}</dt><dd @if (isset($fact['slot'])) data-confirm-slot="{{ $fact['slot'] }}" @endif>@foreach ((array) $fact['value'] as $line)<span class="domains-record-value">{{ $line }}</span>@endforeach</dd></div>
@endforeach
</dl>
@endif
<p id="{{ $confirmId }}-risk">{{ $risk }}</p>
<div class="domains-buttons">
<button type="submit" name="confirmed" value="1" class="domains-btn domains-btn--{{ $tone ?? 'danger' }}">{{ $button }}</button>
@if ($open ?? false)<a class="domains-btn domains-btn--secondary" href="{{ $cancel }}">Cancel</a>@else<button type="button" class="domains-btn domains-btn--secondary" data-confirm-cancel autofocus>Cancel</button>@endif
</div>
</dialog>
