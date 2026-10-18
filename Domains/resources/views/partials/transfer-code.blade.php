@if ($domain->transfer['received'])<p><strong>Code saved.</strong>@if ($domain->transfer['open']) The transfer starts once payment and your details are confirmed.@endif</p>@endif
@if ($domain->transfer['open'])
<p>Your current registrar gives you this code. It’s also called an auth code or EPP code.</p>
<form method="POST" action="{{ $domain->actions['codeSave'] }}" autocomplete="off" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'code'])
<label for="transfer-code-input" class="domains-label">{{ $domain->transfer['received'] ? 'New transfer code' : 'Transfer code' }}</label>
<input id="transfer-code-input" name="transfer_code" class="domains-input" type="password" required maxlength="512" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="transfer-code-hint{{ $errors->has('transfer_code') ? ' transfer-code-error' : '' }}" @if ($errors->has('transfer_code')) aria-invalid="true" @endif>
<p id="transfer-code-hint" class="domains-hint">Paste it exactly as given. Codes are case-sensitive.</p>
@error('transfer_code')<p id="transfer-code-error" class="domains-error" role="alert">{{ $message }}</p>@enderror
<button type="submit" class="domains-btn domains-btn--primary">{{ $domain->transfer['received'] ? 'Save new code' : 'Save code' }}</button></form>
@if ($domain->transfer['deadline'])<p>You can enter or change the code until {{ $domain->transfer['deadline'] }}, or until the transfer is sent if that’s sooner.</p>@endif
@else
@if ($domain->transfer['phase'] === 'pending')<p>Your current registrar may email the domain owner to approve the transfer. Approving it there can speed things up. Most transfers finish within 5 to 7 days.</p>
@elseif ($domain->transfer['phase'] === 'review')<p>We’re checking your transfer and will update this page. You don’t need to do anything.</p>
@elseif ($domain->transfer['phase'] === 'cutoff')<p>The time to enter or change this transfer code has passed. Contact support and we’ll sort it out.</p>
@elseif ($domain->transfer['phase'] === 'payment')<p>We couldn’t confirm the payment date for this transfer. Contact support and we’ll sort it out.</p>
@else<p>Your transfer is on hold. Contact support and we’ll sort it out.</p>@endif
@endif
@if ($domain->transfer['unchecked'])<p class="domains-small domains-muted">We couldn’t check this domain’s public registration record for this transfer.</p>@endif
