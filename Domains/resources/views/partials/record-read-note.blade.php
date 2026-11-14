{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Why registrar records without a current read show Missing or Checking: said once, above the rows.
     Checking only while a read is due or running; a later read is said as its time. --}}
{{-- Staff already read the queue state on the Next registrar read line, so they get only what that line does not say. --}}
@php
    $staff ??= false;
    $unread = !($recordFamilies['cancelled_without_records'] ?? false) && collect($recordFamilies['rows'] ?? [])->contains('state', 'unknown');
@endphp
@if ($unread || ($recordFamilies['read_checking'] ?? false))
@if (!$staff && ($recordFamilies['initial_read_pending'] ?? false))
@unless (($notice['text'] ?? null) === \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::INITIAL_READ)<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::INITIAL_READ }}</p>@endunless
@elseif ($recordFamilies['read_checking'] ?? false)
<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::state(\Paymenter\Extensions\Servers\Domains\Presentation\State::Checking, $staff ? \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Staff : \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer) }}</p>
@elseif (!$staff && ($recordFamilies['next_read'] ?? null) && !($recordFamilies['read_cause'] ?? null) && !($recordFamilies['read_code'] ?? null) && !($recordFamilies['records_paused'] ?? false))
<p class="domains-hint">Registrar read queued for @include('domains::partials.moment', ['at' => gmdate(DATE_ATOM, (int) $recordFamilies['next_read'])]).</p>
@elseif ($recordFamilies['records_paused'] ?? false)
<p class="domains-hint">Record checks are paused or not set up for this registrar account.@if ($staff) Check Processing and record-read setup.@endif</p>
@elseif (!$staff)
<p class="domains-hint">{{ \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::NOT_READ_NOTE }}</p>
@elseif (($recordFamilies['read_cause'] ?? null) && !($recordFamilies['read_code'] ?? null))
<p class="domains-hint">{{ $recordFamilies['read_cause'] }} Missing records will appear after a successful registrar read.</p>
@endif
@endif
