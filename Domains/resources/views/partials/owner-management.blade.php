@if ($domain->management)
@foreach ($domain->management as $cap => $section)
<section class="domains-panel domains-section" id="{{ $cap }}" aria-labelledby="heading-{{ $cap }}">
<h2 id="heading-{{ $cap }}" class="domains-h2">{{ $section['label'] }}</h2>
@if ($cap === 'nameservers')
<p>These decide where your website and email are hosted. Changes can take up to 48 hours to reach everyone.</p>
<form method="POST" action="{{ $section['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'nameservers'])
<label for="domains-ns" class="domains-label">Nameservers, one per line</label>
<textarea id="domains-ns" name="nameservers" rows="4" class="domains-input" required spellcheck="false" autocapitalize="none" aria-describedby="domains-ns-hint"></textarea>
<p id="domains-ns-hint" class="domains-hint">2 to {{ $section['max'] }} hostnames, such as ns1.example.com.</p>
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
<details class="domains-more" @if (collect($errors->keys())->contains(fn($key) => str_starts_with($key, 'contact.'))) open @endif><summary>{{ $section['staged'] ? ($action === 'contact_profile' ? 'Step 1: Prepare new details' : 'Step 2: Apply the new details') : 'Change contact details' }}</summary>
@if ($section['staged'])<p>{{ $action === 'contact_profile' ? 'Enter the new details. When Activity shows Contact details as Confirmed, go to step 2.' : 'Choose the same contact and enter exactly the same details as in step 1. We apply them only after step 1 is confirmed.' }}</p>@endif
<form method="POST" action="{{ $section['actions'][$action] }}" class="domains-owner-form">
@include('domains::partials.form-key')
<label class="domains-label" for="role-{{ $action }}">Contact</label><select id="role-{{ $action }}" class="domains-input" name="role"><option value="all">All contacts</option><option value="registrant">Domain owner</option><option value="admin">Administrative</option><option value="tech">Technical</option><option value="auxbilling">Billing</option></select>
@include('domains::partials.contact', ['prefix' => 'contact', 'idPrefix' => $action, 'section' => 'owner-'.$action, 'stateRequired' => $section['staged']])
@include('domains::partials.owner-confirm', ['text' => 'I confirm these contact details are correct.'])
<button type="submit" class="domains-btn domains-btn--primary">{{ $label }}</button></form></details>
@endforeach
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
<label for="domains-lock" class="domains-label">Transfer lock</label><select id="domains-lock" name="locked" class="domains-input"><option value="1">On</option><option value="0">Off</option></select>
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
