{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Page rows for $caps: label with help, current value, one action. Editors open in a dialog; risky toggles confirm in one. --}}
@php $show = $domain->actions['show']; @endphp
@php $caps ??= array_values(array_diff(array_keys($domain->management), ['auto_renew_off'])); @endphp
@foreach ($caps as $cap)
@php
    $section = $domain->management[$cap] ?? null;
    $retry = $section['retry'] ?? null;
    $confirmStep = $retry && ($retry['confirm'] ?? false) ? $retry['action'] : null;
    $failed = session('domains_failed_form');
    $editUrl = $show.'?edit='.$cap.'#edit-'.$cap;
    $editOpen = $section !== null && (request()->query('edit') === $cap || $retry !== null
        || ($failed !== null && in_array($failed, array_filter([$section['url'] ?? null, ...array_values($section['actions'] ?? [])]), true)));
    $records = $section['records'] ?? null;
    $label = $section['label'] ?? 'Nameservers';
    $state = $section['state'] ?? null;
    $waits = ($section['recordCheckHeld'] ?? false) ? 'Shows when our team resumes record checks' : 'Shows after the next sync';
@endphp
@continue($section === null && !($cap === 'nameservers' && $domain->syncPaused))
<li class="domains-row" id="{{ $cap }}">
<span class="domains-row__l">{{ $label }}@if (array_key_exists($cap, \Paymenter\Extensions\Servers\Domains\Services\OwnerHelp::SECTIONS))@include('domains::partials.help', ['id' => $cap, 'label' => $label])@endif</span>
@if ($cap === 'nameservers')
<span class="domains-row__v">@if ($domain->syncPaused || !($section['current'] ?? []))@include('domains::partials.missing', ['cause' => null, 'fix' => null])@else<span class="domains-mono">{{ $section['current'][0] }}</span>@foreach (array_slice($section['current'], 1) as $server) <span class="domains-sub domains-mono">{{ $server }}</span>@endforeach @endif</span>
@if ($section)
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Change</a></span>
@php $useDefault = $confirmStep === 'nameservers' && ($retry['values']['use_default'] ?? '') === '1'; @endphp
@php $nsValue = $confirmStep === 'nameservers' ? ($useDefault ? implode("\n", $section['defaultServers'] ?? []) : $retry['values']['nameservers']) : implode("\n", $section['current'] ?? []); @endphp
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm @if ($section['defaults'] ?? false) data-default-servers="{{ implode("\n", $section['defaultServers'] ?? []) }}" @endif>
@include('domains::partials.form-key', ['action' => 'nameservers'])
@if ($section['defaults'] ?? false)<input type="hidden" name="use_default" value="1" data-use-default @disabled(!$useDefault)>@endif
@include('domains::partials.nameservers', ['id' => 'domains-ns', 'max' => $section['max'], 'required' => true, 'useOld' => $failed === $section['url'], 'value' => $nsValue, 'placeholder' => "ns1.example.com\nns2.example.com", 'hint' => '2 to '.$section['max'].', like ns1.example.com.'])
<div class="domains-buttons">
<button type="submit" class="domains-btn domains-btn--primary">Save</button>
@if ($section['defaults'] ?? false)<button type="submit" name="use_default" value="1" formnovalidate class="domains-btn domains-btn--ghost" data-fill-defaults>Use Beco nameservers</button>@endif
</div>
@include('domains::partials.owner-confirm', ['id' => 'nameservers', 'open' => $confirmStep === 'nameservers', 'cancel' => $show.'?edit=nameservers#edit-nameservers', 'title' => 'Change nameservers?', 'tone' => 'primary', 'button' => 'Change nameservers',
    'facts' => [['label' => 'Current', 'value' => $section['current'] ?? []], ['label' => 'New', 'slot' => 'nameservers', 'value' => preg_split('/\R/', trim($nsValue), -1, PREG_SPLIT_NO_EMPTY)]],
    'risk' => 'If the new nameservers aren’t set up, your website and email stop working.'])
</form>
@endcomponent
@endif
@elseif (in_array($cap, ['dns', 'srv']))
<span class="domains-row__v">@if ($records === null)@include('domains::partials.missing', ['cause' => $waits, 'fix' => null])@else{{ count($records) }} {{ count($records) === 1 ? 'record' : 'records' }}@endif</span>
@if ($records !== null)
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Edit</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])@include('domains::partials.owner-dns')@endcomponent
@endif
@elseif (in_array($cap, ['glue', 'ds']))
<span class="domains-row__v">@if ($records === null)@include('domains::partials.missing', ['cause' => $waits, 'fix' => null])@elseif (!$records)<span class="domains-muted">{{ $cap === 'glue' ? 'No glue records' : 'Off' }}</span>@elseif ($cap === 'glue'){{ count($records) }} {{ count($records) === 1 ? 'record' : 'records' }}@else On · key tag {{ $records[0]['key_tag'] }}@endif</span>
@if ($section['actions'] ?? [])
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">{{ $cap === 'glue' ? ($records ? 'Edit' : 'Add') : ($records && isset($section['actions']['ds_delete']) ? 'Remove' : 'Add DS record') }}</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])@include('domains::partials.owner-'.$cap)@endcomponent
@endif
@elseif ($cap === 'lock')
<span class="domains-row__v">@if ($state === null)@include('domains::partials.missing', ['cause' => null, 'fix' => null])@else{{ $state ? 'On' : 'Off' }}@endif</span>
@if ($state === null)
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Change</a></span>
@php $lockOff = $confirmStep === 'lock' || ($failed === $section['url'] && old('locked') === '0'); @endphp
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm data-confirm-when="lock-off">
@include('domains::partials.form-key', ['action' => 'lock'])
<label for="domains-lock" class="domains-label">Transfer lock</label><select id="domains-lock" name="locked" class="domains-input"><option value="1">On</option><option value="0" @selected($lockOff)>Off</option></select>
<div class="domains-buttons"><button class="domains-btn domains-btn--primary" type="submit">Save</button></div>
@include('domains::partials.owner-confirm', ['id' => 'lock', 'open' => $confirmStep === 'lock', 'cancel' => $show, 'title' => 'Turn off transfer lock?', 'button' => 'Turn off',
    'risk' => 'Anyone with the auth code can then move '.$domain->name.' to another registrar.'])
</form>
@endcomponent
@else
<span class="domains-row__a"><button type="submit" form="form-lock" class="domains-a domains-linkbtn">{{ $state ? 'Turn off' : 'Turn on' }}</button></span>
<form id="form-lock" method="POST" action="{{ $section['url'] }}" class="domains-owner-form" @if ($state) data-confirm @endif>
@include('domains::partials.form-key', ['action' => 'lock'])
<input type="hidden" name="locked" value="{{ $state ? '0' : '1' }}">
@if ($state)@include('domains::partials.owner-confirm', ['id' => 'lock', 'open' => $confirmStep === 'lock', 'cancel' => $show, 'title' => 'Turn off transfer lock?', 'button' => 'Turn off',
    'risk' => 'Anyone with the auth code can then move '.$domain->name.' to another registrar.'])@endif
</form>
@endif
@elseif ($cap === 'privacy')
@php
    $labels = ['privacy_enable' => 'Turn on', 'privacy_disable' => 'Turn off', 'privacy_purchase' => $section['free'] ? 'Turn on' : 'Add · '.$section['price'].'/yr', 'privacy_renew' => 'Renew · '.$section['price'].'/yr'];
    $pick = static fn (array $keys) => array_intersect_key($section['actions'], array_flip(array_slice(array_values(array_filter($keys, fn ($key) => isset($section['actions'][$key]))), 0, 1)));
    $privacyActions = match (true) {
        $state === true => $pick(['privacy_disable', 'privacy_renew']),
        $state === false => $pick($section['free'] ? ['privacy_enable', 'privacy_purchase'] : ['privacy_purchase', 'privacy_enable']),
        default => $section['actions'],
    };
@endphp
<span class="domains-row__v">@if ($state === null)@include('domains::partials.missing', ['cause' => null, 'fix' => null])@else{{ $state ? 'On' : 'Off' }}@endif</span>
@if ($privacyActions)
<span class="domains-row__a">@foreach ($privacyActions as $action => $url)<form method="POST" action="{{ $url }}" class="domains-owner-form domains-inline">@include('domains::partials.form-key')<button class="domains-a domains-linkbtn" type="submit">{{ $labels[$action] }}</button></form>@endforeach</span>
@endif
@elseif ($cap === 'contacts')
<span class="domains-row__v"></span>
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Edit contacts</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
@foreach ($section['staged'] ? ['contact_profile' => 'Send for approval', 'contacts' => 'Apply details'] : ['contacts' => 'Save'] as $action => $buttonLabel)
@php $contactFailed = $failed === $section['actions'][$action]; @endphp
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@if ($section['staged'])<p class="domains-hint">{{ $action === 'contact_profile' ? 'Step 1 of 2: we send the new details for approval.' : 'Step 2 of 2: apply the approved details.' }}</p>@endif
@include('domains::partials.form-key')
<label class="domains-label" for="role-{{ $action }}">Contact</label><select id="role-{{ $action }}" class="domains-input" name="role">@foreach (['all' => 'All contacts', 'registrant' => 'Registrant', 'admin' => 'Admin', 'tech' => 'Tech', 'auxbilling' => 'Billing'] as $role => $roleLabel)<option value="{{ $role }}" @selected($contactFailed && old('role', 'all') === $role)>{{ $roleLabel }}</option>@endforeach</select>
@include('domains::partials.contact', ['prefix' => 'contact', 'useOld' => $contactFailed, 'errors' => $contactFailed ? $errors : new \Illuminate\Support\ViewErrorBag, 'idPrefix' => $action, 'section' => 'owner-'.$action, 'stateRequired' => $section['staged'], 'limits' => $section['limits'] ?? null])
<p class="domains-hint">Changing the owner’s name, organization or email can block transfers to another registrar for 60 days.</p>
<div class="domains-buttons"><button type="submit" class="domains-btn domains-btn--primary">{{ $buttonLabel }}</button></div></form>
@endforeach
@endcomponent
@elseif ($cap === 'auth_code_email')
<span class="domains-row__v"><span class="domains-muted">Emailed to the {{ $section['recipient'] }}</span></span>
<span class="domains-row__a"><button type="submit" form="form-auth_code_email" class="domains-a domains-linkbtn">Email auth code</button></span>
<form id="form-auth_code_email" method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm>
@include('domains::partials.form-key', ['action' => 'auth_code_email'])
@include('domains::partials.owner-confirm', ['id' => 'auth_code_email', 'open' => $confirmStep === 'auth_code_email', 'cancel' => $show, 'title' => 'Email the auth code?', 'button' => 'Email auth code', 'tone' => 'primary',
    'risk' => 'We’ll email it to the '.$section['recipient'].'.'])
</form>
@endif
</li>
@endforeach
