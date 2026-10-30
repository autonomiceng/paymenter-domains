<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Callout,Components\Grid,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{MissingEntry,WatchActions,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchDnsObservation,WatchRegistryObservation};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainName;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewWatch extends ViewRecord
{
    protected static string $resource=WatchResource::class;
    /** The revision this page shows; writes from a stale page are refused. */
    #[Locked] public int $revision=0;
    #[Locked] public array $pendingCheck=[];
    private const FACTS_STYLE='<style>@media (min-width:40rem){.domains-facts .fi-in-entry.fi-in-entry-has-inline-label{grid-template-columns:minmax(0,40%) minmax(0,1fr) minmax(0,1fr)}}</style>';
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
        elseif(now()->timestamp>=$until){Notification::make()->warning()->title('Check is taking longer')->body('Last check has not changed. Ask your site admin.')->persistent()->send();}
        else{return;}
        $this->pendingCheck=[];
    }
    /** Facts and status, read once per request. */
    private function facts(): array
    {
        if(!$this->resolved){$w=$this->getRecord();$row=$w->state==='active'?WatchFindings::summaries([$w])[$w->id]:null;$facts=WatchFacts::for($w,$row);
            $status=WatchFindings::status($w,$row['keys']??[],($row['summary']??null)?->expiry);
            $this->resolved=['facts'=>$facts,'status'=>$status,'notice'=>WatchFacts::notice($w,$status,$facts)];}
        return $this->resolved;
    }
    public function getTitle(): string{return $this->getRecord()->canonical_name;}
    public function getHeading(): HtmlString
    {
        $status=$this->facts()['status'];
        return new HtmlString('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem"><span class="font-mono" style="overflow-wrap:anywhere">'.DomainName::html($this->getTitle())->toHtml().'</span>'
            .(DemoData::is($this->getRecord())?DemoData::tag(inline:true)->toHtml():'')
            .view('filament::components.badge',['color'=>$status['tone'],'size'=>'lg','slot'=>new HtmlString(e($status['label']))])->render().'</span>'.self::FACTS_STYLE);
    }
    public function getSubheading(): ?string{return $this->facts()['status']['reason'];}
    public function getBreadcrumbs(): array{return [WatchResource::getUrl()=>'Domain monitoring',$this->getTitle()];}
    public function getHeader(): ?\Illuminate\Contracts\View\View{return view('domains::staff.watch-header');}
    protected function getHeaderActions(): array{return WatchActions::watch($this->getRecord(),$this->revision);}
    /** Check now in the notice and next to a missing check time; mounted as a page action. */
    public function checkAction(): Action{return WatchActions::check($this->getRecord(),$this->revision);}
    public function infolist(Schema $schema): Schema
    {
        ['facts'=>$facts,'notice'=>$notice]=$this->facts();$components=[];$check=$this->getAction('check',false);
        // Each Missing check time gets its own Check now hint.
        $fix=$check?->isVisible()?fn()=>WatchActions::check($this->getRecord(),$this->revision):null;
        if($notice){$components[]=Callout::make($notice)->status('warning')->columnSpanFull()->footer($check?->isVisible()?new HtmlString($check->toHtml()):null);}
        $section=fn(string $title)=>Section::make($title)->columnSpan(1)->extraAttributes(['class'=>'domains-facts'])
            ->schema(array_map(fn(string $label)=>self::entry($label,$facts[$title][$label],$fix),array_keys($facts[$title])));
        $components[]=Grid::make(['default'=>1,'lg'=>2])->columnSpanFull()->schema([$section('Domain'),$section('Checks')]);
        $components[]=Section::make('History')->columnSpanFull()->schema(self::history($this->getRecord()));
        return $schema->components($components);
    }
    private static function entry(string $label,array $fact,?\Closure $fix): TextEntry
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($fact['value'])->inlineLabel()->listWithLineBreaks()
            ->extraAttributes(['style'=>'overflow-wrap:anywhere'])->belowContent($fact['value']!==null?$fact['sub']:null)->url($fact['url']);
        return MissingEntry::apply($entry,fix:$fact['check'] && $fix?$fix():null,cause:$fact['value']===null?$fact['cause']:null,color:$fact['tone']);
    }
    /** @return list<TextEntry> */
    private static function history(\Paymenter\Extensions\Servers\Domains\Models\WatchName $w): array
    {
        $rows=WatchFacts::history($w);
        if($rows===null){return [TextEntry::make('history')->hiddenLabel()->state(WatchFacts::DECRYPT)->color('danger')];}
        if(!$rows){return [TextEntry::make('history')->hiddenLabel()->state('No history yet')->color('gray')];}
        return array_map(fn(array $r,int $i)=>TextEntry::make('history_'.$i)->label($r['time'])->inlineLabel()->state($r['event'])
            ->belowContent(implode(' · ',array_filter([$r['by'],$r['note']])))->extraAttributes(['style'=>'overflow-wrap:anywhere']),$rows,array_keys($rows));
    }
}
