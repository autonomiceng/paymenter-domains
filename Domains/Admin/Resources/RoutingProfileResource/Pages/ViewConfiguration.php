<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Sheet;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Trace;
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
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
    protected const PAGE_CLASSES = [...parent::PAGE_CLASSES, 'domains-template-detail'];
    protected static string $resource=RoutingProfileResource::class;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    public function mount(int|string $record): void{parent::mount($record);$this->revision=(int)$this->getRecord()->revision;}
    protected function head(): Head
    {
        $r=$this->getRecord();
        return Head::make((string)$r->name,Reader::Staff)->note($r->enabled?'Monitoring is on.':'Monitoring is off.');
    }
    public function getBreadcrumbs(): array{return [RoutingProfileResource::getUrl()=>'DNS templates',$this->getTitle()];}
    protected function actions(): array
    {
        $profile = $this->getRecord();
        return [Action::make('save')->label('Edit')->color('gray')->visible(fn () => WatchActions::canEditTemplate($profile))
            ->disabled(!$profile->readProfile())->tooltip(!$profile->readProfile() ? WatchFacts::TEMPLATE_UNAVAILABLE : null)
            ->url(RoutingProfileResource::getUrl('edit', ['record' => $profile])), $this->deleteAction()];
    }
    public function deleteAction(): Action { return WatchActions::deleteTemplate($this->getRecord(),$this->revision); }
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
        $out=[];foreach(['exact'=>'Hostnames','suffix'=>'Hostnames ending in','ips'=>'IP addresses'] as $type=>$label){if($r[$type]??[]){$out[]=$label.': '.implode(', ',$r[$type]);}}
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
        $refusal = DnsTemplateUsage::refusal($r->id);
        $reason = $r->enabled ? $refusal : null;
        $monitor = Row::make('Monitoring', Value::plain($r->enabled ? Plain::On : Plain::Off,
            $reason ?? ($profile ? 'Checks compare domains against this template.' : WatchFacts::TEMPLATE_UNAVAILABLE)))
            ->actions($this->enableAction(), $this->disableAction());
        $controls = [$monitor];
        if ($refusal && $this->deleteAction()->isVisible()) {
            $controls[] = Row::make('Can delete', Value::plain(Plain::No, $refusal));
        }
        return [Section::make('Records', $records), Section::make('Monitoring', $controls)];
    }
    protected function rail(): array
    {
        $r = $this->getRecord();
        $default = SiteDnsDefault::profile();
        $isDefault = $default?->id === $r->id;
        $usage = WatchFacts::usage($r->id);
        $new = Row::make('New domains', Value::plain($isDefault ? Plain::Yes : Plain::No,
            $isDefault ? 'Transfers keep their own nameservers.' : null));
        if ($default && !$isDefault) {
            $new->actions(Sheet::link('default_template', $default->name.' is used', RoutingProfileResource::getUrl('view', ['record' => $default])));
        }
        $trace = Trace::make(Reader::Staff);
        $events = WatchAudit::where('subject_type', 'dns_profile')->where('subject_id', $r->id)->latest('id')->limit(Sheet::RAIL_STEPS)->get();
        foreach ($events as $event) {
            $trace->step($event->event === 'deployment_dns_default' ? 'Deployment template added' : 'Template saved', null, $event->created_at);
        }
        return [Section::make('Used by', [$new,
            Row::make('Monitored domains', Value::fact(Fact::count($usage['assigned']), WatchFacts::usageNote($usage, $r)))
                ->actions(Sheet::link('assigned', 'View domains', RoutingProfileResource::getUrl('assigned', ['record' => $r])))]),
            Section::make('History', $events->isEmpty() ? [] : [$trace], $events->isEmpty() ? 'No history yet.' : null)];
    }
}
