{{-- MIT, Copyright (c) 2026 Autonomic Engineering. The head of every page type: the native header with the page's own heading, line and actions, then its refresh when it waits on a check. --}}
<x-filament-panels::header class="domains-panel-page-head" :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()" :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
@if($poll !== null)<div wire:key="panel-poll-{{ $poll[0] }}" wire:poll.{{ $poll[1] }}s="{{ $poll[0] }}"></div>@endif
