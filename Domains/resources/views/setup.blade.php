@component('layouts.app', ['title' => 'Finish setup', 'sidebar' => true])
@php
    $state = \Paymenter\Extensions\Servers\Domains\Presentation\State::class;
    $paid = collect($domain->history)->first(fn ($step) => ($step['status'] ?? $step['state'] ?? null) === 'Paid');
    $contacts = (bool) $domain->setup['contacts'];
    $optional = !$contacts && !$domain->setup['required'];
    $kind = ($domain->order['kind'] ?? null) === 'transfer' ? 'Transfer' : 'Registration';
    // Where the order is: paid, this page's step, then the work it starts and the active domain.
    $order = [
        ...($paid || ($domain->order['state'] ?? null) === 'paid' ? [['label' => 'Paid', 'state' => $state::Paid, 'date' => $paid['date'] ?? null]] : []),
        ['label' => $contacts ? 'Your details' : 'Domain setup', 'state' => $optional ? null : $state::ActionRequired, 'reason' => $optional ? 'Optional.' : null],
        ['label' => $kind, 'reason' => $contacts ? 'Starts when your details are saved.' : 'Starts when your setup is saved.'],
        ['label' => 'Active'],
    ];
@endphp
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<x-domains::customer.head title="Finish setup" :back="[$domain->name, $domain->actions['show']]" :demo="$domain->demo" :test="$domain->testEnvironment" />
@include('domains::partials.notices', ['fieldKeys' => ['nameservers', 'privacy', 'contact']])
<x-domains::customer.split>
<x-domains::customer.section id="setup" :padded="true">@include('domains::partials.registration-setup')</x-domains::customer.section>
<x-slot:rail><x-domains::customer.section id="order" title="Your order"><x-domains::customer.trace :steps="$order" /></x-domains::customer.section></x-slot:rail>
</x-domains::customer.split>
@include('domains::partials.client-script')
</div></div>
@endcomponent
