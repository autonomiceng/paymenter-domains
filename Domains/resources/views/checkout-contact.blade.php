@php
    $tlds = app(\Paymenter\Extensions\Servers\Domains\Services\CartAdmission::class)->contactTlds();
    $values = auth()->check() ? \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect(auth()->user())['registrant'] : [];
    // The form model starts from these values, so the phone shows as people write it here too.
    if (isset($values['phone'])) { $values['phone'] = \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::readablePhone($values['phone'], $values['country'] ?? ''); }
    $stateRequired = collect($tlds)->contains('registrar_key', 'namesilo');
    $limits = \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits($stateRequired ? 'namesilo' : 'enom');
@endphp
@if ($tlds && auth()->check())
<section class="domains" wire:key="domains-order-contact">
@include('domains::partials.styles')
<div class="domains-panel"><div class="domains-panel__h"><h2 class="domains-h2">Domain contact details</h2></div><div class="domains-panel__b">
<p>We use these details for all contacts on the domains in this order.</p>
<form method="POST" id="domains-checkout-contact" x-data="{ contact: {{ Js::from($values) }}, saveAccount: false }" x-on:submit.prevent="$wire.checkout({ contact: contact, save_account: saveAccount })">
@csrf
@include('domains::partials.contact', ['prefix'=>'contact', 'idPrefix'=>'order-contact', 'modelPrefix'=>'contact'])
@include('domains::partials.contact-save-account', ['accountModel'=>true])
</form></div></div></section>
@endif
