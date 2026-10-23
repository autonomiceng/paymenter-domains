@component('layouts.app', ['title' => 'Domain check notifications', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">
@include('domains::partials.styles')
<h1 class="domains-title">{{ $name }}</h1>
<p class="domains-subtitle">Public domain checks · Dates are UTC</p>
@if(session('watch_saved'))<div class="domains-notice" role="status">{{ session('watch_saved') }}</div>@endif
@error('preferences')<div class="domains-notice" role="alert">{{ $message }}</div>@enderror
<section class="domains-panel domains-section" aria-labelledby="watch-notice-heading"><h2 id="watch-notice-heading" class="domains-h2">Latest notices</h2>
@foreach($notices as $notice)<div class="domains-notice"><p>
@if($notice['kind']==='expiring')The public registry lists {{ $notice['date'] }} as the expiry date for {{ $name }}. Public registry dates may not show recent changes. Check renewal with your registration provider.
@elseif($notice['kind']==='expired')The public registry expiry date for {{ $name }} was reached on {{ $notice['date'] }}. Contact your registration provider to check its status. Whether it can still be renewed depends on your registration provider and the registry.
@elseif($notice['kind']==='ns_changed')As of {{ $notice['date'] }}, {{ $name }} no longer uses the nameservers provided by {{ $notice['company'] }}. If you did not expect this, contact support.
@else As of {{ $notice['date'] }}, {{ $name }} uses the nameservers provided by {{ $notice['company'] }} again.
@endif</p></div>@endforeach
@if(in_array('registry_expiry_reached',$portal,true) && !in_array('expired',array_column($notices,'kind'),true))<p>The last recorded public registry expiry date for {{ $name }} was {{ $expiryDate }}. Check its registration status with your registration provider.</p>@endif
@if(in_array('ns_changed',$portal,true) && !in_array('ns_changed',array_column($notices,'kind'),true))<p>Since {{ $episodeDate }}, {{ $name }} has not used the nameservers provided by {{ $company }}. If you did not expect this, contact support.</p>@endif
@if(in_array('ns_pending',$portal,true))<p>As of {{ $statusDate }}, we are checking a possible nameserver change for {{ $name }}. We will check again to confirm it. No action is needed yet.</p>@endif
@if(in_array('expiry_unavailable',$portal,true))<p>As of {{ $statusDate }}, we cannot read current public registration details for {{ $name }}, so expiry notices are paused until a check succeeds. We will keep trying. Previous notices remain visible.</p>@endif
@if(in_array('dns_unavailable',$portal,true))<p>As of {{ $statusDate }}, we cannot read current DNS details for {{ $name }}, so nameserver notices are paused until a check succeeds. We will keep trying. Previous notices remain visible.</p>@endif
@if(!$notices && !$portal)<p>No public domain check notices for {{ $name }}.</p>@endif
@if($checked)<p>Latest check: <time datetime="{{ gmdate('c',$checked) }}">{{ \Carbon\CarbonImmutable::createFromTimestampUTC($checked)->format('F j, Y, H:i') }} UTC</time>.</p>@endif
<p><a class="domains-a" href="{{ route('tickets.create') }}">Contact support</a>. These checks show public registration and DNS details. They do not test website or email availability.</p></section>
<section class="domains-panel domains-section" aria-labelledby="watch-preference-heading"><h2 id="watch-preference-heading" class="domains-h2">Domain check notifications</h2>
@if(!$preferences['account']['enabled'])<p class="domains-notice">Public domain check notices are off for all your domains, including {{ $name }}. Checks continue and results appear here.</p>
@elseif(!$preferences['target']['enabled'])<p class="domains-notice">Notices about {{ $name }} are paused. Checks continue and results appear here.</p>@endif
<form method="POST" action="{{ url('/domains/checks/'.$kind.'/'.$id) }}" class="domains-form domains-stack">
@csrf<input type="hidden" name="_request_key" value="{{ $key }}"><input type="hidden" name="account_revision" value="{{ $preferences['account']['revision'] }}"><input type="hidden" name="target_revision" value="{{ $preferences['target']['revision'] }}">
<fieldset class="domains-stack-sm"><legend class="domains-label">All domains</legend><input type="hidden" name="email" value="0"><label class="domains-checkbox"><input type="checkbox" name="email" value="1" @checked($preferences['account']['enabled']) aria-describedby="watch-account-help"> Send public domain check notices</label>
<p id="watch-account-help">Covers email and in-app notices for every domain we check. Your <a class="domains-a" href="{{ route('account.notifications') }}">notification settings</a> decide which of these you get.</p>
</fieldset><fieldset class="domains-stack-sm"><legend class="domains-label">This domain</legend><input type="hidden" name="notices" value="0"><label class="domains-checkbox"><input type="checkbox" name="notices" value="1" @checked($preferences['target']['enabled']) aria-describedby="watch-target-help"> Send notices about {{ $name }}</label>
<p id="watch-target-help">Turn this off to pause notices about this domain. We still check it and show results on this page.</p>
</fieldset><p>These settings do not affect invoices or renewal reminders for domains registered with {{ $company }}.</p>
<button type="submit" class="domains-btn domains-btn--primary">Save preferences</button>
</form></section></div></div>
@endcomponent
