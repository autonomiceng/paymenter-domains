{{-- MIT, Copyright (c) 2026 Autonomic Engineering. An editor opened from a page row. Script shows it as a modal; without script ?edit= renders it open in place. --}}
<dialog id="edit-{{ $id }}" class="domains-confirm domains-edit" aria-labelledby="edit-{{ $id }}-title" @if ($open) open @endif>
<h3 id="edit-{{ $id }}-title" class="domains-h3">{{ $title }}</h3>
{{ $slot }}
<div class="domains-edit__close">@if ($open)<a class="domains-btn domains-btn--ghost" href="{{ $cancel }}">Cancel</a>@else<button type="button" class="domains-btn domains-btn--ghost" data-dialog-close>Cancel</button>@endif</div>
</dialog>
