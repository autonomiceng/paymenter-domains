{{-- The cart's one customer message, at the top of the cart. Themes include it with @includeIf('domains::cart-notice'). --}}
@php $notice = \Paymenter\Extensions\Servers\Domains\Services\CartNotice::get($__livewire ?? null); @endphp
@if ($notice)
<section class="domains" {{ \Paymenter\Extensions\Servers\Domains\Services\CartNotice::MARKER }} wire:key="domains-cart-notice">
@include('domains::partials.styles')
<div class="domains-notice domains-notice--cart" role="status">
<span class="domains-notice__ic" aria-hidden="true"><svg viewBox="0 0 16 16"><path d="M8 7v4.5M8 4.6v.1"/></svg></span>
<div><strong class="domains-notice__title">{{ $notice['title'] }}</strong>
<p>{!! strtr(e($notice['text']), collect($notice['names'])->mapWithKeys(fn ($name) => [e($name) => '<span class="domains-mono">'.e($name).'</span>'])->all()) !!}</p></div>
</div>
</section>
@endif
