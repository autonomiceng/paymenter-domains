{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Auto-renew toggle for the Renewal card; its button sits in the card row. Turning it off confirms in a dialog. --}}
@if ($domain->autoRenew)
<form id="form-auto-renew" method="POST" action="{{ $domain->autoRenew['url'] }}" class="domains-owner-form" @if ($domain->autoRenew['enabled']) data-confirm @endif>
@include('domains::partials.form-key', ['action' => 'auto-renew'])
<input type="hidden" name="enabled" value="{{ $domain->autoRenew['enabled'] ? '0' : '1' }}">
@if ($domain->autoRenew['enabled'])
@include('domains::partials.owner-confirm', ['id' => 'auto-renew', 'open' => session('domains_confirm') === 'auto-renew', 'cancel' => $domain->actions['show'], 'title' => 'Turn off renewal invoices?', 'button' => 'Turn off renewal invoices',
    'risk' => 'This stops scheduled renewal invoices. You can still use Renew now when your domain is eligible. An invoice already issued stays payable. Paying it can still renew '.$domain->name.'.'])
@endif
</form>
@endif
