{{-- MIT, Copyright (c) 2026 Autonomic Engineering. Demo and Test tags: one neutral look for light and dark themes. Shared by the client stylesheet and inline tags on staff and native pages; both themes set .dark on a parent. --}}
.domains-tag-tip { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); white-space:nowrap; }
/* Body font even inside a mono domain-name heading. */
.domains-demo-tag { border:1px solid rgb(15 23 42 / .18); background:none; color:#475569; font-family:var(--font-sans, system-ui, sans-serif); letter-spacing:0; }
.dark .domains-demo-tag { border-color:rgb(255 255 255 / .14); color:#aebbd0; }
