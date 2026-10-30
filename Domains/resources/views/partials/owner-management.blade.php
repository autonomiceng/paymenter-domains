{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Page rows for $caps: label with help, current value, one action. Editors open in a dialog; risky toggles confirm in one. --}}
@php $show = $domain->actions['show']; @endphp
@php $fills = \Paymenter\Extensions\Servers\Domains\Presentation\Missing::fills(...); @endphp
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
    // Stopped reads need our team, so they promise no time. The page notice has the one Contact support.
    $waits = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::checkHeld($domain) ? \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::TEAM_CHECK : $fills('within a day');
    // While the demo registrar is paused, synced values are unknown: nothing to do until it resumes.
    $paused = $domain->syncPaused && in_array($cap, ['nameservers', 'dns', 'srv', 'glue', 'ds', 'lock', 'privacy'], true);
@endphp
@continue($section === null && !($cap === 'nameservers' && $domain->syncPaused))
<li class="domains-row" id="{{ $cap }}">
<span class="domains-row__l">{{ $label }}@if (array_key_exists($cap, \Paymenter\Extensions\Servers\Domains\Services\OwnerHelp::SECTIONS))@include('domains::partials.help', ['id' => $cap, 'label' => $label])@endif</span>
@if ($paused)
<span class="domains-row__v">@include('domains::partials.missing', ['cause' => $fills('when sync resumes'), 'fix' => null, 'stack' => true])</span>
@elseif ($cap === 'nameservers')
<span class="domains-row__v">@if (!($section['current'] ?? []))@include('domains::partials.missing', ['cause' => $waits, 'fix' => null, 'stack' => true])@else<span class="domains-mono">{{ $section['current'][0] }}</span>@foreach (array_slice($section['current'], 1) as $server) <span class="domains-sub domains-mono">{{ $server }}</span>@endforeach @endif</span>
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
@if ($section['defaults'] ?? false)<button type="submit" name="use_default" value="1" formnovalidate class="domains-btn domains-btn--ghost" data-fill-defaults>Use the default nameservers</button>@endif
</div>
@include('domains::partials.owner-confirm', ['id' => 'nameservers', 'open' => $confirmStep === 'nameservers', 'cancel' => $show.'?edit=nameservers#edit-nameservers', 'title' => 'Change nameservers?', 'tone' => 'primary', 'button' => 'Change nameservers',
    'facts' => [['label' => 'Current', 'value' => $section['current'] ?? []], ['label' => 'New', 'slot' => 'nameservers', 'value' => preg_split('/\R/', trim($nsValue), -1, PREG_SPLIT_NO_EMPTY)]],
    'risk' => 'If the new nameservers aren’t set up, your website and email stop working.'])
</form>
@endcomponent
@endif
@elseif (in_array($cap, ['dns', 'srv']))
<span class="domains-row__v">@if ($records === null)@include('domains::partials.missing', ['cause' => $waits, 'fix' => null, 'stack' => true])@else{{ count($records) }} {{ count($records) === 1 ? 'record' : 'records' }}@endif</span>
@if ($records !== null)
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Edit</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])@include('domains::partials.owner-dns')@endcomponent
@endif
@elseif (in_array($cap, ['glue', 'ds']))
<span class="domains-row__v">@if ($records === null)@include('domains::partials.missing', ['cause' => $waits, 'fix' => null, 'stack' => true])@elseif (!$records)<span class="domains-muted">{{ $cap === 'glue' ? 'No glue records' : 'Off' }}</span>@elseif ($cap === 'glue'){{ count($records) }} {{ count($records) === 1 ? 'record' : 'records' }}@else On · key tag {{ $records[0]['key_tag'] }}@endif</span>
@if ($section['actions'] ?? [])
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">{{ $cap === 'glue' ? ($records ? 'Edit' : 'Add') : ($records && isset($section['actions']['ds_delete']) ? 'Remove' : 'Add DS record') }}</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])@include('domains::partials.owner-'.$cap)@endcomponent
@endif
@elseif ($cap === 'lock')
<span class="domains-row__v">@if ($state === null)@include('domains::partials.missing', ['cause' => $waits, 'fix' => null, 'stack' => true])@else{{ $state ? 'On' : 'Off' }}@endif</span>
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
<span class="domains-row__v">@if ($state === null)@include('domains::partials.missing', ['cause' => $waits, 'fix' => null, 'stack' => true])@else{{ $state ? 'On' : 'Off' }}@endif</span>
@if ($privacyActions)
<span class="domains-row__a">@foreach ($privacyActions as $action => $url)<form method="POST" action="{{ $url }}" class="domains-owner-form domains-inline">@include('domains::partials.form-key')<button class="domains-a domains-linkbtn" type="submit">{{ $labels[$action] }}</button></form>@endforeach</span>
@endif
@elseif ($cap === 'contacts')
<span class="domains-row__v">@if ($domain->owner){{ $domain->owner['name'] }} <span class="domains-sub">{{ $domain->owner['email'] }}</span>@else @include('domains::partials.missing', ['cause' => 'Add them with Edit contacts', 'fix' => null, 'stack' => true])@endif</span>
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Edit contacts</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
@php
    // Staged registrars take new details first, then use them once approved: one form at a time.
    $approved = $failed === ($section['actions']['contact_profile'] ?? null) || request()->boolean('new') ? null : $section['approved'];
    $action = $section['staged'] && !$approved ? 'contact_profile' : 'contacts';
    $contactFailed = $failed === $section['actions'][$action];
@endphp
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@include('domains::partials.form-key')
@if ($approved)
<p class="domains-hint">Step 2 of 2: apply the approved details.</p>
<p>{{ trim(($approved['contact']['first_name'] ?? '').' '.($approved['contact']['last_name'] ?? '')) }} <span class="domains-sub">{{ $approved['contact']['email_address'] ?? '' }}</span></p>
<input type="hidden" name="role" value="{{ $approved['role'] }}">
@foreach ($approved['contact'] as $field => $fieldValue)<input type="hidden" name="contact[{{ $field }}]" value="{{ $fieldValue }}">@endforeach
<div class="domains-buttons"><button type="submit" class="domains-btn domains-btn--primary">Use the approved details</button> <a class="domains-a" href="{{ $show }}?edit={{ $cap }}&new=1#edit-{{ $cap }}">Enter other details</a></div>
@else
@if ($section['staged'])<p class="domains-hint">Step 1 of 2: we send the new details for approval.</p>@endif
@if (!$section['saved'])<p class="domains-hint">Enter the full details. We replace what's saved.</p>@endif
<p class="domains-hint">We use these details for every contact on this domain.</p>
@include('domains::partials.contact', ['prefix' => 'contact', 'values' => $section['saved'], 'useOld' => $contactFailed, 'errors' => $contactFailed ? $errors : new \Illuminate\Support\ViewErrorBag, 'idPrefix' => $action, 'section' => 'owner-'.$action, 'stateRequired' => $section['staged'], 'limits' => $section['limits'] ?? null])
<p class="domains-hint">Changing the owner’s name, organization or email can block transfers for 60 days.</p>
<div class="domains-buttons"><button type="submit" class="domains-btn domains-btn--primary">{{ $section['staged'] ? 'Send for approval' : 'Save' }}</button></div>
@endif
</form>
@endcomponent
@elseif ($cap === 'auth_code_email')
<span class="domains-row__v"><span class="domains-muted">We email it to the {{ $section['recipient'] }}</span></span>
<span class="domains-row__a"><button type="submit" form="form-auth_code_email" class="domains-a domains-linkbtn">Email auth code</button></span>
<form id="form-auth_code_email" method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm>
@include('domains::partials.form-key', ['action' => 'auth_code_email'])
@include('domains::partials.owner-confirm', ['id' => 'auth_code_email', 'open' => $confirmStep === 'auth_code_email', 'cancel' => $show, 'title' => 'Email the auth code?', 'button' => 'Email auth code', 'tone' => 'primary',
    'risk' => 'We’ll email it to the '.$section['recipient'].'.'])
</form>
@endif
</li>
@endforeach
