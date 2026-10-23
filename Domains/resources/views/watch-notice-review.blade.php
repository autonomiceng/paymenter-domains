@component('layouts.app', ['title' => 'Public notice delivery review', 'sidebar' => true])
<div class="container mt-14 mb-10"><div class="domains">@include('domains::partials.styles')
<h1 class="domains-title">Public notice delivery review</h1>
@error('resend')<div role="alert" class="domains-notice">{{ $message }}</div>@enderror
@if(session('watch_review'))<div class="domains-notice" role="status">{{ session('watch_review') }}</div>@endif
<dl><dt>Channel</dt><dd>{{ $inspection['channel'] }}</dd><dt>State</dt><dd>{{ $inspection['state']==='accepted'?'Queued':$inspection['state'] }}</dd><dt>Native {{ $inspection['evidence_type'] }} ID</dt><dd>{{ $inspection['native_id'] ?? 'Not recorded; inspect native evidence before proceeding' }}</dd></dl>
<p>Review the native evidence and the customer’s current preferences. A queue exception may follow acceptance. Resending can send another copy and requires a new audited request.</p>
@if(in_array($inspection['state'],['accepted','uncertain'],true) && $inspection['native_id'])
<form method="POST" class="domains-form">@csrf<input type="hidden" name="_request_key" value="{{ $key }}">
<label class="domains-label" for="native-id">Reviewed native evidence ID</label><input class="domains-input" id="native-id" name="native_id" inputmode="numeric" required>
<label class="domains-label" for="reason">Reason for sending another copy</label><textarea class="domains-input" id="reason" name="reason" minlength="10" maxlength="500" required></textarea>
@foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<button class="domains-btn domains-btn--primary" type="submit">Record and request resend</button></form>
@endif</div></div>
@endcomponent
