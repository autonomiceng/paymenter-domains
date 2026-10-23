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
    public function getTitle(): string{return 'Product domain mappings';}
    public function getSubheading(): string{return 'New configurations start disabled. Saving or enabling does not run discovery or public checks. Review the saved mapping preview before enabling.';}
    protected function getHeaderActions(): array{return [WatchActions::configuration('mapping',null)];}
}
