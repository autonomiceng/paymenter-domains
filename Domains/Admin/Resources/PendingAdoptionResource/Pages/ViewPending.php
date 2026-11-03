<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource;
class ViewPending extends ViewRecord
{
    protected static string $resource=PendingAdoptionResource::class;
    protected function fillForm(): void{$this->data=[];}
    protected function getHeaderActions(): array{return [];}
    public function mount(int|string $record): void{parent::mount($record);$this->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$this->getRecord()]));}
    public function getTitle(): string{return 'Registration setup pending';}
}
