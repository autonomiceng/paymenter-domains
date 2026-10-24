@if ($domain->setup['contacts'])
<h2 class="domains-h2">Update your details</h2>
<p>The registry needs a full address and phone number for the domain owner.</p>
<p>Add them to your account, then come back here and choose <strong>Use updated account details</strong>.</p>
<div class="domains-row"><a class="domains-btn domains-btn--secondary" href="{{ route('account') }}">Edit account details</a>
<form method="POST" action="{{ $domain->actions['contactsSave'] }}">@include('domains::partials.form-key', ['action' => 'recovery'])<button type="submit" class="domains-btn domains-btn--primary">Use updated account details</button></form></div>
@elseif ($domain->setup['required'])
<h2 class="domains-h2">{{ $domain->setup['defaults'] ? 'Choose your nameservers' : 'Complete your domain setup' }}</h2><p>{{ $domain->setup['defaults'] ? 'Optional. If you don’t choose before registration starts, we use Beco nameservers. You can change them later.' : 'Confirm the details for your domain before we continue.' }}</p>
<form method="POST" action="{{ $domain->actions['setupSave'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'setup'])
@if ($domain->setup['nameservers'])
@include('domains::partials.nameservers', ['id' => 'setup-ns', 'max' => $domain->setup['maxNameservers'], 'required' => !$domain->setup['defaults'], 'useOld' => session('domains_failed_form') === $domain->actions['setupSave'], 'hint' => $domain->setup['defaults'] ? 'Leave blank to use Beco nameservers, or enter 2 to '.$domain->setup['maxNameservers'].' hostnames.' : 'Enter 2 to '.$domain->setup['maxNameservers'].' hostnames, such as ns1.example.com. Your website or email host can tell you which to use.'])
@else<p>Your current nameservers stay the same.</p>@endif
@if ($domain->setup['privacy'])<label class="domains-checkbox"><input id="setup-privacy" type="checkbox" name="privacy" value="1" @checked(session('domains_failed_form') === $domain->actions['setupSave'] && old('privacy') === '1') @if ($errors->has('privacy')) aria-invalid="true" aria-describedby="setup-privacy-error" @endif><span>Turn on WHOIS privacy (free)</span></label>@endif
@if ($errors->has('privacy'))<p id="setup-privacy-error" class="domains-error">{{ $errors->first('privacy') }}</p>@endif
<button type="submit" class="domains-btn domains-btn--primary">{{ $domain->setup['defaults'] ? 'Save nameservers' : 'Save domain setup' }}</button></form>
@else
<p>@include('domains::partials.status-pill', ['pill' => \Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::of('held')]) Your order is on hold. Contact support.</p>
@endif
