<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchActions,WatchFacts};
class ViewConfiguration extends ViewRecord
{
    protected static string $resource=ProductMappingResource::class;
    protected function fillForm(): void{$this->data=[];}
    public function getTitle(): string{return 'Product domain mappings';}
    protected function getHeaderActions(): array{return [WatchActions::configuration('mapping',$this->getRecord()),WatchActions::enable('mapping',$this->getRecord())];}
    public function infolist(Schema $schema): Schema
    {
        $r=$this->getRecord();$facts=['Saved revision'=>(string)$r->revision,'Product'=>'Product #'.$r->product_id,'Primary property'=>$r->property_key,'Discovery'=>$r->enabled?'Enabled':'Disabled','Default profile'=>$r->expected_ns_profile_id?'Profile #'.$r->expected_ns_profile_id:'None','Preview · sample of 25 services'=>WatchFacts::preview($r)];
        $entries=[];foreach($facts as $label=>$value){$entries[]=TextEntry::make('fact_'.count($entries))->label($label)->state($value)->listWithLineBreaks()->extraAttributes(['style'=>'overflow-wrap:anywhere']);}
        return $schema->components([Section::make('Saved configuration')->description('Saved local settings only. Default feature gates still apply.')->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
