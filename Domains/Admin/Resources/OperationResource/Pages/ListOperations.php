<?php
// Adapted from Enom 444f31c ListOperations; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
class ListOperations extends ListRecords
{
    protected static string $resource=OperationResource::class;
    public function getTitle(): string { Staff::requireView(); return 'Registrar requests'; }
    protected function getHeaderActions(): array { return []; }
}
