<label for="{{ $id }}" class="domains-label">Nameservers, one per line</label>
{{-- Tall enough for every saved name plus a blank line, so none is cut off. --}}
<textarea id="{{ $id }}" name="nameservers" rows="{{ max(4, min(13, count(preg_split('/\R/', trim((string) ($value ?? '')), -1, PREG_SPLIT_NO_EMPTY)) + 1)) }}" maxlength="{{ $max * 255 }}" class="domains-input" @required($required) spellcheck="false" autocapitalize="none" @isset($placeholder) placeholder="{{ $placeholder }}" @endisset aria-describedby="{{ $id }}-hint{{ $errors->has('nameservers') ? ' '.$id.'-error' : '' }}" @if ($errors->has('nameservers')) aria-invalid="true" @endif>{{ ($useOld ?? false) && is_string(old('nameservers')) ? old('nameservers') : ($value ?? '') }}</textarea>
<p id="{{ $id }}-hint" class="domains-hint">{{ $hint }}</p>
@if ($errors->has('nameservers'))<p id="{{ $id }}-error" class="domains-error">{{ $errors->first('nameservers') }}</p>@endif
