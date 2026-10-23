<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource;
class ViewPending extends ViewRecord
{
    protected static string $resource=PendingAdoptionResource::class;
    protected function fillForm(): void{$this->data=[];}
    protected function getHeaderActions(): array{return [];}
    public function getTitle(): string{return 'Registration setup pending';}
    public function infolist(Schema $schema): Schema
    {$d=$this->getRecord();$entries=[];foreach(['Domain'=>$d->domain,'Customer'=>'Customer #'.$d->user_id,'Service'=>'Service #'.$d->service_id,'Next step'=>'Verify this registration before handling renewals. No adoption or financial actions are available here.']as$k=>$v){$entries[]=TextEntry::make('fact_'.count($entries))->label($k)->state($v)->extraAttributes(['style'=>'overflow-wrap:anywhere']);}return $schema->components([Section::make('Adoption pending')->schema($entries)]);}
}
