@component('layouts.app', ['title' => 'Domain setup', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<a class="domains-back" href="{{ $domain->actions['show'] }}">{{ $domain->name }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}
<h1 class="domains-title">Domain setup</h1>
@include('domains::partials.notices', ['fieldKeys' => ['nameservers', 'privacy']])
<section class="domains-panel domains-section">@include('domains::partials.registration-setup')</section>
@include('domains::partials.client-script')
</div></div>
@endcomponent
