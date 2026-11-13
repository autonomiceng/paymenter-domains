{{-- MIT, Copyright (c) 2026 Autonomic Engineering. One setting on the domain page, the customer side of Panel\SettingsRow: the label with its "?"
     (help) and any read status under it (status), then the value and the action column. The row keeps the setting's id for links and polls. --}}
@props(['id', 'label', 'help' => false, 'pending' => false, 'status' => null])
<li @class(['domains-row', 'domains-row--pending' => $pending]) id="{{ $id }}" data-piece="settings-row"><span class="domains-row__l">{{ $label }}@if ($help)@include('domains::partials.help', ['id' => $id, 'label' => $label])@endif{{ $status }}</span>
{{ $slot }}
</li>
