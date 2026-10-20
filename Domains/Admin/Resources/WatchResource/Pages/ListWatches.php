<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
class ListWatches extends ListRecords
{
    protected static string $resource=WatchResource::class;
    public function getTitle(): string{return 'Domain watch';}
    public function getSubheading(): string{return 'Saved public checks for hosting domains. Recorded evidence may be partial or out of date.';}
}
