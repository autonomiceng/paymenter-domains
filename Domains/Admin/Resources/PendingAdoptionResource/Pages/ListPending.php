<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource;
class ListPending extends ListRecords
{
    protected static string $resource=PendingAdoptionResource::class;
    public function getTitle(): string{return 'Adoption pending';}
    public function getSubheading(): string{return 'Read-only setup queue. These registrations are not approved for renewal handling.';}
    protected function getHeaderActions(): array{return [];}
    public function mount(): void{parent::mount();$this->redirect(PendingAdoptionResource::redirectUrl());}
}
