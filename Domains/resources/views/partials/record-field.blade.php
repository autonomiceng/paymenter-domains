@php $error = ($showError ?? true) ? $errors->first($key) : '';  @endphp
<div data-field>
<label class="domains-label" for="{{ $id }}">{{ $label }}@if ($labelSuffix ?? '')<span class="domains-sr">{{ $labelSuffix }}</span>@endif</label>
@include('domains::partials.input', ['auto'=>'off', 'description'=>implode(' ',array_filter([$hintId ?? '',$error?$id.'-error':''])), 'invalid'=>(bool)$error, 'placeholder'=>null, 'machine'=>true])
@if ($hint ?? '')<p id="{{ $hintId }}" class="domains-hint">{{ $hint }}</p>@endif
@if ($error)<p id="{{ $id }}-error" class="domains-error">{{ $error }}</p>@endif
</div>
