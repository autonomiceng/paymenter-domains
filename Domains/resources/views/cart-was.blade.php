{{-- A changed line's old price, struck through before the new one. Themes include it with @includeIf('domains::cart-was', ['item' => $item]). --}}
@php $was = (\Paymenter\Extensions\Servers\Domains\Services\CartNotice::get($__livewire ?? null)['was'] ?? [])[$item->id] ?? null; @endphp
@if ($was !== null)
<s class="domains-was"><span class="domains-sr">Was </span>{{ $item->price->format((float) $was) }}</s><span class="domains-sr">Now </span>
@endif
