<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchActions,WatchFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage,Section};
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Plain,Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{DnsTemplateUsage,SiteDnsDefault};
/** A DNS template: its records and where it is used, with the monitoring switch on its own row. Edit and Delete are
 * the page's native header actions. */
class ViewConfiguration extends RecordDetailPage
{
    protected static string $resource=RoutingProfileResource::class;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    public function mount(int|string $record): void{parent::mount($record);$this->revision=(int)$this->getRecord()->revision;}
    protected function head(): Head
    {
        $r=$this->getRecord();
        return Head::make((string)$r->name,Reader::Staff)->state($r->enabled?State::Active:State::Paused,'template-'.$r->id);
    }
    public function getBreadcrumbs(): array{return [RoutingProfileResource::getUrl()=>'DNS templates',$this->getTitle()];}
    protected function actions(): array{return [WatchActions::configuration($this->getRecord(),$this->revision),WatchActions::deleteTemplate($this->getRecord(),$this->revision)];}
    public function refreshTemplate(): void
    {
        $this->record=$this->getRecord()->fresh();$this->revision=(int)$this->record->revision;
        unset($this->cachedSchemas['infolist'],$this->cachedSchemas['content']);$this->cachedActions=[];$this->cachedHeaderActions=[];$this->cacheInteractsWithHeaderActions();
    }
    public function enableAction(): Action{return WatchActions::enable($this->getRecord(),$this->revision);}
    public function disableAction(): Action{return WatchActions::disable($this->getRecord(),$this->revision);}
    /** One line per kind of record; kinds with no values are left out. @return list<string> */
    private static function records(array $r): array
    {
        $out=[];foreach(['exact'=>'Hostnames','suffix'=>'Hostname suffixes','ips'=>'IP addresses'] as $type=>$label){if($r[$type]??[]){$out[]=$label.': '.implode(', ',$r[$type]);}}
        return $out;
    }
    private static function list(array $items): Value{return ($fact=Fact::lines($items))===null?Value::plain(Plain::None):Value::fact($fact);}
    protected function sections(): array
    {
        $r=$this->getRecord();$profile=$r->readProfile();$staff=Reader::Staff;
        $unreadable=Value::state(State::CouldntRead,$staff);
        $records=$profile===null?[Row::notice('template',Value::state(State::CouldntRead,$staff,WatchFacts::TEMPLATE_UNAVAILABLE)),
                Row::make('Nameservers',$unreadable),Row::make('Website records',$unreadable),Row::make('Email records',$unreadable)]
            :[Row::make('Nameservers',self::list($profile->nameservers)),Row::make('Website records',self::list(self::records($profile->website))),
                Row::make('Email records',self::list(self::records($profile->email)))];
        $default=SiteDnsDefault::profile();$usage=WatchFacts::usage((int)$r->id);
        return [Section::make('Records',$records),Section::make('Used by',[
            Row::make('New domains use',$default===null?Value::state(State::NotSet,$staff):Value::fact(Fact::attr($default,'name')??Fact::count((int)$default->id))),
            Row::make('Monitored domains',Value::fact(Fact::count((int)$usage['checked']),WatchFacts::usageNote($usage))),
            Row::make('Monitoring',Value::plain($r->enabled?Plain::On:Plain::Off, DnsTemplateUsage::refusal((int)$r->id) || $profile===null ? null : 'Monitoring checks domains against this template.'))
                ->actions($this->enableAction(),$this->disableAction()),
        ])];
    }
}
