{{-- MIT, Copyright (c) 2026 Autonomic Engineering. A value we have not got: its State (Not read yet when it fills in by itself,
     Couldn't read otherwise, or the $value given) with the cause under it, then the one fix as a link, a button, markup or plain words. --}}
@props(['cause' => null, 'fix' => null, 'calm' => false, 'value' => null])
{{ $value ?? \Paymenter\Extensions\Servers\Domains\Presentation\CustomerValues::unread($cause, $calm) }}@if ($fix instanceof \Illuminate\Contracts\Support\Htmlable) {{ $fix }}@elseif (!empty($fix['url'])) <a class="{{ ($fix['button'] ?? false) ? 'domains-btn domains-btn--primary domains-btn--sm' : 'domains-a' }}" href="{{ $fix['url'] }}">{{ $fix['label'] }}</a>@elseif (!empty($fix['label'])) <span class="domains-sub">{{ $fix['label'] }}</span>@endif
