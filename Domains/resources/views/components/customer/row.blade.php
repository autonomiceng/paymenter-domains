{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One sheet row, the customer side of Panel\Row (Panel\SettingsRow with setting, for a value the customer chooses): the label with its "?" (help),
     the value (a Panel\Value or the row's own markup) with any line under it, then the action column. --}}
@props(['label', 'help' => null, 'actions' => null, 'setting' => false])
<div data-piece="{{ $setting ? 'settings-row' : 'row' }}"><dt>{{ $label }}@if ($help !== null) {{ $help }}@endif</dt><dd>{{ $slot }}@if ($actions !== null && trim((string) $actions) !== '')<span class="domains-row__a domains-card__a">{{ $actions }}</span>@endif</dd></div>
