{{-- MIT, Copyright (c) 2026 Autonomic Engineering. "Contact support" inside a sentence: a link, or plain words when tickets are not set up. --}}
@php($supportUrl = \Illuminate\Support\Facades\Route::has('tickets.create') ? route('tickets.create') : null)
@if ($supportUrl)<a class="domains-a" href="{{ $supportUrl }}">Contact support</a>@else Contact support @endif
