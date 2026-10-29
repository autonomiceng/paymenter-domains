<?php
// Enom 444f31c TLD pricing layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Actions\{Action,ActionGroup};
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Grid,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{MissingEntry,Staff,TldFacts,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\LifecyclePolicies;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewTld extends ViewRecord
{
    protected static string $resource=TldResource::class;
    public string $reviewStamp='';
    /** Page names for the shared settings actions. Edit prices is in More and on the prices section; Edit sits on the grace rows.
     * Default pricing is edited on the registrar account page, and saving prices publishes them. */
    private const LABELS=['seller'=>'Change registrar','override'=>'Edit prices','tld_settings'=>'Edit terms','lifecycle'=>'Edit grace and redemption'];
    public function mount(int|string $record): void
    {parent::mount($record);$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$this->getRecord()->server_id,(int)$this->getRecord()->id);}
    /** @return array<string,Action> */
    private function settings(): array
    {
        $out=[];
        foreach(SettingsActions::make((int)$this->getRecord()->server_id,(int)$this->getRecord()->id,$this->reviewStamp) as $action){
            $label=self::LABELS[$action->getName()]??null;
            $out[$action->getName()]=$label?$action->label($label)->modalHeading($label):$action;
        }
        return $out;
    }
    protected function getHeaderActions(): array
    {
        $actions=$this->settings();
        return [$actions['seller']->color('primary'),ActionGroup::make([$actions['override'],$actions['tld_settings']])->label('More')->button()->color('gray')];
    }
    public function overrideAction(): Action {return $this->settings()['override'];}
    public function lifecycleAction(): Action {return $this->settings()['lifecycle'];}
    public function syncAction(): Action {return SettingsActions::sync()->record($this->getRecord());}
    protected function fillForm(): void {$this->data=[];}
    public function getTitle(): string {Staff::requireView();return '.'.$this->getRecord()->tld;}
    public function getHeading(): HtmlString
    {
        $sales=TldFacts::salesState($this->getRecord());
        return new HtmlString('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem"><span class="font-mono">'.e($this->getTitle()).'</span>'
            .view('filament::components.badge',['color'=>$sales['tone'],'size'=>'lg','slot'=>new HtmlString(e($sales['label']))])->render().'</span>');
    }
    public function getSubheading(): ?string {return TldFacts::salesState($this->getRecord())['reason'];}
    public function getBreadcrumbs(): array {return [$this->getResourceUrl()=>'TLDs & pricing',$this->getTitle()];}
    private function button(?Action $action, string $label): ?HtmlString
    {return $action?->isVisible()?new HtmlString($action->label($label)->link()->size('sm')->toHtml()):null;}
    private static function link(): ?HtmlString
    {return RegistrarSetup::canAccess()?new HtmlString('<a href="'.e(RegistrarSetup::getUrl()).'" class="fi-link fi-size-sm" style="font-size:.875rem;text-decoration:underline">Open Registrars</a>'):null;}
    private static function entry(string $label, ?string $value, string|HtmlString|null $sub = null, ?string $color = null): TextEntry
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($value)->inlineLabel()->extraAttributes(['style'=>'overflow-wrap:anywhere']);
        if($value!==null&&$sub!==null){$entry->belowContent($sub);}
        return MissingEntry::apply($entry,cause:$value===null&&is_string($sub)?$sub:null,color:$color);
    }
    public function infolist(Schema $schema): Schema
    {
        $grants=Staff::requireView();$t=$this->getRecord();$snapshot=TldFacts::snapshot($t,$grants);
        $sellerId=TldFacts::sellerId($t->tld);$seller=$sellerId===null?null:Account::where('server_id',$sellerId)->first();
        $sales=[self::entry('Registrar for new orders',$seller?(string)\App\Models\Server::whereKey($sellerId)->value('name'):null,$seller?'Existing domains stay with their registrar':'No registrar selected')
                ->suffix($seller&&DemoData::is($seller)?DemoData::tag(inline:true):null),
            self::entry('Terms',TldFacts::terms($t))];
        $edit=$this->button($this->getAction('lifecycle',false),'Edit');
        foreach(LifecyclePolicies::facts($t,now()->toDateTimeImmutable(),$grants->costs) as $label=>$fact){
            $entry=self::entry($label,$fact['value'],$fact['sub']);
            if($fact['help']){$entry->hintIcon('heroicon-m-question-mark-circle',$fact['help']);}
            if($fact['edit']&&$edit){$entry->hint($edit);}
            $sales[]=$entry;
        }
        // Sales keep the last synced cost; old prices get a quiet warning, never a stop.
        $synced=TldFacts::lastSynced($snapshot['prices']);$failure=TldFacts::syncFailure((int)$t->server_id);
        $old=$synced!==null&&$synced->lt(now()->subDays(TldFacts::SYNC_WARN_DAYS));
        $rule='Sales use the last synced cost. They stop only if there is no cost or the price is below cost.';
        $sync=[];
        if($failure){
            $cause=e($failure['cause'].($failure['at']?' · '.$failure['at']:''));$link=self::link();
            $sync[]=self::entry('Last sync','Sync failed',new HtmlString($cause.($link?'<div>'.$link->toHtml().'</div>':'')),'danger');
        }
        $sync[]=self::entry('Prices last synced',$synced?->format('M j, Y'),$synced===null?'Not synced yet':new HtmlString(
            ($old?'<div style="color:rgb(217 119 6)">More than '.TldFacts::SYNC_WARN_DAYS.' days ago. Sync prices to get current costs.</div>':'').'<div>'.e($rule).'</div>'),$old?'warning':null);
        $syncAction=$this->getAction('sync',false);
        $override=$this->getAction('override',false);
        return $schema->components([
            Grid::make(['default'=>1,'lg'=>2])->columnSpanFull()->schema([
                Section::make('Sales')->schema($sales)->columnSpan(1),
                Section::make('Price sync')->afterHeader($syncAction?->isVisible()?new HtmlString((clone $syncAction)->button()->color('gray')->toHtml()):null)->schema($sync)->columnSpan(1)]),
            // A copy renders as a button here; the menu keeps its own item view.
            Section::make('Prices by registrar account')->afterHeader($override?->isVisible()?new HtmlString((clone $override)->button()->color('gray')->toHtml()):null)
                ->schema([TextEntry::make('prices')->label('Prices by registrar account')->hiddenLabel()->state(fn()=>TldFacts::accountsTable($t,$grants))->html()])->columnSpanFull(),
            Section::make('History')
                ->schema([TextEntry::make('history')->label('History')->hiddenLabel()->state(fn()=>TldFacts::historyTable(TldFacts::history((int)$t->server_id,(int)$t->id)))->html()])->columnSpanFull(),
        ]);
    }
}
