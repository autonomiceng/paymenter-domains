@if ($domain->setup['contacts'])
<h2 class="domains-h2">Update your details</h2>
<p>The registry needs a full address and phone number for the domain owner.</p>
<p>Add them to your account, then come back here and choose <strong>Use updated account details</strong>.</p>
<div class="domains-row"><a class="domains-btn domains-btn--secondary" href="{{ route('account') }}">Edit account details</a>
<form method="POST" action="{{ $domain->actions['contactsSave'] }}">@include('domains::partials.form-key', ['action' => 'recovery'])<button type="submit" class="domains-btn domains-btn--primary">Use updated account details</button></form></div>
@elseif ($domain->setup['required'])
<h2 class="domains-h2">{{ $domain->setup['defaults'] ? 'Choose your nameservers' : 'Complete your domain setup' }}</h2><p>{{ $domain->setup['defaults'] ? 'Optional. If you don’t choose before registration starts, we use the default nameservers. You can change them later.' : 'Confirm the details for your domain before we continue.' }}</p>
<form method="POST" action="{{ $domain->actions['setupSave'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'setup'])
@if ($domain->setup['nameservers'])
@include('domains::partials.nameservers', ['id' => 'setup-ns', 'max' => $domain->setup['maxNameservers'], 'required' => !$domain->setup['defaults'], 'hint' => $domain->setup['defaults'] ? 'Leave blank to use the default nameservers, or enter 2 to '.$domain->setup['maxNameservers'].' hostnames.' : 'Enter 2 to '.$domain->setup['maxNameservers'].' hostnames, such as ns1.example.com. Your website or email host can tell you which to use.'])
@else<p>Your current nameservers stay the same.</p>@endif
@if ($domain->setup['privacy'])<label class="domains-checkbox"><input id="setup-privacy" type="checkbox" name="privacy" value="1" @if ($errors->has('privacy')) aria-invalid="true" aria-describedby="setup-privacy-error" @endif><span>Turn on domain privacy at no charge</span></label>@endif
@if ($errors->has('privacy'))<p id="setup-privacy-error" class="domains-error">{{ $errors->first('privacy') }}</p>@endif
@include('domains::partials.owner-confirm', ['text' => 'I confirm these settings for my domain.'])
<button type="submit" class="domains-btn domains-btn--primary">{{ $domain->setup['defaults'] ? 'Save nameservers' : 'Save domain setup' }}</button></form>
@else
<p>Your domain is on hold. Contact support and we’ll sort it out.</p>
@endif
