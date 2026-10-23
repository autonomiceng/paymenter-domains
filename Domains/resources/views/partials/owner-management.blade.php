@if ($domain->management)
@foreach ($domain->management as $cap => $section)
<section class="domains-panel domains-section" id="{{ $cap }}" aria-labelledby="heading-{{ $cap }}">
<h2 id="heading-{{ $cap }}" class="domains-h2">{{ $section['label'] }}</h2>
@if ($cap === 'nameservers')
<p>These decide where your website and email are hosted. Changes can take up to 48 hours to reach everyone.</p>
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'nameservers'])
@include('domains::partials.nameservers', ['id' => 'domains-ns', 'max' => $section['max'], 'required' => true, 'useOld' => session('domains_failed_form') === $section['url'], 'hint' => '2 to '.$section['max'].' hostnames, such as ns1.example.com.'])
@include('domains::partials.owner-confirm', ['text' => 'I understand that changing nameservers may stop my website or email working.'])
<button type="submit" class="domains-btn domains-btn--primary">Save nameservers</button></form>
@if ($section['defaults'] ?? false)
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'nameservers'])
<input type="hidden" name="use_default" value="1">
@include('domains::partials.owner-confirm', ['text' => 'I understand that using the default nameservers may stop my website or email working.'])
<button type="submit" class="domains-btn domains-btn--secondary">Use default nameservers</button></form>
@endif
@elseif ($cap === 'contacts')
<p>Change the domain owner, one other contact, or all contacts at once.</p>
<p class="domains-hint">Changing the owner’s name, organization or email may need email verification, and blocks transfers to another registrar for 60 days.</p>
@foreach ($section['staged'] ? ['contact_profile' => 'Prepare contact', 'contacts' => 'Save contact'] : ['contacts' => 'Save contact'] as $action => $label)
@php($contactFailed = session('domains_failed_form') === $section['actions'][$action])
<details class="domains-more" @if ($contactFailed) open @endif><summary>{{ $section['staged'] ? ($action === 'contact_profile' ? 'Step 1: Prepare new details' : 'Step 2: Apply the new details') : 'Change contact details' }}</summary>
@if ($section['staged'])<p>{{ $action === 'contact_profile' ? 'Enter the new details. When Activity shows Contact details as Confirmed, go to step 2.' : 'Choose the same contact and enter exactly the same details as in step 1. We apply them only after step 1 is confirmed.' }}</p>@endif
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@include('domains::partials.form-key')
<label class="domains-label" for="role-{{ $action }}">Contact</label><select id="role-{{ $action }}" class="domains-input" name="role">@foreach (['all'=>'All contacts','registrant'=>'Domain owner','admin'=>'Administrative','tech'=>'Technical','auxbilling'=>'Billing'] as $role => $roleLabel)<option value="{{ $role }}" @selected($contactFailed && old('role', 'all') === $role)>{{ $roleLabel }}</option>@endforeach</select>
@include('domains::partials.contact', ['prefix' => 'contact', 'useOld' => $contactFailed, 'errors' => $contactFailed ? $errors : new \Illuminate\Support\ViewErrorBag, 'idPrefix' => $action, 'section' => 'owner-'.$action, 'stateRequired' => $section['staged'], 'limits' => $section['limits'] ?? null])
@include('domains::partials.owner-confirm', ['text' => 'I confirm these contact details are correct.'])
<button type="submit" class="domains-btn domains-btn--primary">{{ $label }}</button></form></details>
@endforeach
@elseif (in_array($cap, ['dns', 'srv', 'ds', 'glue']))
@include('domains::partials.owner-'.$cap)
@elseif ($cap === 'privacy')
<p>Hides the owner’s name, address, phone and email from public WHOIS lookups.</p>
@if ($section['free'])<p class="domains-hint">Domain privacy is included at no charge.</p>@else<p class="domains-hint">Domain privacy renews separately from the domain, one year at a time.</p>@endif
@foreach ($section['actions'] as $action => $url)
<form method="POST" action="{{ $url }}" class="domains-owner-form">
@include('domains::partials.form-key')
@include('domains::partials.owner-confirm', ['text' => $action === 'privacy_purchase' ? 'I want an invoice for one year of domain privacy at '.$section['price'].', plus tax where applicable.' : ($action === 'privacy_renew' ? 'I want an invoice to renew domain privacy for one year at '.$section['price'].', plus tax where applicable.' : ($action === 'privacy_disable' ? 'I understand that turning off privacy may make the owner’s details public.' : 'I want to turn on domain privacy.'))])
<button class="domains-btn domains-btn--secondary" type="submit">{{ ['privacy_enable'=>'Turn on','privacy_disable'=>'Turn off','privacy_purchase'=>'Add domain privacy','privacy_renew'=>'Renew domain privacy'][$action] }}@if (in_array($action, ['privacy_purchase', 'privacy_renew'])) · {{ $section['price'] }}/yr @endif</button></form>
@endforeach
@else
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => $cap])
@if ($cap === 'lock')
<p>Stops anyone moving this domain to another registrar. Leave it on unless you’re moving the domain.</p>
<label for="domains-lock" class="domains-label">Transfer lock</label><select id="domains-lock" name="locked" class="domains-input"><option value="1">On</option><option value="0" @selected(session('domains_failed_form') === $section['url'] && old('locked') === '0')>Off</option></select>
@include('domains::partials.owner-confirm', ['text' => 'I understand that turning the lock off lets anyone with the transfer code move this domain.'])
<button class="domains-btn domains-btn--secondary" type="submit">Save transfer lock</button>
@elseif ($cap === 'auth_code_email')
<p>Your new registrar asks for this code. We’ll ask for it to be emailed to the {{ $section['recipient'] }}.</p>
@include('domains::partials.owner-confirm', ['text' => 'I want to request the transfer code by email.'])
<button class="domains-btn domains-btn--secondary" type="submit">Email transfer code</button>
@elseif ($cap === 'auto_renew_off')
<p>This turns off automatic renewal at the registrar. Your domain then renews only as set by Auto-renew above, which doesn’t change.</p>
@include('domains::partials.owner-confirm', ['text' => 'I want to turn off automatic renewal at the registrar.'])
<button class="domains-btn domains-btn--secondary" type="submit">Turn off registrar auto-renew</button>
@endif
</form>
@endif
</section>
@endforeach
@endif
