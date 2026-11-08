{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Page rows for $caps: label with help, current value, one action. Editors open in a dialog; risky toggles confirm in one. --}}
@php $show = $domain->actions['show']; $initial = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::initialRead($domain); @endphp
@php $fills = \Paymenter\Extensions\Servers\Domains\Presentation\Missing::fills(...); @endphp
{{-- The page hint already gives the read time; a row says As of only when its own time differs. --}}
@php $instant = static fn ($at) => $at === null ? null : ($at instanceof \DateTimeInterface ? $at->getTimestamp() : (is_numeric($at) ? (int) $at : strtotime((string) $at))); $asOf = static fn ($at) => $at && $instant($at) !== $instant($readAt ?? null); @endphp
@php $caps ??= array_values(array_diff(array_keys($domain->management), ['auto_renew_off'])); @endphp
@foreach ($caps as $cap)
@continue($cap === 'privacy' && !\Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN)
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
    // Stopped reads need our team, so they promise no time. When the page notice says so, with the one Contact support, the rows stay quiet.
    $waits = \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::checkHeld($domain) ? (\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::readHeldSaid($notice ?? null) ? null : \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::TEAM_CHECK) : $fills('within a day');
    // An unread family and an empty value would say Missing twice; the value keeps it.
    $familyLabel = isset($section['family']) && !($section['family_only'] ?? false) && !($cap === 'nameservers' && !($section['current'] ?? []) && ($section['family']['state'] ?? null) === 'unknown');
    // The family status beside the label says Missing, Checking or Partial. A full read shows no status, so the value says what was found.
    $familyState = $familyLabel ? ($section['family']['state'] ?? null) : null;
    // While the demo registrar is paused, synced values are unknown: nothing to do until it resumes.
    $paused = $domain->syncPaused && in_array($cap, ['nameservers', 'dns', 'srv', 'glue', 'ds', 'lock', 'privacy'], true);
@endphp
@continue($section === null && !($cap === 'nameservers' && $domain->syncPaused))
<li @class(['domains-row', 'domains-row--pending' => $section['progress']['pending'] ?? false]) id="{{ $cap }}">
<span class="domains-row__l">{{ $label }}@if (array_key_exists($cap, \Paymenter\Extensions\Servers\Domains\Services\OwnerHelp::SECTIONS))@include('domains::partials.help', ['id' => $cap, 'label' => $label])@endif@if ($familyLabel)@include('domains::partials.record-family-status',['family'=>$section['family'],'support'=>'Not shown here?'])@endif
@if (($section['progress'] ?? null) && !$section['progress']['pending'])<span class="domains-sub" role="status">{{ $section['progress']['text'] }}</span>@endif</span>
@if (($section['family_only'] ?? false) && !($section['off'] ?? false) && !($section['progress']['pending'] ?? false))
<span class="domains-row__v">@if ($cap === 'glue')@if ($section['family']['hosts'] ?? [])@include('domains::partials.nameserver-list', ['servers' => $section['family']['hosts']])@elseif (($section['family']['state'] ?? null) === 'partial')<span class="domains-muted">No glue records known here</span>@endif @endif @include('domains::partials.record-family-status',['family'=>$section['family'],'support'=>'Need a change?','asValue'=>true])</span>
@if ($cap==='dns')<span class="domains-row__v domains-row__full">@include('domains::partials.dns-inventory')</span>@endif
@elseif ($section['progress']['pending'] ?? false)
<span class="domains-row__v">
@if ($cap==='ds' && isset($section['progress']['state']))<strong data-ds-state>{{ $section['progress']['state'] }}</strong>@endif
@if (!$paused && $cap === 'nameservers' && ($section['current'] ?? []))
@include('domains::partials.nameserver-list', ['servers' => $section['current']])
@endif
<span @class(['domains-tone-text-warn' => ($section['progress']['tone'] ?? null) === 'warning', 'domains-sub' => !$paused && $cap === 'nameservers' && ($section['current'] ?? [])]) role="status" data-management-pending="{{ $cap }}" data-operation="{{ $section['progress']['id'] }}">{{ $section['progress']['text'] }}</span>
</span>
@else
@if ($paused)
<span class="domains-row__v">@include('domains::partials.missing', ['cause' => $fills('when sync resumes'), 'fix' => null, 'stack' => true])</span>
@elseif ($cap === 'nameservers')
<span class="domains-row__v">@if (!($section['current'] ?? []) && \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::initialRead($domain))<span class="domains-muted">Checking</span>@elseif (!($section['current'] ?? []))@include('domains::partials.missing', ['cause' => $waits, 'calm' => $waits === null, 'fix' => null, 'stack' => true])@else @include('domains::partials.nameserver-list', ['servers' => $section['current']])@endif</span>
@if ($section['actions'])
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}" aria-label="Edit nameservers">Edit</a></span>
@php $nsValue = $confirmStep === 'nameservers' ? $retry['values']['nameservers'] : implode("\n", $section['current'] ?? []); @endphp
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm @if ($section['defaults'] ?? false) data-default-servers="{{ implode("\n", $section['defaultServers'] ?? []) }}" @endif>
@include('domains::partials.form-key', ['action' => 'nameservers'])
<p class="domains-hint">Registrar: {{ $section['registrar'] }}.</p>
@if (!($section['defaults'] ?? false))<p class="domains-hint">{{ $section['defaultError'] ?? \Paymenter\Extensions\Servers\Domains\Services\ManagementNameservers::MISSING }}</p>@endif
@include('domains::partials.nameservers', ['id' => 'domains-ns', 'max' => $section['max'], 'required' => true, 'useOld' => $failed === $section['url'], 'value' => $nsValue, 'placeholder' => "ns1.example.com\nns2.example.com", 'hint' => '2 to '.$section['max'].', like ns1.example.com.'])
<div class="domains-buttons">
<button type="submit" class="domains-btn domains-btn--primary">Save</button>
@if ($section['defaults'] ?? false)<button type="submit" name="fill_site_default" value="1" formnovalidate class="domains-btn domains-btn--ghost" data-fill-defaults>Use site default nameservers</button>@endif
</div>
@include('domains::partials.owner-confirm', ['id' => 'nameservers', 'open' => $confirmStep === 'nameservers', 'cancel' => $show.'?edit=nameservers#edit-nameservers', 'title' => 'Change nameservers?', 'registrar' => $section['registrar'], 'tone' => 'primary', 'button' => 'Change nameservers',
    'facts' => [['label' => 'Current', 'value' => $section['current'] ?? []], ['label' => 'New', 'slot' => 'nameservers', 'value' => preg_split('/\R/', trim($nsValue), -1, PREG_SPLIT_NO_EMPTY)]],
    'risk' => \Paymenter\Extensions\Servers\Domains\Services\ManagementForms::NAMESERVERS_RISK])
</form>
@endcomponent
@endif
@elseif ($section['off'] ?? false)
<span class="domains-row__v">@include('domains::partials.status-pill', ['pill'=>['label'=>'Off','tone'=>'muted']])<span class="domains-sub">{{ $section['reason'] }}</span> @if (isset($section['family']))@include('domains::partials.record-family-status',['family'=>$section['family']])@endif</span>
<span class="domains-row__a"><button class="domains-btn domains-btn--secondary" type="button" aria-disabled="true" disabled>Add DS record</button></span>
@elseif ($section['unavailable'] ?? false)
@php $total = $records === null ? null : count($records) + count($section['fixed'] ?? []); @endphp
<span class="domains-row__v">@if ($total === null)<span class="domains-muted">Not available</span>@else{{ $total }} {{ $total === 1 ? 'record' : 'records' }}@if ($asOf($section['asOf'] ?? null))<span class="domains-sub">As of @include('domains::partials.moment', ['at' => $section['asOf']])</span>@endif @endif <span class="domains-sub">{{ \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::DNS_OFF }}</span></span>
@elseif (in_array($cap, ['dns', 'srv']))
@php $total = $records === null ? 0 : count($records) + count($section['fixed'] ?? []); @endphp
<span class="domains-row__v">@if ($records === null && $familyState === 'unknown')@elseif ($records === null && ($section['family']['state'] ?? null) === 'unknown')<span class="domains-muted">Checking records</span>@elseif ($records === null)@if ($initial)@include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])@else @include('domains::partials.missing', ['cause' => $waits, 'calm' => $waits === null, 'fix' => null, 'stack' => true])@endif@else{{ $total }} {{ $total === 1 ? 'record' : 'records' }}@if ($asOf($section['asOf'] ?? null))<span class="domains-sub">As of @include('domains::partials.moment', ['at' => $section['asOf']])</span>@endif@endif</span>
@if ($records !== null && $section['actions'])
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}" aria-label="Edit {{ $label }}">Edit</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])@include('domains::partials.owner-dns')@endcomponent
@endif
@elseif (in_array($cap, ['glue', 'ds']))
<span class="domains-row__v">@if ($records === null && $familyState === 'unknown')@elseif ($records === null && ($section['family']['state'] ?? null) === 'unknown')<span class="domains-muted">Checking records</span>@elseif ($records === null)@if ($initial)@include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])@else @include('domains::partials.missing', ['cause' => $waits, 'calm' => $waits === null, 'fix' => null, 'stack' => true])@endif@elseif ($cap === 'glue' && $records)@include('domains::partials.nameserver-list', ['servers' => array_column($records, 'host')])@if ($asOf($section['asOf'] ?? null))<span class="domains-sub">As of @include('domains::partials.moment', ['at' => $section['asOf']])</span>@endif @elseif ($cap === 'glue')@if ($familyState !== 'complete')<span class="domains-muted">No glue records known here</span>@else 0 records @endif @elseif (!$records)<span class="domains-muted">Off</span>@else On · key tag {{ $records[0]['key_tag'] }}@endif@foreach ($section['warnings'] ?? [] as $warning)<span class="domains-sub domains-tone-warn">{{ $warning }}</span>@endforeach</span>
@if ($section['actions'] ?? [])
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">{{ $cap === 'glue' ? 'Manage' : ($records && isset($section['actions']['ds_delete']) ? 'Remove' : 'Add DS record') }}</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])@include('domains::partials.owner-'.$cap)@endcomponent
@endif
@elseif ($cap === 'lock')
<span class="domains-row__v">@if ($state === null)@if ($initial)@include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])@else @include('domains::partials.missing', ['cause' => $waits, 'calm' => $waits === null, 'fix' => null, 'stack' => true])@endif@else{{ $state ? 'On' : 'Off' }}@endif</span>
@if ($section['actions'])
@if ($state === null)
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}">Change</a></span>
@php $lockOff = $confirmStep === 'lock' || ($failed === $section['url'] && old('locked') === '0'); @endphp
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm data-confirm-when="lock-off">
@include('domains::partials.form-key', ['action' => 'lock'])
<label for="domains-lock" class="domains-label">Transfer lock</label><select id="domains-lock" name="locked" class="domains-input"><option value="1">On</option><option value="0" @selected($lockOff)>Off</option></select>
<div class="domains-buttons"><button class="domains-btn domains-btn--primary" type="submit">Save</button></div>
@include('domains::partials.owner-confirm', ['id' => 'lock', 'open' => $confirmStep === 'lock', 'cancel' => $show, 'title' => 'Turn off transfer lock?', 'button' => 'Turn off',
    'risk' => \Paymenter\Extensions\Servers\Domains\Services\ManagementForms::lockRisk($domain->name)])
</form>
@endcomponent
@else
<span class="domains-row__a"><button type="submit" form="form-lock" class="domains-a domains-linkbtn">{{ $state ? 'Turn off' : 'Turn on' }}</button></span>
<form id="form-lock" method="POST" action="{{ $section['url'] }}" class="domains-owner-form" @if ($state) data-confirm @endif>
@include('domains::partials.form-key', ['action' => 'lock'])
<input type="hidden" name="locked" value="{{ $state ? '0' : '1' }}">
@if ($state)@include('domains::partials.owner-confirm', ['id' => 'lock', 'open' => $confirmStep === 'lock', 'cancel' => $show, 'title' => 'Turn off transfer lock?', 'button' => 'Turn off',
    'risk' => \Paymenter\Extensions\Servers\Domains\Services\ManagementForms::lockRisk($domain->name)])@endif
</form>
@endif
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
<span class="domains-row__v">@if ($state === null)@if ($initial)@include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])@else @include('domains::partials.missing', ['cause' => $waits, 'calm' => $waits === null, 'fix' => null, 'stack' => true])@endif@else{{ $state ? 'On' : 'Off' }}@endif</span>
@if ($privacyActions)
<span class="domains-row__a">@foreach ($privacyActions as $action => $url)<form method="POST" action="{{ $url }}" class="domains-owner-form domains-inline">@include('domains::partials.form-key')<button class="domains-a domains-linkbtn" type="submit">{{ $labels[$action] }}</button></form>@endforeach</span>
@endif
@elseif ($cap === 'contacts')
<span class="domains-row__v">@if ($domain->owner){{ $domain->owner['name'] }} <span class="domains-sub">{{ $domain->owner['email'] }}</span>@elseif ($initial)@include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])@else @include('domains::partials.missing', ['cause' => 'Add them with Edit', 'fix' => null, 'stack' => true])@endif</span>
@if ($section['actions'])
<span class="domains-row__a"><a class="domains-a" data-dialog-open href="{{ $editUrl }}" aria-label="Edit contacts">Edit</a></span>
@component('domains::partials.owner-edit', ['id' => $cap, 'title' => $label, 'open' => $editOpen, 'cancel' => $show])
@php
    $action = 'contacts';
    $contactFailed = $failed === $section['actions'][$action];
@endphp
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@include('domains::partials.form-key')
@if (!$section['saved'])<p class="domains-hint">Enter the full details. We replace what's saved.</p>@endif
<p class="domains-hint">We use these details for every contact on this domain.</p>
@include('domains::partials.contact', ['prefix' => 'contact', 'values' => $section['saved'], 'useOld' => $contactFailed, 'errors' => $contactFailed ? $errors : new \Illuminate\Support\ViewErrorBag, 'idPrefix' => $action, 'section' => 'owner-'.$action, 'stateRequired' => $section['staged'], 'limits' => $section['limits'] ?? null])
<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Services\ManagementForms::CONTACTS_RISK }}</p>
<div class="domains-buttons"><button type="submit" class="domains-btn domains-btn--primary">Save</button></div>
</form>
@endcomponent
@endif
@elseif ($cap === 'auth_code_email')
<span class="domains-row__v"><span class="domains-muted">We email it to the {{ $section['recipient'] }}</span></span>
@if ($section['actions'])
<span class="domains-row__a"><button type="submit" form="form-auth_code_email" class="domains-a domains-linkbtn">Email code</button></span>
<form id="form-auth_code_email" method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm>
@include('domains::partials.form-key', ['action' => 'auth_code_email'])
@include('domains::partials.owner-confirm', ['id' => 'auth_code_email', 'open' => $confirmStep === 'auth_code_email', 'cancel' => $show, 'title' => 'Email the auth code?', 'button' => 'Email auth code', 'tone' => 'primary',
    'risk' => 'We’ll email it to the '.$section['recipient'].'.'])
</form>
@endif
@endif
@endif
@if ($cap==='ds' && ($section['off']??false))<span class="domains-row__v">@if ($section['records'] ?? [])<span class="domains-sub">Observed DS records</span>@foreach ($section['records'] as $ds)<span class="domains-sub domains-mono">Key tag {{ $ds['key_tag'] }}, algorithm {{ $ds['algorithm'] }}, digest type {{ $ds['digest_type'] }}: {{ $ds['digest'] }}</span>@endforeach @endif</span>@endif
@if ($cap==='dns' && !($section['family_only']??false) && isset($section['inventory']))<span class="domains-row__v domains-row__full">@include('domains::partials.dns-inventory')</span>@endif
</li>
@endforeach
