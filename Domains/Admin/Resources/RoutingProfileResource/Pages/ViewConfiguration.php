<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchActions,WatchFacts};
class ViewConfiguration extends ViewRecord
{
    protected static string $resource=RoutingProfileResource::class;
    protected function fillForm(): void{$this->data=[];}
    public function getTitle(): string{return 'DNS and routing profiles';}
    protected function getHeaderActions(): array{return [WatchActions::configuration('profile',$this->getRecord()),WatchActions::enable('profile',$this->getRecord())];}
    private static function rules(array $r): array{$out=[];foreach($r as $type=>$values){foreach($values as $v){$out[]=match($type){'ips'=>'IP addresses: ','suffix'=>'Allowed hostname suffixes: ',default=>'Hostnames: '}.$v;}}return $out?:['None'];}
    public function infolist(Schema $schema): Schema
    {
        $r=$this->getRecord();$facts=['Saved revision'=>(string)$r->revision,'Profile'=>$r->name,'Status'=>$r->enabled?'Enabled':'Disabled','Impact'=>WatchFacts::impact((int)$r->id),'Nameservers'=>$r->nameservers,'Website rules'=>self::rules($r->website_endpoints),'Email rules'=>self::rules($r->email_endpoints)];
        $entries=[];foreach($facts as $label=>$value){$entries[]=TextEntry::make('fact_'.count($entries))->label($label)->state($value)->listWithLineBreaks()->extraAttributes(['style'=>'overflow-wrap:anywhere']);}
        return $schema->components([Section::make('Saved configuration')->description('Saved local settings only. Default feature gates still apply.')->columnSpanFull()->columns(2)->schema($entries)]);
    }
}
