{{-- MIT, Copyright (c) 2026 Autonomic Engineering. --}}
<div class="domains-edit__heading">
<h3 id="edit-{{ $id }}-title" class="domains-h3">{{ $title }}</h3>
@if ($open)
<a class="domains-edit__dismiss" href="{{ $cancel }}" aria-label="Close">✕</a>
@else
<button class="domains-edit__dismiss" type="button" data-dialog-close aria-label="Close">✕</button>
@endif
</div>
