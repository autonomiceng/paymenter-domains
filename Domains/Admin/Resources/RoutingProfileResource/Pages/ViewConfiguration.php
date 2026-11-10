<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\{FontFamily,Width};
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Sheet,StatusMark,WatchActions,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{DnsTemplateUsage,SiteDnsDefault};
class ViewConfiguration extends ViewRecord
{
    protected static string $resource=RoutingProfileResource::class;
    protected Width|string|null $maxContentWidth=Sheet::WIDTH;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    public function mount(int|string $record): void{parent::mount($record);$this->revision=(int)$this->getRecord()->revision;}
    protected function fillForm(): void{$this->data=[];}
    public function getTitle(): string{return (string)$this->getRecord()->name;}
    /** The status once, under the title. A template that can't be read says so after it. */
    public function getSubheading(): string|Htmlable|null
    {
        $r=$this->getRecord();$status=RoutingProfileResource::status($r);
        return StatusMark::line($status['tone'],$status['label'],'template-'.$r->id,$r->readProfile()===null?WatchFacts::TEMPLATE_UNAVAILABLE:null);
    }
    public function getBreadcrumbs(): array{return [RoutingProfileResource::getUrl()=>'DNS templates',$this->getTitle()];}
    protected function getHeaderActions(): array{return [WatchActions::configuration($this->getRecord(),$this->revision),WatchActions::deleteTemplate($this->getRecord(),$this->revision)];}
    public function refreshTemplate(): void
    {
        $this->record=$this->getRecord()->fresh();$this->revision=(int)$this->record->revision;
        unset($this->cachedSchemas['infolist'],$this->cachedSchemas['content']);$this->cachedActions=[];$this->cachedHeaderActions=[];$this->cacheInteractsWithHeaderActions();
    }
    public function enableAction(): Action{return WatchActions::enable($this->getRecord(),$this->revision);}
    public function disableAction(): Action{return WatchActions::disable($this->getRecord(),$this->revision);}
    /** One line per kind of record; kinds with no values are left out. */
    private static function records(array $r): array
    {
        $out=[];foreach(['exact'=>'Hostnames','suffix'=>'Hostname suffixes','ips'=>'IP addresses'] as $type=>$label){if($r[$type]??[]){$out[]=$label.': '.implode(', ',$r[$type]);}}
        return $out?:['None'];
    }
    public function infolist(Schema $schema): Schema
    {
        $r=$this->getRecord();$profile=$r->readProfile();
        $row=fn(string $label,mixed $value)=>Sheet::row(TextEntry::make('fact_'.md5($label))->label($label)->state($value)->listWithLineBreaks()->extraAttributes(['class'=>'domains-wrap']));
        $nameservers=Sheet::row(TextEntry::make('nameservers_list')->label('Nameservers')->state($profile?->nameservers??Missing::LABEL)->listWithLineBreaks()->fontFamily($profile?FontFamily::Mono:null)->extraAttributes(['class'=>'domains-wrap']));
        return $schema->components([Sheet::single([
            Sheet::section('Records',[$nameservers,$row('Website records',$profile?self::records($profile->website):Missing::LABEL),$row('Email records',$profile?self::records($profile->email):Missing::LABEL)]),
            Sheet::section('Used by',[
                Sheet::row(TextEntry::make('new_domains_use')->label('New domains use')->state(SiteDnsDefault::profile()?->name??Missing::LABEL)),
                Sheet::row(TextEntry::make('monitored_domains')->label('Monitored domains')->state((string)($usage=WatchFacts::usage((int)$r->id))['checked'])->belowContent(WatchFacts::usageNote($usage))->extraAttributes(['class'=>'domains-wrap'])),
                Sheet::row(TextEntry::make('monitoring')->label('Monitoring')->state($r->enabled?'On':'Off')
                    ->belowContent(DnsTemplateUsage::refusal((int)$r->id)??($profile===null?WatchFacts::TEMPLATE_UNAVAILABLE:'Monitoring checks domains against this template.')),
                    [$this->enableAction(),$this->disableAction()]),
            ]),
        ])]);
    }
}
