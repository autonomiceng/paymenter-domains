{{-- NativePresenter::checkout supplies this owner-scoped, neutral display contract. --}}
<div class="domains">@include('domains::partials.styles')
<div class="domains-panel domains-section"><p class="domains-domain">{{ $domain['name'] }}</p>
<p>{{ $domain['action'] }} · {{ $domain['years'] }} {{ $domain['years'] === 1 ? 'year' : 'years' }}</p>
@if ($domain['renewalPrice'])<p>Renews at {{ $domain['renewalPrice'] }}/yr</p>@else<p>Renewal price: @include('domains::partials.missing', ['cause' => 'Not set for this TLD yet'])</p>@endif
<a class="domains-a" href="{{ $domain['editUrl'] }}">Change domain</a></div></div>
