<label for="{{ $id }}" class="domains-label">Nameservers, one per line</label>
<textarea id="{{ $id }}" name="nameservers" rows="4" maxlength="{{ $max * 255 }}" class="domains-input" @required($required) spellcheck="false" autocapitalize="none" aria-describedby="{{ $id }}-hint{{ $errors->has('nameservers') ? ' '.$id.'-error' : '' }}" @if ($errors->has('nameservers')) aria-invalid="true" @endif>{{ ($useOld ?? false) && is_string(old('nameservers')) ? old('nameservers') : '' }}</textarea>
<p id="{{ $id }}-hint" class="domains-hint">{{ $hint }}</p>
@if ($errors->has('nameservers'))<p id="{{ $id }}-error" class="domains-error">{{ $errors->first('nameservers') }}</p>@endif
