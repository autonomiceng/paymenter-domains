{{-- Pure safe DTO rendering. No model relations or query calls. --}}
<article class="domains-summary" aria-label="{{ $summary->name }}">
@if($summary->displayName && $summary->displayName!==$summary->name)<p class="domains-small domains-wrap">{{ $summary->displayName }}</p>@endif
{{-- One status pill at most. The managed page header already shows it. --}}
@unless($summaryOnManaged??false)@include('domains::partials.status-pill',['pill'=>\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($summary)])@endunless
@if(in_array('adopt.pending',$summary->stateKeys,true))@unless($summaryOnManaged??false)<p class="domains-notice">{{ $summary->labels['adopt.pending_notice'] }}</p>@endunless@if($summary->urls['invoiceHistory']??null)<p><a class="domains-a" href="{{ $summary->urls['invoiceHistory'] }}">View invoice history</a></p>@endif@endif
@php($nameservers=$summary->nameservers['nameservers']??[])
@php($dnsValue=implode(', ',$nameservers))
@php($dnsAt=isset($summary->nameservers['at'])?\Carbon\CarbonImmutable::parse($summary->nameservers['at'])->utc():null)
<dl class="domains-facts">
@if($summaryFinancials??true)@php($expires=\Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::expires($summary))<div class="domains-fact"><dt>Expires</dt><dd>@if($summary->expiry)@include('domains::partials.date',['date'=>$summary->expiry])@elseif($expires['missing']){{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::html($expires['cause']??null,$expires['fix']??null) }}@elseif(isset($expires['value'])){{ $expires['value'] }}@else None @endif</dd></div>
@if($summary->renewalIssueAt&&!$summary->renewalInvoiceMissing)<div class="domains-fact"><dt>Next invoice</dt><dd>@include('domains::partials.date',['date'=>$summary->renewalIssueAt])</dd></div>@elseif($summary->renewalDueAt)<div class="domains-fact"><dt>Due</dt><dd>@include('domains::partials.date',['date'=>$summary->renewalDueAt])</dd></div>@endif
@if($summary->renewalPrice && !$summary->renewalOff && !in_array('adopt.pending',$summary->stateKeys,true))<div class="domains-fact"><dt>Renewal price</dt><dd>{{ $summary->renewalPrice }}{{ $summary->renewalPrice === \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL ? '' : '/yr' }}</dd></div>@endif
@endif
<div class="domains-fact"><dt>Nameservers</dt><dd>@if($dnsValue)<span class="domains-wrap">{{ $dnsValue }}</span>@if($dnsAt) <span class="domains-small">{{ ($summary->nameservers['state']??null)==='requested'?'Requested':'Checked' }} {{ $dnsAt->format('M j, Y') }}</span>@endif @else{{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::html(\Paymenter\Extensions\Servers\Domains\Presentation\Missing::fills('after the first check')) }}@endif</dd></div>
</dl>
@if(($summaryFinancials??true) && $summary->renewalOff && !in_array('adopt.pending',$summary->stateKeys,true))<p>@if($summary->renewalReminderOff)No renewal invoices or renewal reminders are scheduled.@else Future renewal invoices are stopped. Existing renewal invoices keep their own payment and reminder rules.@endif@if($summary->expiry) Registration is shown until @include('domains::partials.date',['date'=>$summary->expiry]).@endif</p>@endif
</article>
