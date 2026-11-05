{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Why registrar records without a current read show Missing or Checking: said once, above the rows. --}}
{{-- Staff already read the queue state on the Next registrar read line, so they get only what that line does not say. --}}
@php
    $staff ??= false;
    $unread = !($recordFamilies['cancelled_without_records'] ?? false) && collect($recordFamilies['rows'] ?? [])->contains('state', 'unknown');
@endphp
@if ($unread)
@if ($recordFamilies['read_checking'] ?? false)
@unless ($staff)<p class="domains-hint">Registrar read queued for @include('domains::partials.moment', ['at' => gmdate(DATE_ATOM, (int) $recordFamilies['next_read'])]).</p>@endunless
@elseif ($recordFamilies['records_paused'] ?? false)
<p class="domains-hint">Record checks are paused or not set up for this registrar account.@if ($staff) Check Processing and record-read setup.@endif</p>
@elseif (!$staff || (($recordFamilies['read_cause'] ?? null) && !($recordFamilies['read_code'] ?? null)))
<p class="domains-hint">{{ $recordFamilies['read_cause'] ?? 'No registrar read is queued.' }} Missing records will appear after a successful registrar read.</p>
@endif
@endif
