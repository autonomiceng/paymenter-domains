<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\RecordHistoryPage;
/** The History tab of a TLD: its settings and price changes, newest first. The head, its actions and the tab bar are
 * shared with the Overview tab (Concerns\TldSalesPage). */
class TldHistory extends RecordHistoryPage
{
    use Concerns\TldSalesPage;
    protected static string $resource=TldResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    protected function actions(): array {return $this->tldActions();}
    protected function events(): ?array
    {Staff::requireView();return TldFacts::history((int)$this->getRecord()->server_id,(int)$this->getRecord()->id);}
}
