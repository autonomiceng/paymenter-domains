@component('layouts.app', ['title' => 'Transfer code', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<a class="domains-back" href="{{ $domain->actions['show'] }}">{{ $domain->name }}</a>{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}
<h1 class="domains-title">Transfer code</h1>
@include('domains::partials.notices', ['fieldKeys' => ['transfer_code'], 'summary' => false])
<section class="domains-panel domains-section">@include('domains::partials.transfer-code')</section>
</div></div>
@endcomponent
