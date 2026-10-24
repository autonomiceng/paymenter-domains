<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Grid,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchActions,WatchFacts};
class ViewConfiguration extends ViewRecord
{
    protected static string $resource=RoutingProfileResource::class;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    public function mount(int|string $record): void{parent::mount($record);$this->revision=(int)$this->getRecord()->revision;}
    protected function fillForm(): void{$this->data=[];}
    public function getTitle(): string{return (string)$this->getRecord()->name;}
    public function getBreadcrumbs(): array{return [RoutingProfileResource::getUrl()=>'DNS templates',$this->getTitle()];}
    protected function getHeaderActions(): array{return [WatchActions::enable('profile',$this->getRecord(),$this->revision),WatchActions::configuration('profile',$this->getRecord(),$this->revision)];}
    /** One line per kind of record; kinds with no values are left out. */
    private static function records(array $r): array
    {
        $out=[];foreach(['exact'=>'Hostnames','suffix'=>'Hostname suffixes','ips'=>'IP addresses'] as $type=>$label){if($r[$type]??[]){$out[]=$label.': '.implode(', ',$r[$type]);}}
        return $out?:['None'];
    }
    public function infolist(Schema $schema): Schema
    {
        $r=$this->getRecord();$used=WatchFacts::usedBy((int)$r->id);
        $entry=fn(string $label,mixed $value)=>TextEntry::make('fact_'.md5($label))->label($label)->state($value)->inlineLabel()->listWithLineBreaks()->extraAttributes(['style'=>'overflow-wrap:anywhere']);
        return $schema->components([Grid::make(['default'=>1,'lg'=>2])->columnSpanFull()->schema([
            Section::make('Records')->columnSpan(1)->schema([$entry('Status',$r->enabled?'On':'Off'),$entry('Nameservers',$r->nameservers)->fontFamily(\Filament\Support\Enums\FontFamily::Mono),$entry('Website records',self::records($r->website_endpoints)),$entry('Email records',self::records($r->email_endpoints))]),
            Section::make('Used by')->columnSpan(1)->schema([$entry('Monitored domains',(string)$used['domains']),$entry('Hosting products',(string)$used['products'])])])]);
    }
}
