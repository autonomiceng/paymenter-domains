{{-- Resolve only through NativePresenter::invoice for this exact native invoice item. --}}
<div class="domains">@include('domains::partials.styles')
<div class="domains-panel domains-section"><p class="domains-domain">{{ $domain->name }}{{ \Paymenter\Extensions\Servers\Domains\Support\DemoData::label($domain->demo, $domain->testEnvironment) }}</p><p>{{ $domain->status }}</p>
@if ($domain->renewalDue)<p>Renewal payment due @include('domains::partials.date', ['date' => $domain->renewalDue])</p>@endif
@if ($domain->expiry)<p>Domain expiry @include('domains::partials.date', ['date' => $domain->expiry])</p>@endif
<a class="domains-a" href="{{ $domain->actions['show'] }}">View domain</a></div></div>
