<x-filament-panels::header :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()" :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
@php($poll = $this->managementPoll())
@if ($poll['stopped'])<p role="status" class="domains-hint">A change is still waiting. Reload this page to check it again.</p>@endif
{{-- Follow queued completion even when staff switch tabs, for a bounded time (managementPoll). --}}
@if ($poll['every'] === '10s')<div wire:key="management-poll-slow" wire:poll.10s.keep-alive="pollManagement"></div>
@elseif ($poll['every'] === '2s')<div wire:key="management-poll-fast" wire:poll.2s.keep-alive="pollManagement"></div>@endif
