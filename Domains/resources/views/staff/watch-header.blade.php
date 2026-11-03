{{-- Scoped native header layout; no reads in Blade. --}}
<x-filament-panels::header class="domains-watch-header" :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()" :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" :subheading="$this->getSubheading()" />
@if($this->pendingCheck)<div wire:key="watch-poll-pending" wire:poll.5s="pollCheck"></div>
@elseif($this->getRecord()->state === 'active')<div wire:key="watch-poll-passive" wire:poll.60s="refreshFacts"></div>@endif
