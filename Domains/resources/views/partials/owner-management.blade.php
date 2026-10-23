@if ($domain->management)
@foreach ($domain->management as $cap => $section)
@php($retry = $section['retry'] ?? null)
@php($confirmStep = $retry && ($retry['confirm'] ?? false) ? $retry['action'] : null)
<section class="domains-panel domains-section" id="{{ $cap }}" aria-labelledby="heading-{{ $cap }}">
<div class="domains-help-head"><h2 id="heading-{{ $cap }}" class="domains-h2">{{ $section['label'] }}</h2>@if (array_key_exists($cap, \Paymenter\Extensions\Servers\Domains\Services\OwnerHelp::SECTIONS))@include('domains::partials.help', ['id' => $cap, 'label' => $section['label']])@endif</div>
@if ($cap === 'nameservers')
@php($useDefault = $confirmStep === 'nameservers' && ($retry['values']['use_default'] ?? '') === '1')
@php($nsValue = $confirmStep === 'nameservers' ? ($useDefault ? implode("\n", $section['defaultServers'] ?? []) : $retry['values']['nameservers']) : implode("\n", $section['current'] ?? []))
<p>Nameservers decide where your website and email are hosted.</p>
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm @if ($section['defaults'] ?? false) data-default-servers="{{ implode("\n", $section['defaultServers'] ?? []) }}" @endif>
@include('domains::partials.form-key', ['action' => 'nameservers'])
@if ($section['defaults'] ?? false)<input type="hidden" name="use_default" value="1" data-use-default @disabled(!$useDefault)>@endif
@include('domains::partials.nameservers', ['id' => 'domains-ns', 'max' => $section['max'], 'required' => true, 'useOld' => session('domains_failed_form') === $section['url'], 'value' => $nsValue, 'placeholder' => "ns1.example.com\nns2.example.com", 'hint' => '2 to '.$section['max'].' hostnames, such as ns1.example.com.'])
<div class="domains-buttons">
<button type="submit" class="domains-btn domains-btn--primary">Save nameservers</button>
@if ($section['defaults'] ?? false)<button type="submit" name="use_default" value="1" formnovalidate class="domains-btn domains-btn--ghost" data-fill-defaults>Use default nameservers</button>@endif
</div>
@include('domains::partials.owner-confirm', ['id' => 'nameservers', 'open' => $confirmStep === 'nameservers', 'cancel' => $domain->actions['show'].'#nameservers', 'title' => 'Change nameservers?', 'tone' => 'primary', 'button' => 'Change nameservers',
    'facts' => [['label' => 'Current', 'value' => $section['current'] ?? []], ['label' => 'New', 'slot' => 'nameservers', 'value' => preg_split('/\R/', trim($nsValue), -1, PREG_SPLIT_NO_EMPTY)]],
    'risk' => 'Your website and email will use the new nameservers. If they aren’t set up there, they can stop working.'])
</form>
@elseif ($cap === 'contacts')
<p>Change the domain owner or other contacts.</p>
@foreach ($section['staged'] ? ['contact_profile' => 'Prepare contact', 'contacts' => 'Save contact'] : ['contacts' => 'Save contact'] as $action => $label)
@php($contactFailed = session('domains_failed_form') === $section['actions'][$action])
<details class="domains-more" @if ($contactFailed) open @endif><summary>{{ $section['staged'] ? ($action === 'contact_profile' ? 'Step 1: Prepare new details' : 'Step 2: Apply the new details') : 'Change contact details' }}</summary>
@if ($section['staged'])<p>{{ $action === 'contact_profile' ? 'Enter the new details. When Activity shows Contact details as Confirmed, go to step 2.' : 'Choose the same contact and enter exactly the same details as in step 1. We apply them only after step 1 is confirmed.' }}</p>@endif
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@include('domains::partials.form-key')
<label class="domains-label" for="role-{{ $action }}">Contact</label><select id="role-{{ $action }}" class="domains-input" name="role">@foreach (['all'=>'All contacts','registrant'=>'Domain owner','admin'=>'Administrative','tech'=>'Technical','auxbilling'=>'Billing'] as $role => $roleLabel)<option value="{{ $role }}" @selected($contactFailed && old('role', 'all') === $role)>{{ $roleLabel }}</option>@endforeach</select>
@include('domains::partials.contact', ['prefix' => 'contact', 'useOld' => $contactFailed, 'errors' => $contactFailed ? $errors : new \Illuminate\Support\ViewErrorBag, 'idPrefix' => $action, 'section' => 'owner-'.$action, 'stateRequired' => $section['staged'], 'limits' => $section['limits'] ?? null])
<p class="domains-hint">Changing the owner’s name, organization or email can block transfers to another registrar for 60 days.</p>
<button type="submit" class="domains-btn domains-btn--primary">{{ $label }}</button></form></details>
@endforeach
@elseif (in_array($cap, ['dns', 'srv', 'ds', 'glue']))
@include('domains::partials.owner-'.$cap)
@elseif ($cap === 'privacy')
<p>Hide the owner’s contact details from public WHOIS lookups. {{ $section['free'] ? 'Included at no charge.' : 'Renews yearly, separately from the domain.' }}</p>
@foreach ($section['actions'] as $action => $url)
<form method="POST" action="{{ $url }}" class="domains-owner-form">
@include('domains::partials.form-key')
<button class="domains-btn domains-btn--secondary" type="submit">{{ ['privacy_enable'=>'Turn on','privacy_disable'=>'Turn off','privacy_purchase'=>'Add domain privacy','privacy_renew'=>'Renew domain privacy'][$action] }}@if (in_array($action, ['privacy_purchase', 'privacy_renew'])) · {{ $section['price'] }}/yr @endif</button></form>
@endforeach
@else
@php($lockOff = $confirmStep === 'lock' || (session('domains_failed_form') === $section['url'] && old('locked') === '0'))
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form" data-confirm @if ($cap === 'lock') data-confirm-when="lock-off" @endif>
@include('domains::partials.form-key', ['action' => $cap])
@if ($cap === 'lock')
<p>Keep this on unless you’re moving the domain to another registrar.</p>
<label for="domains-lock" class="domains-label">Transfer lock</label><select id="domains-lock" name="locked" class="domains-input"><option value="1">On</option><option value="0" @selected($lockOff)>Off</option></select>
<button class="domains-btn domains-btn--secondary" type="submit">Save transfer lock</button>
@include('domains::partials.owner-confirm', ['id' => 'lock', 'open' => $confirmStep === 'lock', 'cancel' => $domain->actions['show'].'#lock', 'title' => 'Turn off transfer lock?', 'button' => 'Turn off lock',
    'risk' => 'Anyone with the transfer code can then move '.$domain->name.' to another registrar.'])
@elseif ($cap === 'auth_code_email')
<p>Your new registrar asks for this code.</p>
<button class="domains-btn domains-btn--secondary" type="submit">Email transfer code</button>
@include('domains::partials.owner-confirm', ['id' => 'auth_code_email', 'open' => $confirmStep === 'auth_code_email', 'cancel' => $domain->actions['show'].'#auth_code_email', 'title' => 'Email the transfer code?', 'button' => 'Email code', 'tone' => 'primary',
    'risk' => 'We’ll email it to the '.$section['recipient'].'. Anyone with the code can move this domain once the transfer lock is off.'])
@elseif ($cap === 'auto_renew_off')
<p>Turn off automatic renewal at the registrar. Auto-renew above doesn’t change.</p>
<button class="domains-btn domains-btn--secondary" type="submit">Turn off registrar auto-renew</button>
@include('domains::partials.owner-confirm', ['id' => 'auto_renew_off', 'open' => $confirmStep === 'auto_renew_off', 'cancel' => $domain->actions['show'].'#auto_renew_off', 'title' => 'Turn off registrar auto-renew?', 'button' => 'Turn off',
    'risk' => 'The registrar stops renewing '.$domain->name.' on its own. It then renews only as set by Auto-renew above.'])
@endif
</form>
@endif
</section>
@endforeach
@endif
