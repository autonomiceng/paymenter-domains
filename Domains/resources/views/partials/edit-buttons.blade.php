{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The one button row of a domain edit dialog (DESIGN.md P-COH-1), as on Renew and every confirm:
     Cancel, then the one primary, on the right. `aside` is a quieter third choice that sits apart on the left. Script closes the dialog;
     without script ?edit= rendered it open in place, so Cancel goes back to the page. In a scrolling dialog the row stays in view. --}}
<div class="domains-buttons domains-edit__buttons">
@isset($aside){{ $aside }}@endisset
@if ($open ?? false)<a class="domains-btn domains-btn--secondary" href="{{ $cancel }}">Cancel</a>@else<button type="button" class="domains-btn domains-btn--secondary" data-dialog-close>Cancel</button>@endif
<button type="submit" class="domains-btn domains-btn--primary">{{ $label }}</button>
</div>
