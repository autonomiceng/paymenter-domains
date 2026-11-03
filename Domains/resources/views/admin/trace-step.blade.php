{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One step of a Sheet trace. The help shows on hover and on keyboard focus, so the line stays short. --}}
<div class="domains-trace-line">
    <span class="domains-trace-title" @if (! empty($help)) tabindex="0" x-tooltip="{ content: @js($help), theme: $store.theme }" @endif>{{ $label }}</span>
    @if (filled($value ?? null)) <span class="domains-trace-word">{{ $value }}</span> @endif
</div>
@if (filled($detail ?? null))
    <div class="domains-trace-detail">{{ $detail }}</div>
@endif
