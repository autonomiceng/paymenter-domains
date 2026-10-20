{{-- Scoped native header layout; no reads in Blade. --}}
<style>.domains-watch-header{flex-wrap:wrap!important}.domains-watch-header>div:first-child{flex:1 1 20rem;min-width:min(100%,20rem)}.domains-watch-header .fi-header-actions-ctn{flex:1 1 30rem;min-width:0}.domains-watch-header .fi-ac{flex-wrap:wrap}</style>
<x-filament-panels::header class="domains-watch-header" :actions="$this->getCachedHeaderActions()" :actions-alignment="$this->getHeaderActionsAlignment()" :breadcrumbs="$this->getBreadcrumbs()" :heading="$this->getHeading()" />
