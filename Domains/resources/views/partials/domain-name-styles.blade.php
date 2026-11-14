{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Styles for DomainName::cell(), shared by staff and customer tables. --}}
<style>
/* A name sizes to its content up to 30 characters wide (20 on a phone), then its head shortens with '...' and the tail
   with the TLD stays. Its width is its min-content too, so a table never squeezes it below that. The cap is in ch of the
   name's monospace font, plus 1px because a glyph is a hair wider than 1ch: whole glyphs fit, so no gap shows before the tail. */
.domains-dn{display:inline-flex;width:max-content;max-width:calc(30ch + 1px);white-space:nowrap;vertical-align:bottom;position:relative}
@media(max-width:39.99rem){.domains-dn{max-width:calc(20ch + 1px)}}
.domains-dn-head{min-width:0;overflow:hidden;text-overflow:ellipsis}
.domains-dn-tail{flex:none}
/* The full name for screen readers. Copy skips it, so a copied name is the two visible parts once. */
.domains-dn-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;user-select:none}
</style>
