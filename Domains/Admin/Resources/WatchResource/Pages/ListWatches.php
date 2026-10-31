<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\{Callout,EmbeddedTable,RenderHook,View};
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchActions,WatchFindings,WatchQueries};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\{DnsResolverSettings,StaffWatchWrites,WatchMaintenance,WatchWork};
class ListWatches extends ListRecords
{
    protected static string $resource=WatchResource::class;
    /** Request-scoped projection of the visible page; protected, so never part of the Livewire snapshot. */
    protected ?array $visible=null;
    protected array $status=[];
    /** Overflow of the current expiry scan; protected, so never part of the Livewire snapshot. */
    protected ?bool $expiryCapped=null;
    /** Request-scoped scans keyed by interval and incoming query, including filter bindings. */
    protected array $expiryScans=[];
    public function getTitle(): string{return 'Domain monitoring';}
    public function getBreadcrumbs(): array{return [];}
    public function getSubheading(): ?string{return null;}
    public function getSubNavigation(): array{return WatchResource::tabs('index');}
    /** Request-scoped maintenance notice; false until read. */
    protected array|false|null $notice=false;
    private function notice(): ?array{if($this->notice===false){$this->notice=WatchMaintenance::notice(app(WatchWork::class)->status());}return $this->notice;}
    public function content(Schema $schema): Schema
    {
        $notice=$this->notice();
        $url=($notice['action']??null)==='settings'&&MonitoringSettings::canAccess()&&Staff::can(StaffWatchWrites::MANAGE)&&!app(DnsResolverSettings::class)->configuration()[2]?WatchResource::getUrl('settings'):null;
        $button=$url?view('filament::components.button.index',['tag'=>'a','href'=>$url,'color'=>'gray','size'=>'sm','slot'=>'Open Settings'])->render():null;
        return $schema->components([...($notice?[Callout::make($notice['text'])->status($notice['tone'])->footer($button?new HtmlString($button):null)->columnSpanFull()]:[]),
            ...(($notice['action']??null)==='setup'&&Staff::can(StaffWatchWrites::MANAGE)?[View::make('domains::admin.watch-setup')]:[]),
            ...(app(WatchWork::class)->active()?[View::make('domains::admin.watch-products')]:[]),
            $this->getTabsContentComponent(),RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER)]);
    }
    /** Request-scoped, so a long product list reads permission once; every write checks it again. */
    protected ?bool $canManage=null;
    public function canManageProducts(): bool{return $this->canManage??=Staff::can(StaffWatchWrites::VIEW)&&Staff::can(StaffWatchWrites::MANAGE);}
    public function productSwitchAction(): Action{return WatchActions::productSwitch();}
    public function chooseFieldAction(): Action{return WatchActions::chooseField();}
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
        return $row+['customer'=>$this->visible['customers'][$record->user_id]??WatchFindings::customers([$record->user_id])[$record->user_id],'status'=>WatchFindings::status($record,$row['keys'],$row['summary']?->expiry)];
    }
}
