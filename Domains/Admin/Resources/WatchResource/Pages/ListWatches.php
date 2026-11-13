<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{PublicSuffixList,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DefaultNameservers,Sheet,Staff,StaffDomainStatus,StaffTabs,WatchFacts,WatchFindings,WatchQueries,WatchRows};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Trace,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column,Filter,RecordListPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Presentation\{Missing,Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites,WatchMaintenance,WatchWork,ManagedDnsSetup};
/** Domains > Monitored domains, a List page: every monitored name with its status, note and latest facts. */
class ListWatches extends RecordListPage
{
    protected static string $resource=WatchResource::class;
    /** Request-scoped projection of the visible page; protected, so never part of the Livewire snapshot. */
    protected ?array $visible=null;
    protected array $status=[];
    /** Overflow of the current expiry scan; protected, so never part of the Livewire snapshot. */
    protected ?bool $expiryCapped=null;
    /** Request-scoped scans keyed by interval and incoming query, including filter bindings. */
    protected array $expiryScans=[];
    /** Request-scoped maintenance notice; false until read. */
    protected array|false|null $notice=false;
    protected array $failedFamilies=[];
    public function getBreadcrumbs(): array{return [];}
    public function getSubNavigation(): array{return StaffTabs::domains('Monitored domains');}
    protected function head(): Head{return Head::make('Domains',Reader::Staff);}
    private function notice(): ?array{if($this->notice===false){$health=app(WatchWork::class)->status();$this->failedFamilies=$health['families']??[];$this->notice=WatchMaintenance::notice($health);}return $this->notice;}
    /** A DNS failure that reached managed DNS lists its affected checks; the notice keeps the resolver link. */
    protected function actions(): array
    {
        return [Action::make('affected')->label('Affected checks')->color('gray')->url(fn()=>WatchResource::getUrl('dns-failures'))
            ->visible(fn()=>$this->notice()!==null&&in_array('managed_dns',$this->failedFamilies,true))];
    }
    protected function notices(): array
    {
        $staff=Reader::Staff;$sections=[];
        $count=ManagedDnsSetup::affected(time())->count();
        if($count){
            $canAssign=Staff::can(StaffWatchWrites::MANAGE)&&Staff::canEditDefaultDns();
            $text='DNS monitoring is not set up for '.$count.' '.($count===1?'domain.':'domains.').($canAssign?' Set the default nameservers in Domains > DNS templates.':'');
            $sections[]=Section::make('DNS monitoring',[Row::notice('dns-setup',Value::state(State::NotSet,$staff,$text),$canAssign?Sheet::link('default_nameservers',DefaultNameservers::TITLE,RoutingProfileResource::getUrl()):null)]);
        }
        $notice=$this->notice();
        if($notice){
            // A failed check broke something; monitoring that was never set up waits on staff.
            $state=$notice['tone']==='danger'?State::Failed:State::NotSet;
            $settings=($notice['action']??null)==='settings'&&MonitoringSettings::canAccess()?Sheet::link('resolver_settings','Resolver settings',WatchResource::getUrl('settings')):null;
            $sections[]=Section::make('Monitoring',[Row::notice('maintenance',Value::state($state,$staff,$notice['text']),$settings)]);
            if(($notice['action']??null)==='setup'&&Staff::can(StaffWatchWrites::MANAGE)){$sections[]=$this->setupSteps();}
        }
        return $sections;
    }
    /** The steps to connect monitoring, in order. They have no state of their own, so they carry no mark. */
    private function setupSteps(): Section
    {
        // The DNS templates tab sits beside this list; the suffix list page does not, so the one link goes there.
        $page=\App\Models\Server::where('extension','Domains')->exists()?RegistrarSetup::class:PublicSuffixList::class;
        $psl=$page===RegistrarSetup::class?'Registrars > System checks':'Public suffix list';
        $open=\Illuminate\Support\Facades\Route::has($page::getRouteName())&&$page::canAccess();
        $steps=Section::make('Set up domain monitoring',[Trace::make(Reader::Staff)
            ->step('Ask your site admin to connect monitoring on the server',null,reason:'Changing the resolver in Settings does not turn monitoring on.')
            ->step('The public suffix list downloads automatically',null,reason:($open?'Failed updates appear under ':'A staff member with domain monitoring access can check failed updates under ').$psl.'.')
            ->step('Add expected nameservers and records in DNS templates',null,reason:'Do this if you want DNS checks. Expiry checks run without one.')
            ->step('Hosting products with a domain field are monitored by themselves',null,reason:'Once monitoring is connected, the Monitoring settings tab lists each one with an on/off switch.')
            ->step('Check that an active hosting service shows up here and finishes a check',null,reason:'Checks run every five minutes. Monitoring reads public DNS and registry data. It doesn\'t buy or register domains.')]);
        return $open?$steps->link(Sheet::link('setup_suffix_list',$psl,$page::getUrl())):$steps;
    }
    protected function columns(): array
    {
        $staff=Reader::Staff;
        return [
            Column::domain('canonical_name','Domain')->searchable()->sortable(),
            Column::text('customer','Customer',fn(WatchName $w)=>$this->watchRow($w)['customer']['label'])->short(),
            Column::value('status','Status',function(WatchName $w)use($staff){$status=$this->watchRow($w)['status'];return Value::state(WatchRows::state($status['label']),$staff,null,'watch-'.$w->id);}),
            Column::text('note','Note',fn(WatchName $w)=>$this->note($w)),
            Column::value('registration','Registrar',fn(WatchName $w)=>$this->fact($w,['value'=>WatchFindings::who($w->registration),'cause'=>WatchFacts::missingCause($w,'expiry')]))->from('xl')->optional(),
            Column::value('delegation.dns','Nameservers',fn(WatchName $w)=>$this->fact($w,['value'=>WatchFindings::who($w->delegation?->dns),'cause'=>WatchFacts::missingCause($w,'delegation')]))->from('lg')->optional(),
            Column::value('last_check','Last check',fn(WatchName $w)=>$this->fact($w,[...$this->lastCheck($w),'sub'=>null]))->from('md'),
            Column::value('expiry','Expires',fn(WatchName $w)=>$this->fact($w,[...$this->expires($w),'sub'=>null]))->from('lg'),
        ];
    }
    protected function filters(): array
    {
        $words=['attention'=>WatchFindings::STATUSES['attention'],...array_map(static fn(string $label): string=>WatchRows::state($label)->label(),array_diff_key(WatchFindings::STATUSES,['attention'=>true]))];
        return [
            Filter::select('status','Status',$words)->query(fn(Builder $query,array $data)=>WatchFindings::filter($query,$data['value']??null,$this))
                ->indicator(fn(array $data)=>isset($words[$data['value']??''])?(in_array($data['value'],WatchFindings::READ_STATUSES,true)?self::capped('Status: '.$words[$data['value']]):'Status: '.$words[$data['value']]):null),
            Filter::text('customer','Customer',fn(Builder $query,?string $customer)=>WatchFindings::customer($query,$customer)),
            Filter::select('expiry','Expires within',['7'=>'7 days','30'=>'30 days','90'=>'90 days'])->query(fn(Builder $query,array $data)=>$this->expiryQuery($query,(int)($data['value']??0)))
                ->indicator(fn(array $data)=>in_array($data['value']??null,['7','30','90'],true)?'Expires within '.$data['value'].' days'.($this->expiryScanCapped()?' (first 1,000 domains)':''):null),
        ];
    }
    protected function defaultSort(): ?array{return ['canonical_name','asc'];}
    protected function searchLabel(): ?string{return 'Search domain';}
    protected function emptyLine(): array{return ['No monitored domains','Turn on monitoring on a hosting product.','No monitored domains match'];}
    /** A value not saved yet reads Not read yet with its cause. While monitoring is paused or stopped no check is expected:
     * Status says so once and these cells stay empty. */
    private function fact(WatchName $w,array $fact): ?Value
    {
        if($w->state!=='active'&&Missing::is($fact['value']??null)){return null;}
        return WatchRows::value(['value'=>Missing::is($fact['value']??null)?null:$fact['value'],'sub'=>$fact['sub']??null,'tone'=>$fact['tone']??null,'cause'=>$fact['cause']??null,'at'=>$fact['at']??null,'url'=>null,'check'=>false]);
    }
    /** Last check and Expires, each without its sub-line: those go in Note. */
    private function lastCheck(WatchName $w): array{return WatchFacts::lastCheck($w,$this->watchRow($w)['summary']);}
    private function expires(WatchName $w): array
    { $row=$this->watchRow($w);return in_array('watch.no_coverage',$row['keys'],true)?['value'=>null]:WatchFacts::expires($row['summary'],$row['keys'],$w->state==='active',$w); }
    /** The status reason first, then what the last check and the expiry date add. Each says once. */
    private function note(WatchName $w): ?string
    {
        $parts=array_values(array_unique(array_filter([$this->watchRow($w)['status']['reason'],$this->lastCheck($w)['sub']??null,$this->expires($w)['sub']??null])));
        return match(count($parts)){0=>null,1=>$parts[0],default=>implode(' ',array_map(StaffDomainStatus::sentence(...),$parts))};
    }
    /** Status reads at most CAP active names; the indicator says so only when more exist. */
    private static function capped(string $label): string{return WatchName::where('state','active')->count()>WatchFindings::CAP?$label.' (first 1,000 active domains)':$label;}
    /** @return list<int> */
    public function statusIds(string $status): array{return $this->status[$status]??=WatchFindings::statusIds($status);}
    public function expiryQuery(Builder $query,int $days): Builder
    {
        abort_unless(Staff::can(StaffWatchWrites::VIEW),403);
        if(!in_array($days,[7,30,90],true)){return $query;}
        $key=hash('sha256',serialize([$days,$query->toSql(),$query->getBindings()]));
        $scan=$this->expiryScans[$key]??=WatchQueries::expiryScan($query,$days);
        $this->expiryCapped=$scan['capped'];
        return $query->whereIn('id',$scan['ids']);
    }
    /** Indicators can render before records; applying the filters records the scan without reading indicators. */
    public function expiryScanCapped(): bool
    {
        if($this->expiryCapped===null){$this->getFilteredTableQuery();}
        return $this->expiryCapped??false;
    }
    public function watchRow(WatchName $record): array
    {
        $records=$this->getTableRecords();
        if(($this->visible['records']??null)!==$records){$rows=collect(method_exists($records,'items')?$records->items():$records);
            $this->visible=['records'=>$records,'summaries'=>WatchFindings::summaries($rows),'customers'=>WatchFindings::customers($rows->pluck('user_id')->unique()->all())];}
        $row=$this->visible['summaries'][$record->id]??WatchFindings::summaries([$record])[$record->id];
        return $row+['customer'=>$this->visible['customers'][$record->user_id]??WatchFindings::customers([$record->user_id])[$record->user_id],'status'=>WatchFindings::status($record,$row['keys'],$row['summary']?->expiry,summary:$row['summary'])];
    }
}
