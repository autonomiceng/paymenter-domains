{{-- MIT, Copyright (c) 2026 Autonomic Engineering. "Contact support" with the words around it. With no way to reach us set up, nothing: the page keeps only what is true. --}}
@php($supportUrl = \Paymenter\Extensions\Servers\Domains\Presentation\Missing::supportUrl())
@if ($supportUrl){{ $before ?? '' }}<a class="domains-a" href="{{ $supportUrl }}">Contact support</a>{{ $after ?? '' }}@endif
