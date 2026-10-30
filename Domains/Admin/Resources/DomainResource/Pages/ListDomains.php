<?php
// Adapted from Enom 444f31c ListDomains; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;
    public function getTitle(): string { return 'Domains'; }
    public function getBreadcrumbs(): array { return []; }
    protected function getHeaderActions(): array { return [\Filament\Actions\Action::make('adopt')->label('Import domain')->visible(fn () => config('domains.adoption.enabled', false) === true && collect(\Paymenter\Extensions\Servers\Domains\Services\AdoptionCommit::PERMISSIONS)->every(fn ($permission) => \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::can($permission)))->url(DomainResource::getUrl('adopt'))]; }
}
