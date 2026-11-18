{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The one button row of a domain edit dialog (DESIGN.md P-COH-1), as on Renew and every confirm:
     Cancel, then the one primary, on the right. Script closes the dialog. Without script, ?edit= rendered it open in place, so Cancel goes back to the page. In a scrolling dialog the row stays in view. --}}
<div class="domains-buttons domains-edit__buttons">
@if ($open ?? false)<a class="domains-btn domains-btn--secondary" href="{{ $cancel }}">Cancel</a>@else<button type="button" class="domains-btn domains-btn--secondary" data-dialog-close>Cancel</button>@endif
<button type="submit" class="domains-btn domains-btn--primary"@if ($footerNote ?? null) aria-describedby="{{ $footerNoteId }}"@endif>{{ $label }}</button>
@if ($footerNote ?? null)<p class="domains-sub domains-edit__note" id="{{ $footerNoteId }}">{{ $footerNote }}</p>@endif
</div>
