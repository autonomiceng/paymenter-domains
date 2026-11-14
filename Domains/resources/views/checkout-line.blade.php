{{-- NativePresenter::checkout supplies this owner-scoped, neutral display contract. --}}
<div class="domains">@include('domains::partials.styles')
<div class="domains-panel domains-section"><p class="domains-domain">{{ $domain['name'] }}</p>
<p>{{ $domain['action'] }} for {{ \Paymenter\Extensions\Servers\Domains\Presentation\TermChoice::years((int) $domain['years']) }}</p>
@if ($domain['renewalPrice'])<p>Renews at {{ $domain['renewalPrice'] }}/yr</p>@else<p>Renewal price: @include('domains::partials.missing', ['cause' => \Paymenter\Extensions\Servers\Domains\Presentation\Missing::ASK_FIRST])</p>@endif
<a class="domains-a" href="{{ $domain['editUrl'] }}">Change domain</a></div></div>
