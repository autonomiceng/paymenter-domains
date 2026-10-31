{{-- Pure safe DTO rendering. No model relations or query calls. --}}
<article class="domains-summary" aria-label="{{ $summary->name }}">
@if($summary->displayName && $summary->displayName!==$summary->name)<p class="domains-small domains-wrap">{{ $summary->displayName }}</p>@endif
{{-- One status pill at most. The managed page header already shows it. --}}
@unless($summaryOnManaged??false)@include('domains::partials.status-pill',['pill'=>\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($summary)])@endunless
@foreach(array_intersect($summary->stateKeys,['watch.paused','watch.retired','watch.not_registered','watch.no_coverage']) as $key)<p class="domains-notice"><span>{{ $summary->labels[$key] }}</span></p>@endforeach
@if(in_array('adopt.pending',$summary->stateKeys,true))@unless($summaryOnManaged??false)<p class="domains-notice">{{ $summary->labels['adopt.pending_notice'] }}</p>@endunless@if($summary->urls['invoiceHistory']??null)<p><a class="domains-a" href="{{ $summary->urls['invoiceHistory'] }}">View invoice history</a></p>@endif@endif
{{-- summaryNoticesBelow: notice kinds the page already states below (domain checks page). Say each fact once. --}}
@if(in_array('watch.ns_changed',$summary->stateKeys,true) && !in_array('ns_changed',$summaryNoticesBelow??[],true))
<p class="domains-notice"><span>{{ strtr($summary->labels['watch.ns_changed_notice'],['{domain}'=>$summary->name,'{date}'=>isset($summary->noticeDates['dns'])?\Carbon\CarbonImmutable::createFromTimestampUTC((int)$summary->noticeDates['dns'])->format('M j, Y, H:i').' UTC':'recently']) }} @if($summary->urls['support']??null)<a class="domains-a" href="{{ $summary->urls['support'] }}">contact support</a>@else{{ 'contact support' }}@endif.</span></p>
@endif
@php($nameservers=$summary->nameservers['nameservers']??[])
@php($dnsCheck=$summary->freshness['delegation']??null)
@php($dnsValue=$nameservers?implode(', ',$nameservers):(!($dnsCheck['stale']??true)?($summary->labels[array_values(array_intersect($summary->dnsKeys,['domain.dns.platform','domain.dns.external']))[0]??'']??null):null))
@php($dnsAt=isset($summary->nameservers['at'])?\Carbon\CarbonImmutable::parse($summary->nameservers['at'])->utc():(($dnsCheck['checkedAt']??null)?\Carbon\CarbonImmutable::createFromTimestampUTC($dnsCheck['checkedAt']):null))
<dl class="domains-facts">
@if($summaryFinancials??true)@php($expires=\Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::expires($summary))<div class="domains-fact"><dt>Expires</dt><dd>@if($summary->expiry)@include('domains::partials.date',['date'=>$summary->expiry])@elseif($expires['missing']){{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::html($expires['cause']??null,$expires['fix']??null) }}@else None @endif</dd></div>
@if($summary->renewalIssueAt&&!$summary->renewalInvoiceMissing)<div class="domains-fact"><dt>Next invoice</dt><dd>@include('domains::partials.date',['date'=>$summary->renewalIssueAt])</dd></div>@elseif($summary->renewalDueAt)<div class="domains-fact"><dt>Due</dt><dd>@include('domains::partials.date',['date'=>$summary->renewalDueAt])</dd></div>@endif
@if($summary->renewalPrice && !$summary->renewalOff && !in_array('adopt.pending',$summary->stateKeys,true))<div class="domains-fact"><dt>Renewal price</dt><dd>{{ $summary->renewalPrice }}/yr</dd></div>@endif
@endif
<div class="domains-fact"><dt>Nameservers</dt><dd>@if($dnsValue)<span class="domains-wrap">{{ $dnsValue }}</span>@if($dnsAt) <span class="domains-small">{{ ($summary->nameservers['state']??null)==='requested'?'Requested':'Checked' }} {{ $dnsAt->format('M j, Y') }}</span>@endif @else{{ \Paymenter\Extensions\Servers\Domains\Presentation\Missing::html(\Paymenter\Extensions\Servers\Domains\Presentation\Missing::fills(($dnsCheck['checkedAt']??null)?'after the next check':'after the first check')) }}@endif</dd></div>
</dl>
@if(($summaryFinancials??true) && $summary->renewalOff && !in_array('adopt.pending',$summary->stateKeys,true))<p>@if($summary->renewalReminderOff)No renewal invoices or renewal reminders are scheduled.@else Future renewal invoices are stopped. Existing renewal invoices keep their own payment and reminder rules.@endif@if($summary->expiry) Registration is shown until @include('domains::partials.date',['date'=>$summary->expiry]).@endif</p>@endif
@if($summary->kind==='watch' && array_intersect(['watch.expired','watch.redemption','domain.expired','domain.redemption'],$summary->stateKeys))<p class="domains-small">Registry dates can lag behind a recent renewal.@unless(in_array('expired',$summaryNoticesBelow??[],true)) Contact your registrar to check its status.@endunless</p>@endif
@if($summary->relatedServices)<p class="domains-summary-links">Hosting: @foreach(array_slice($summary->relatedServices,0,3) as $service)<a class="domains-a" href="{{ $service['url'] }}">{{ $service['label'] }}</a>{{ $loop->last?'':', ' }}@endforeach{{ $summary->relatedOverflow?' and '.$summary->relatedOverflow.' more':'' }}</p>@endif
@if($summary->urls['compareTransfer']??null)<p><a class="domains-a" href="{{ $summary->urls['compareTransfer'] }}">Compare transfer price</a></p>@endif
@if(!($summaryOnChecks??false) && ($summary->urls['preferences']??null))<p><a class="domains-a" href="{{ $summary->urls['preferences'] }}">Domain check notifications</a></p>@endif
</article>
