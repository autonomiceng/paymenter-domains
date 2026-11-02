<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Resources\Pages\Page;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
/** Old address of the price list. Prices now show on What you sell. */
class PriceList extends Page
{
    protected static string $resource=TldResource::class;
    public static function canAccess(array $parameters=[]): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();$this->redirect(WhatYouSell::getUrl());}
}
