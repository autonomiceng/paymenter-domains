{{-- Contact fields. $prefix: input name prefix (contact or roles[admin]); $errorPrefix: dotted error key (contact or roles.admin);
     $values: current values; $useOld: read retained input instead; $required: mark required fields; $idPrefix: unique id prefix. --}}
@php
    $required = $required ?? true;
    $useOld = $useOld ?? false;
    $values = $values ?? [];
    $errorPrefix = $errorPrefix ?? 'contact';
    $idPrefix = $idPrefix ?? 'contact';
    $requiredFields = ['first_name','last_name','email_address','phone','address1','city','postal_code','country'];
    if ($stateRequired ?? false) { $requiredFields[] = 'state_province'; }
    $limits = $limits ?? \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits('enom');
    $countries = config('app.countries', []);
    $value = function (string $field) use ($useOld, $values, $errorPrefix) {
        $v = !empty($useOld) ? old($errorPrefix.'.'.$field, '') : ($values[$field] ?? '');
        return is_string($v) ? $v : '';
    };
    $layout = [
        'first_name' => ['First name', 'given-name', 'text', ''], 'last_name' => ['Last name', 'family-name', 'text', ''],
        'organization_name' => ['Organization', 'organization', 'text', 'domains-span'],
        'email_address' => ['Email', 'email', 'email', ''], 'phone' => ['Phone', 'tel', 'tel', ''],
        'address1' => ['Street address', 'address-line1', 'text', 'domains-span'], 'address2' => ['Apartment, suite, etc.', 'address-line2', 'text', 'domains-span'],
        'city' => ['City', 'address-level2', 'text', ''], 'state_province' => ['State or province', 'address-level1', 'text', ''],
        'postal_code' => ['Postal code', 'postal-code', 'text', ''], 'country' => ['Country', 'country', 'select', ''],
    ];
    $section = $section ?? null; // autocomplete section token keeps browsers from mixing contacts
@endphp
<div class="domains-fields" data-contact-fields data-state-required="{{ ($stateRequired ?? false) ? '1' : '0' }}">
    @foreach ($layout as $field => [$label, $auto, $type, $span])
        @php
            $id = $idPrefix.'-'.$field;
            $key = $errorPrefix.'.'.$field;
            $isRequired = $required && in_array($field, $requiredFields, true);
            $error = $errors->first($key);
            $current = $value($field);
            $described = trim((in_array($field, ['phone', 'state_province']) ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
        @endphp
        <div class="{{ $span }}" data-field>
            <label for="{{ $id }}" class="domains-label">{{ $label }}@if (!$isRequired && $field !== 'state_province') <span class="domains-opt">(optional)</span>@endif</label>
            @include('domains::partials.input', ['name'=>$prefix.'['.$field.']', 'current'=>$type==='select'?strtoupper($current):$current, 'limit'=>$limits[$field], 'required'=>$isRequired, 'auto'=>($section ? $section.' ' : '').$auto, 'description'=>$described, 'invalid'=>(bool)$error, 'placeholder'=>$field==='phone'?'+1 206 555 0100':null, 'machine'=>false, 'options'=>$type==='select'?[''=>'Choose a country']+array_filter($countries, fn($key)=>$key!=='', ARRAY_FILTER_USE_KEY):null])
            @if ($field === 'phone')<p id="{{ $id }}-hint" class="domains-hint">Start with + and your country code.</p>@endif
            @if ($field === 'state_province')<p id="{{ $id }}-hint" class="domains-hint">{{ ($stateRequired ?? false) ? 'Required. For the United States and Canada, use the two-letter code.' : 'Required for the United States, Canada and Australia.' }}</p>@endif
            @if ($error)<p id="{{ $id }}-error" class="domains-error">{{ $error }}</p>@endif
        </div>
    @endforeach
</div>
