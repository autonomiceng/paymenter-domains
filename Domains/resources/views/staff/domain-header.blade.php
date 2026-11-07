<x-filament-panels::header :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()" :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
@php($pending = array_filter($this->managementProgress(), fn ($row) => $row['pending']))
@if ($pending || $this->syncPending())
{{-- Follow queued completion even when staff switch tabs; pending state bounds this poll. --}}
@if (array_filter($pending, fn ($row) => $row['slow']))<div wire:key="management-poll-slow" wire:poll.10s.keep-alive="pollManagement"></div>
@else<div wire:key="management-poll-fast" wire:poll.2s.keep-alive="pollManagement"></div>@endif
@endif
