@if ($domain->transfer['received'])<p class="domains-notice domains-notice--ok" role="status">{{ $domain->transfer['open'] ? \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::CODE_SAVED : 'Auth code saved.' }}</p>@endif
@if ($domain->transfer['open'])
<p>Your current registrar gives you the auth code (also called EPP code).</p>
<form method="POST" action="{{ $domain->actions['codeSave'] }}" autocomplete="off" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'code'])
<label for="transfer-code-input" class="domains-label">Auth code</label>
<input id="transfer-code-input" name="transfer_code" class="domains-input" type="password" required maxlength="512" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="transfer-code-hint{{ $errors->has('transfer_code') ? ' transfer-code-error' : '' }}" @if ($errors->has('transfer_code')) aria-invalid="true" @endif>
<p id="transfer-code-hint" class="domains-hint">Paste it exactly as given. Codes are case-sensitive.</p>
@error('transfer_code')<p id="transfer-code-error" class="domains-error" role="alert">{{ $message }}</p>@enderror
<button type="submit" class="domains-btn domains-btn--primary">Save</button></form>
@if ($domain->transfer['deadline'])<p>You can enter or change the auth code until {{ $domain->transfer['deadline'] }}, or until the transfer is sent if that’s sooner.</p>@endif
@else
@if ($domain->transfer['phase'] === 'pending')<p>Your current registrar may email the domain owner to approve the transfer. Approving it there can speed things up. Most transfers finish within 5 to 7 days.</p>
@elseif ($domain->transfer['phase'] === 'review')<p>We’re checking your transfer and will update this page. You don’t need to do anything.</p>
@elseif ($domain->transfer['phase'] === 'setup_review')<p>Your updated details are awaiting review by our team. Contact support to discuss the next step.</p>
@elseif ($domain->transfer['phase'] === 'cutoff' && $domain->setup['contacts'])<p>Your saved auth code stays in place. The deadline to enter it has passed, so this transfer remains on hold. Our team will review any updated details.</p>
@elseif ($domain->transfer['phase'] === 'cutoff')<p>The time to enter or change this auth code has passed. Contact support and we’ll sort it out.</p>
@elseif ($domain->transfer['phase'] === 'payment')<p>We couldn’t confirm the payment date for this transfer. Contact support and we’ll sort it out.</p>
@else<p>Your transfer is on hold. Contact support and we’ll sort it out.</p>@endif
@endif
@if ($domain->transfer['unchecked'])<dl class="domains-facts"><div><dt>Expires</dt><dd>@include('domains::partials.missing', ['cause' => 'Shows when the transfer completes'])</dd></div></dl>@endif

@if ($domain->setup['contacts'])
<section class="domains-section">@include('domains::partials.registration-setup')</section>
@endif
