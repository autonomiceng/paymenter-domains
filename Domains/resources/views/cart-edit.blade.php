@component('layouts.app', ['title'=>'Change domain','sidebar'=>true,'spa'=>false])
<div class="container mt-14 mb-10"><div class="domains">@include('domains::partials.styles')
<a class="domains-back" href="{{ route('cart') }}">Back to cart</a><h1 class="domains-title">Change domain</h1>
<p class="domains-lede">Update this cart line. To choose a different TLD or registration type, remove the line from your cart and search again.</p>
@include('domains::partials.notices', ['fieldKeys'=>['domain'],'summary'=>false])
<form class="domains-panel domains-section" method="POST" action="{{ route('domains.cart.update',['item'=>$item->id]) }}">
@include('domains::partials.form-key',['keys'=>['edit'=>$key],'action'=>'edit'])
<label for="domain">Domain name</label><input id="domain" class="domains-input" name="domain" value="{{ $domain['name'] }}" maxlength="253" required>
<label for="term">Years</label><select id="term" class="domains-input" name="term">@foreach($item->product->plans()->orderBy('billing_period')->get() as $plan)<option value="{{ $plan->billing_period }}" @selected($plan->id===$item->plan_id)>{{ $plan->billing_period }}</option>@endforeach</select>
<button class="domains-btn domains-btn--primary" type="submit">Update domain</button></form>
</div></div>
@endcomponent
