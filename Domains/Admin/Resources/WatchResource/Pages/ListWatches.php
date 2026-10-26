<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\{Callout,EmbeddedTable,RenderHook};
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchFindings;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\{WatchMaintenance,WatchWork};
class ListWatches extends ListRecords
{
    protected static string $resource=WatchResource::class;
    /** Request-scoped projection of the visible page; protected, so never part of the Livewire snapshot. */
    protected ?array $visible=null;
    protected array $status=[];
    /** Overflow of the current expiry scan; protected, so never part of the Livewire snapshot. */
    protected ?bool $expiryCapped=null;
    public function getTitle(): string{return 'Domain monitoring';}
    public function getBreadcrumbs(): array{return [];}
    public function getSubheading(): ?string{return null;}
    public function getSubNavigation(): array{return WatchResource::tabs('index');}
    /** Request-scoped maintenance notice; false until read. */
    protected array|false|null $notice=false;
    private function notice(): ?array{if($this->notice===false){$this->notice=WatchMaintenance::notice(app(WatchWork::class)->status());}return $this->notice;}
    /** Monitoring off (the only warning notice) with nothing monitored: the empty state says so instead of a notice. */
    private function offAndEmpty(): bool{return ($this->notice()['tone']??null)==='warning'&&!WatchName::query()->exists();}
    public function table(Table $table): Table
    {
        $table=parent::table($table);
        return $this->offAndEmpty()?$table->emptyStateHeading($this->notice()['text']):$table;
    }
    public function content(Schema $schema): Schema
    {
        $notice=$this->notice();
        if(!$notice||$this->offAndEmpty()){return parent::content($schema);}
        $url=match($notice['action']){'settings'=>WatchResource::getUrl('settings'),'failed_jobs'=>self::failedJobs(),default=>null};
        $button=$url?view('filament::components.button.index',['tag'=>'a','href'=>$url,'color'=>'gray','size'=>'sm','slot'=>$notice['action']==='settings'?'Open Settings':'Open Failed jobs'])->render():null;
        return $schema->components([Callout::make($notice['text'])->status($notice['tone'])->footer($button?new HtmlString($button):null)->columnSpanFull(),
            $this->getTabsContentComponent(),RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER)]);
    }
    /** Paymenter's Failed jobs page, when this panel has it and the staff member may open it. */
    private static function failedJobs(): ?string
    {
        $resource='App\\Admin\\Resources\\FailedJobResource';
        try{return class_exists($resource)&&$resource::canAccess()?$resource::getUrl('index'):null;}catch(\Throwable){return null;}
    }
    /** @return list<int> */
    public function statusIds(string $status): array{return $this->status[$status]??=WatchFindings::statusIds($status);}
    public function recordExpiryScan(bool $capped): void{$this->expiryCapped=$capped;}
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
