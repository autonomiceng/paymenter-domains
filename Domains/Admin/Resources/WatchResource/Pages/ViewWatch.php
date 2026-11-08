<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Flex,Components\Section,Components\Text};
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{MissingEntry,Sheet,StatusMark,WatchActions,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchDnsObservation,WatchRegistryObservation};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainName;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewWatch extends ViewRecord
{
    protected static string $resource=WatchResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    protected Width|string|null $maxContentWidth=Sheet::WIDTH;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    #[Locked] public array $pendingCheck=[];
    protected ?array $resolved=null;
    public function mount(int|string $record): void{parent::mount($record);$this->revision=(int)$this->getRecord()->revision;$this->waitForCheck();}
    protected function fillForm(): void{$this->data=[];}
    public function waitForCheck(): void
    {
        $w=$this->getRecord();$at=WatchAudit::where('subject_type','watch')->where('subject_id',$w->id)->where('event','staff_read_requested')->orderByDesc('id')->first(['created_at'])?->created_at?->getTimestamp();
        $this->pendingCheck=[];if($w->state!=='active' || !$at){return;}
        $before=max((int)WatchDnsObservation::where('watch_id',$w->id)->where('completed_at','<',$at)->max('started_at'),
            (int)WatchRegistryObservation::where('watch_id',$w->id)->where('completed_at','<',$at)->max('started_at'));
        if($this->checkTime()<=$before){$this->pendingCheck=[$at+360,$before];}
        if($this->pendingCheck && now()->timestamp>=$this->pendingCheck[0]){$this->pollCheck();}
    }
    private function checkTime(): int{$w=$this->getRecord();return max((int)$w->delegation?->started_at,(int)$w->points?->started_at,(int)$w->registry?->started_at);}
    /** Refresh saved facts only. Keep the mounted revision so stale actions still refuse. */
    public function refreshFacts(): void
    {
        $this->getRecord()->refresh();$this->resolved=null;
    }
    public function pollCheck(): void
    {
        if(!$this->pendingCheck){return;}
        $this->refreshFacts();[$until,$before]=$this->pendingCheck;
        if($this->checkTime()>$before){Notification::make()->success()->title('Check finished')->send();}
        elseif(now()->timestamp>=$until){Notification::make()->warning()->title('Check is taking longer')->body('Last check has not changed. Checks keep retrying. Open Registrars and check the background worker.')->persistent()->send();}
        else{return;}
        $this->pendingCheck=[];
    }
    /** Facts and status, read once per request. */
    private function facts(): array
    {
        if(!$this->resolved){$w=$this->getRecord();$row=$w->state==='active'?WatchFindings::summaries([$w])[$w->id]:null;$facts=WatchFacts::for($w,$row);
            $status=WatchFindings::status($w,$row['keys']??[],($row['summary']??null)?->expiry);
            $this->resolved=['facts'=>$facts,'status'=>$status];}
        return $this->resolved;
    }
    public function getTitle(): string{return $this->getRecord()->canonical_name;}
    public function getHeading(): HtmlString
    {return new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span>'.(DemoData::is($this->getRecord())?DemoData::tag(inline:true)->toHtml():''));}
    public function getSubheading(): string|Htmlable|null
    {$status=$this->facts()['status'];$ended=$this->getRecord()->state==='retired'?WatchFindings::ENDED:null;
        return StatusMark::line($status['tone'],$status['label'],'watch-'.$this->getRecord()->id,$status['reason']??$ended);}
    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $id=['record'=>$this->getRecord()];$history=$this instanceof WatchHistory;
        return [NavigationItem::make('Overview')->url(WatchResource::getUrl('view',$id))->isActiveWhen(fn()=>!$history),
            NavigationItem::make('History')->url(WatchResource::getUrl('history',$id))->isActiveWhen(fn()=>$history)];
    }
    public function getBreadcrumbs(): array{return [WatchResource::getUrl()=>'Domain monitoring',$this->getTitle()];}
    public function getHeader(): ?\Illuminate\Contracts\View\View{return view('domains::staff.watch-header');}
    protected function getHeaderActions(): array{return WatchActions::watch($this->getRecord(),$this->revision);}
    /** Check now next to a missing check time; mounted as a page action. */
    public function checkAction(): Action{return WatchActions::check($this->getRecord(),$this->revision);}
    public function infolist(Schema $schema): Schema
    {
        // The head says the problem and Latest checks shows what was found, so the page has no notice repeating them.
        ['facts'=>$facts]=$this->facts();$sheet=[];$check=$this->getAction('check',false);
        // Each Missing check time gets its own Check now hint.
        $fix=$check?->isVisible()?fn()=>WatchActions::check($this->getRecord(),$this->revision):null;
        foreach(array_keys($facts) as $title){$sheet[]=Sheet::section($title==='Checks'?'Latest checks':$title,array_map(fn(string $label)=>self::entry($label,$facts[$title][$label],$fix),array_keys($facts[$title])));}
        return $schema->components([Sheet::split($sheet,[$this->activity()])]);
    }
    private static function entry(string $label,array $fact,?\Closure $fix): Flex
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($fact['value'])->listWithLineBreaks()->belowContent($fact['value']!==null?$fact['sub']:null)->url($fact['url']);
        // A time reads in the viewer's zone, with UTC on hover.
        if (($fact['at']??null)&&is_string($fact['value'])&&$fact['value']!=='') { $entry->formatStateUsing(fn()=>Sheet::moment((int)$fact['at'])); }
        return Sheet::row(MissingEntry::apply($entry,fix:$fact['check'] && $fix?$fix():null,cause:$fact['value']===null?$fact['cause']:null,color:$fact['tone']));
    }
    /** The rail: the newest history, with the tab holding the rest. */
    private function activity(): Section
    {
        $w=$this->getRecord();$rows=WatchFacts::history($w);
        if($rows===null){return Sheet::section('Activity',[Text::make(WatchFacts::DECRYPT)->color('danger')]);}
        if($rows===[]){return Sheet::section('Activity',[Text::make('No history yet')]);}
        return Sheet::section('Activity',[Sheet::trace(array_map(fn(array $r)=>Sheet::event($r['event'],$r['note'],$r['time'],$r['by']),array_slice($rows,0,Sheet::RAIL_STEPS)))],
            after:[Sheet::link('view_history','View all '.count($rows),WatchResource::getUrl('history',['record'=>$w]))]);
    }
}
