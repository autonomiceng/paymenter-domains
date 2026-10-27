<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions;
class ListConfiguration extends ListRecords
{
    protected static string $resource=ProductMappingResource::class;
    public function getTitle(): string{return 'Hosting products';}
    public function getBreadcrumbs(): array{return [];}
    public function getSubheading(): ?string{return null;}
    protected function getHeaderActions(): array{return [WatchActions::configuration('mapping',null)];}
}
