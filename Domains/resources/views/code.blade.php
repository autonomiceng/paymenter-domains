@component('layouts.app', ['title' => 'Auth code', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<x-domains::customer.head title="Auth code" :back="[$domain->name, $domain->actions['show']]" :demo="$domain->demo" :test="$domain->testEnvironment" />
@include('domains::partials.notices', ['fieldKeys' => ['transfer_code'], 'summary' => false])
<x-domains::customer.split>
<x-domains::customer.section id="transfer-code" :padded="true">@include('domains::partials.transfer-code')</x-domains::customer.section>
</x-domains::customer.split>
</div></div>
@endcomponent
