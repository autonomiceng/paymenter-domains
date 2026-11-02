{{-- A moment in time (last checked, request time). The script shows it in the viewer's time zone; the server text is the fallback. --}}
@php
    $instant = null;
    try { $instant = $at instanceof \DateTimeInterface ? \Carbon\CarbonImmutable::instance($at) : (is_string($at) && $at !== '' ? \Carbon\CarbonImmutable::parse($at) : null); } catch (\Throwable) {}
@endphp
@if ($instant)<time datetime="{{ $instant->toIso8601String() }}" data-domains-time="{{ $style ?? 'full' }}" wire:ignore wire:key="moment-{{ $instant->timestamp }}" x-data x-init="const date = new Date($el.dateTime); if (!isNaN(date)) $el.textContent = new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short'}).format(date)">{{ $instant->format('M j, Y, g:i A T') }}</time>@else{{ $fallback ?? 'Never' }}@endif
