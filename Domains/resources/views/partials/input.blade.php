@if (isset($options))
<select id="{{ $id }}" name="{{ $name }}" class="domains-input" autocomplete="{{ $auto }}" @required($required ?? false) @if ($invalid) aria-invalid="true" @endif @if ($description) aria-describedby="{{ $description }}" @endif>
@if (($current ?? '') !== '' && !array_key_exists($current, $options))<option value="{{ $current }}" selected>{{ $current }}</option>@endif
@foreach ($options as $value=>$text)<option value="{{ $value }}" @selected((string)($current ?? '') === (string)$value)>{{ $text }}</option>@endforeach
</select>
@else
<input id="{{ $id }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" value="{{ $current ?? '' }}" maxlength="{{ $limit }}" class="domains-input" autocomplete="{{ $auto }}" @required($required ?? false) @if ($placeholder) placeholder="{{ $placeholder }}" @endif @if ($machine) spellcheck="false" autocapitalize="none" @endif @if (($type ?? '')==='number') min="0" max="65535" @endif @if ($invalid) aria-invalid="true" @endif @if ($description) aria-describedby="{{ $description }}" @endif>
@endif
