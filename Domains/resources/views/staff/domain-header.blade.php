<x-filament-panels::header :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()" :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
@php($pending = array_filter($this->managementProgress(), fn ($row) => $row['pending']))
@if ($pending)
@if (array_filter($pending, fn ($row) => $row['slow']))<div wire:key="management-poll-slow" wire:poll.10s="pollManagement"></div>
@else<div wire:key="management-poll-fast" wire:poll.2s="pollManagement"></div>@endif
@endif
