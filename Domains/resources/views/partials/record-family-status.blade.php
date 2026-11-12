@php
    $staff ??= false;
    // The read time is said once above the rows; a family checked at another time keeps its own.
    $instant = static fn ($at) => $at === null ? null : (is_numeric($at) ? (int) $at : strtotime((string) $at));
    $ownTime = $family['at'] && $instant($family['at']) !== $instant($readAt ?? null);
    // Customers: a full read is good news, so it gets no mark. The row's own value says what was read; a row with no other value ($asValue) says how many it found.
    $quiet = !$staff && $family['state'] === 'complete';
    $asValue ??= false;
    $count = $family['count'] ?? 0;
    $unit = match ($family['kind']) { 'nameservers' => 'nameserver', 'contacts' => 'contact', 'flags' => 'setting', default => 'record' };
@endphp
<span data-record-family="{{ $family['kind'] }}">
@if ($quiet)
    @if ($asValue && !($family['kind'] === 'glue' && ($family['hosts'] ?? [])))<span class="domains-card__v">{{ $count }} {{ $unit.($count === 1 ? '' : 's') }}</span>@endif
@elseif ($family['state'] === 'unknown')
    @if (($family['read_checking'] ?? false) || (!$staff && ($family['initial_read_pending'] ?? false)))
        @include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])
    @elseif (!$staff)
        {{-- Customers can't fix an unread record: a calm word, and the note above the rows says why once. --}}
        <span class="domains-muted">{{ \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::NOT_READ }}</span>
    @else
        @include('domains::partials.missing', ['cause'=>null])
    @endif
@else
    @include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>$staff?'warn':'info',default=>'muted'}]])
@endif
</span>
{{-- An unread family shows only Missing or Checking. Why is the same for every family, so record-read-note says it once above the rows. --}}
@if ($family['state'] !== 'unknown' && ((!$quiet && $family[$staff ? 'staff' : 'customer'] !== '') || $ownTime))
<span class="domains-sub">{{ $quiet ? '' : $family[$staff ? 'staff' : 'customer'] }} @if (!$staff && ($support ?? null) && ($family['support'] ?? false))@include('domains::partials.contact-support', ['before' => $support.' ', 'after' => '. '])@endif @if ($ownTime)Checked @include('domains::partials.moment',['at'=>$family['at']]).@endif</span>
@endif
@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment',['at'=>$family['last_good_at']])</span>@endif
