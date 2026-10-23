{{-- Pure safe DTO rendering. No model relations or query calls. --}}
<article class="domains-summary" aria-label="{{ $summary->name }}">
@if($summary->displayName && $summary->displayName!==$summary->name)<p class="domains-small domains-wrap">{{ $summary->displayName }}</p>@endif
{{-- One status pill at most. The managed page header already shows it; evidence lives in the check details below. --}}
@unless($summaryOnManaged??false)@include('domains::partials.status-pill',['pill'=>\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::summary($summary)])@endunless
@foreach(array_intersect($summary->stateKeys,['watch.not_registered','watch.no_coverage']) as $key)<p class="domains-notice"><span>{{ $summary->labels[$key] }}</span></p>@endforeach
@if(in_array('adopt.pending',$summary->stateKeys,true))@unless($summaryOnManaged??false)<p class="domains-notice">{{ $summary->labels['adopt.pending_notice'] }}</p>@endunless@if($summary->urls['invoiceHistory']??null)<p><a class="domains-a" href="{{ $summary->urls['invoiceHistory'] }}">View invoice history</a></p>@endif@endif
@if(in_array('watch.ns_changed',$summary->stateKeys,true))
<p class="domains-notice"><span>{{ strtr($summary->labels['watch.ns_changed_notice'],['{domain}'=>$summary->name,'{date}'=>isset($summary->noticeDates['dns'])?\Carbon\CarbonImmutable::createFromTimestampUTC((int)$summary->noticeDates['dns'])->format('M j, Y, H:i').' UTC':'date unavailable']) }} <a class="domains-a" href="{{ $summary->urls['support'] }}">contact support</a>.</span></p>
@endif
@if($summaryFinancials??true)<dl class="domains-facts">
<div class="domains-fact"><dt>{{ $summary->kind==='watch'?'Registry expiry':'Registration expiry' }}</dt><dd>@if($summary->expiry)<time datetime="{{ $summary->expiry }}">{{ \Carbon\CarbonImmutable::parse($summary->expiry,'UTC')->format('M j, Y') }}</time>@else Expiry unknown @endif
@if($summary->expirySource)<span class="domains-small">{{ $summary->expirySource }}{{ $summary->expiryPrecision==='date'?' · Date only':'' }}{{ ($summary->lastKnown['registry']['stale']??false)?' · Last known date':'' }}</span>@endif</dd></div>
@if($summary->renewalDueAt)<div class="domains-fact"><dt>{{ $summary->renewalDueProjected?'Planned renewal due':'Renewal due' }}</dt><dd>@include('domains::partials.date',['date'=>$summary->renewalDueAt])</dd></div>@endif
@if($summary->renewalPrice && !$summary->renewalOff && !in_array('adopt.pending',$summary->stateKeys,true))<div class="domains-fact"><dt>Annual renewal price</dt><dd>{{ $summary->renewalPrice }}/yr</dd></div>@endif
</dl>
@if($summary->renewalOff && !in_array('adopt.pending',$summary->stateKeys,true))<p>@if($summary->renewalReminderOff)No renewal invoices or renewal reminders are scheduled.@else Future renewal invoices are stopped. Existing renewal invoices keep their own payment and reminder rules.@endif@if($summary->expiry) Registration is shown until @include('domains::partials.date',['date'=>$summary->expiry]).@endif</p>@endif
@endif
@if($summary->kind==='watch' && !($summaryCompact??false) && array_intersect(['watch.expired','watch.redemption','domain.expired','domain.redemption'],$summary->stateKeys))<p class="domains-small">Registry dates can lag behind a recent renewal. Contact your registration provider to check its status.</p>@endif
@if($summary->relatedServices)<div class="domains-summary-links"><strong>Related services</strong><ul>@foreach(array_slice($summary->relatedServices,0,3) as $service)<li><a class="domains-a" href="{{ $service['url'] }}">{{ $service['label'] }}</a></li>@endforeach</ul>@if($summary->relatedOverflow)<span class="domains-small">And more related services</span>@endif</div>@endif
@if($summary->hostnames)<p class="domains-small domains-wrap">Primary hostnames: {{ implode(', ',array_slice($summary->hostnames,0,3)) }}</p>@endif
@if($summary->urls['compareTransfer']??null)<p><a class="{{ ($summaryCompact??false)?'domains-btn domains-btn--secondary domains-btn--sm':'domains-a' }}" href="{{ $summary->urls['compareTransfer'] }}">Compare transfer price</a></p>@endif
@if(!($summaryCompact??false) && !($summaryOnChecks??false) && ($summary->urls['preferences']??null))<p><a class="domains-a" href="{{ $summary->urls['preferences'] }}">Domain check notifications</a></p>@endif
@if(!($summaryCompact??false))
@php($checked=array_filter($summary->freshness,fn($f)=>(bool)$f['checkedAt']))
@php($lastKnown=array_filter($summary->lastKnown,fn($f)=>($f['inScope']??false) && ($f['stale']??false) && $f['value']!==null))
@if(!$checked && !$lastKnown)<p class="domains-small">No DNS check results yet.</p>
@else<details class="domains-checks"><summary>Check details and timestamps</summary><dl class="domains-facts">
@php($known=array_values(array_diff($summary->dnsKeys,\Paymenter\Extensions\Servers\Domains\Presentation\DomainSummaryBadges::GAPS)))
@foreach($checked as $family=>$fact)
@php($evidence=array_map(fn($key)=>$summary->labels[$key],match($family){'delegation'=>array_intersect($known,['domain.dns.platform','domain.dns.external']),'points'=>array_diff($known,['domain.dns.platform','domain.dns.external']),'registry'=>$summary->kind==='watch'?array_diff([$summary->registrationKey],\Paymenter\Extensions\Servers\Domains\Presentation\DomainSummaryBadges::GAPS):[],default=>[]}))
<div class="domains-fact"><dt>{{ ['delegation'=>'Nameservers','points'=>'Website / email pointers','registry'=>'Public registry'][$family] }}</dt><dd>{{ implode(', ',[...$evidence,...($fact['stale']?['Out of date']:($fact['partial']?['Partly complete']:($evidence?[]:['Checked'])))]) }}
<span class="domains-small">Last checked {{ \Carbon\CarbonImmutable::createFromTimestampUTC($fact['checkedAt'])->format('M j, Y, H:i') }} UTC</span>
@if($fact['nextCheck'])<span class="domains-small">Next check {{ \Carbon\CarbonImmutable::createFromTimestampUTC($fact['nextCheck'])->format('M j, Y, H:i') }} UTC</span>@endif</dd></div>@endforeach
</dl>
@if($lastKnown)<h4>Last known details · out of date</h4><p class="domains-small">These earlier checks do not confirm the current settings.</p><dl class="domains-facts">
@foreach($lastKnown as $axis=>$fact)<div class="domains-fact"><dt>{{ ['dns'=>'Nameservers','website'=>'Website pointer','email'=>'Email pointer','registry'=>'Public registry expiry'][$axis] }}</dt><dd>@if($axis==='registry')@include('domains::partials.date',['date'=>$fact['value']])@else{{ $axis==='dns'?($summary->labels['domain.dns.'.$fact['value']]??'Details unavailable'):($fact['value']?'Platform pointer recorded':($fact['partial']?'Not confirmed (partial check)':'No platform pointer recorded')) }}@endif@if($fact['partial'] && $fact['value']!==false) · Partial check @endif<span class="domains-small">Recorded {{ \Carbon\CarbonImmutable::createFromTimestampUTC($fact['checkedAt'])->format('M j, Y, H:i') }} UTC</span></dd></div>@endforeach
</dl>@endif<p class="domains-small">{{ $summary->labels['domain.points.help'] }}</p></details>@endif
@endif
</article>
