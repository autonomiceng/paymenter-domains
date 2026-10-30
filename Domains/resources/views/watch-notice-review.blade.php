@component('layouts.app', ['title' => 'Check a monitoring alert', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">@include('domains::partials.styles')
<h1 class="domains-title">Check a monitoring alert</h1>
@error('resend')<div role="alert" class="domains-notice">{{ $message }}</div>@enderror
@if(session('watch_review'))<div class="domains-notice" role="status">{{ session('watch_review') }}</div>@endif
<dl><dt>Sent by</dt><dd>{{ $inspection['channel']==='mail'?'Email':ucfirst($inspection['channel']) }}</dd><dt>Status</dt><dd>{{ $inspection['state']==='accepted'?'Queued':ucfirst($inspection['state']) }}</dd><dt>{{ $inspection['evidence_type']==='email_log'?'Email log ID':'Notification ID' }}</dt><dd>{{ $inspection['native_id'] ?? 'Missing. Check Email Logs first.' }}</dd></dl>
<p>Check that it did not arrive and that the customer still wants alerts. Sending again can send a second copy.</p>
@if(in_array($inspection['state'],['accepted','uncertain'],true) && $inspection['native_id'])
<form method="POST" class="domains-form">@csrf<input type="hidden" name="_request_key" value="{{ $key }}">
<label class="domains-label" for="native-id">ID you checked</label><input class="domains-input" id="native-id" name="native_id" inputmode="numeric" required>
<label class="domains-label" for="reason">Reason for sending another copy</label><textarea class="domains-input" id="reason" name="reason" minlength="10" maxlength="500" required></textarea>
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<button class="domains-btn domains-btn--primary" type="submit">Send again</button></form>
@endif</div></div>
@endcomponent
