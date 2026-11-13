@php
    $staff ??= false;
    // The read time is said once above the rows; a family checked at another time keeps its own.
    $instant = static fn ($at) => $at === null ? null : (is_numeric($at) ? (int) $at : strtotime((string) $at));
    $ownTime = $family['at'] && $instant($family['at']) !== $instant($readAt ?? null);
    // Customers: a full read is good news, so it gets no mark. The row's own value says what was read; a row with no other value ($asValue) says how many it found.
    $retainedChecking = ($family['read_checking'] ?? false) && ($family['last_good_at'] ?? null) !== null;
    $quiet = !$staff && ($family['state'] === 'complete' || $retainedChecking);
    $asValue ??= false;
    $count = $family['count'] ?? 0;
    $unit = match ($family['kind']) { 'nameservers' => 'nameserver', 'contacts' => 'contact', 'flags' => 'setting', default => 'record' };
@endphp
<span data-record-family="{{ $family['kind'] }}">
@if ($quiet)
    @if ($asValue && !($family['kind'] === 'glue' && ($family['hosts'] ?? [])))<span class="domains-card__v">{{ $count }} {{ $unit.($count === 1 ? '' : 's') }}</span>@endif
@elseif ($family['state'] === 'unknown')
    @if (!$staff)
        {{-- Customers read State words: Checking while a read runs, otherwise Not read yet, and the note above the rows says why once. --}}
        {{ \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::state(($family['read_checking'] ?? false) || ($family['initial_read_pending'] ?? false) ? \Paymenter\Extensions\Servers\Domains\Presentation\State::Checking : \Paymenter\Extensions\Servers\Domains\Presentation\State::NotReadYet, \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer) }}
    @elseif ($family['read_checking'] ?? false)
        @include('domains::partials.status-pill', ['pill'=>['label'=>'Checking','tone'=>'info']])
    @elseif (!($family['read_failed'] ?? false))
        {{-- Nobody needs to fix a record that has not been read yet: a calm word, and the note above the rows says why once. Staff see Missing only once a read failed. --}}
        <span class="domains-muted">{{ \Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::NOT_READ }}</span>
    @else
        @include('domains::partials.missing', ['cause'=>null, 'calm'=>$calm ?? false])
    @endif
@elseif (!$staff)
    {{-- Customers read State words: Not supported for a family this registrar can't read, and for a partial read the same words as an unread one. --}}
    @php
        $word = match (true) {
            $family['value'] === \Paymenter\Extensions\Servers\Domains\Presentation\Plain::NotApplicable->label() => null,
            $family['state'] === 'unsupported' => \Paymenter\Extensions\Servers\Domains\Presentation\State::NotSupported,
            ($family['read_checking'] ?? false) || ($family['initial_read_pending'] ?? false) => \Paymenter\Extensions\Servers\Domains\Presentation\State::Checking,
            (bool) ($family['read_failed'] ?? false) => \Paymenter\Extensions\Servers\Domains\Presentation\State::CouldntRead,
            default => \Paymenter\Extensions\Servers\Domains\Presentation\State::NotReadYet,
        };
    @endphp
    {{ $word === null ? \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::plain(\Paymenter\Extensions\Servers\Domains\Presentation\Plain::NotApplicable) : \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::state($word, \Paymenter\Extensions\Servers\Domains\Presentation\Reader::Customer) }}
@else
    @include('domains::partials.status-pill', ['pill'=>['label'=>$family['value'],'tone'=>match($family['state']){'complete'=>'ok','partial'=>'warn',default=>'muted'}]])
@endif
</span>
{{-- An unread family shows only Not read, Missing or Checking. Why is the same for every family, so record-read-note says it once above the rows. --}}
@if ($family['state'] !== 'unknown' && ((!$quiet && $family[$staff ? 'staff' : 'customer'] !== '') || $ownTime))
<span class="domains-sub">{{ $quiet ? '' : $family[$staff ? 'staff' : 'customer'] }} @if (!$staff && ($support ?? null) && ($family['support'] ?? false))@include('domains::partials.contact-support', ['before' => $support.' ', 'after' => '. '])@endif @if ($ownTime)Checked @include('domains::partials.moment',['at'=>$family['at']]).@endif</span>
@endif
@if ($family['last_good_at'] && $family['state'] !== 'complete')<span class="domains-sub">Last confirmed: @include('domains::partials.moment',['at'=>$family['last_good_at']])</span>@endif
