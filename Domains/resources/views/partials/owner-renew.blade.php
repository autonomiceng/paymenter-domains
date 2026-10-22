{{-- Owner renewal request. Shown only while manual renewals are on; billing still decides. --}}
@if (isset($domain->renewNow['url']))
<section class="domains-panel domains-section" id="renew" aria-labelledby="heading-renew"><h2 id="heading-renew" class="domains-h2">Renew now</h2>
<p>Add years to this domain before it expires. We create an invoice now, and the renewal starts after it is paid.</p>
<form method="POST" action="{{ $domain->renewNow['url'] }}" class="domains-owner-form">
@include('domains::partials.form-key', ['action' => 'renew'])
<label class="domains-label" for="renew-years">Renew for</label><select id="renew-years" class="domains-input" name="years">@foreach ($domain->renewNow['terms'] as $years)<option value="{{ $years }}">{{ $years }} {{ $years === 1 ? 'year' : 'years' }}</option>@endforeach</select>
<p class="domains-hint">You can renew online until @include('domains::partials.date', ['date' => $domain->renewNow['order_by']]). The invoice shows the total before you pay.</p>
<button type="submit" class="domains-btn domains-btn--primary">Create renewal invoice</button></form>
</section>
@elseif (isset($domain->renewNow['invoice']) && !$domain->renewalInvoice)
<section class="domains-panel domains-section" id="renew" aria-labelledby="heading-renew"><h2 id="heading-renew" class="domains-h2">Renewal invoice issued</h2>
<p>Pay this invoice to renew your domain.</p><a class="domains-btn domains-btn--primary" href="{{ $domain->renewNow['invoice'] }}">View renewal invoice</a>
</section>
@endif
