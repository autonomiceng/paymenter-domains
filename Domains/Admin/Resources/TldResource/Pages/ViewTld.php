<?php
// Enom 444f31c TLD pricing layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use Filament\Actions\{Action,ActionGroup};
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Flex,Components\Html,Components\Section,Components\Text};
use Filament\Support\Enums\Width;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,MissingEntry,RegistrarFacts,Sheet,Staff,StatusMark,TldFacts,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewTld extends ViewRecord
{
    protected static string $resource=TldResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    protected Width|string|null $maxContentWidth=Sheet::WIDTH;
    public string $reviewStamp='';
    /** Page names for the shared settings actions. Default pricing is edited in What you sell, and saving prices publishes them. */
    private const LABELS=['seller'=>'Change registrar','override'=>'Edit prices'];
    public const CHOOSE_SELLER='Use More → Change registrar.';
    public const CHOOSE_SELLER_HEAD='Use Choose registrar above.';
    public function mount(int|string $record): void
    {parent::mount($record);$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$this->getRecord()->server_id,(int)$this->getRecord()->id);}
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        // Writers load their own models. Rebuild the page so the next change needs no reload.
        $t=$this->getRecord()->refresh();$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$t->server_id,(int)$t->id);
        unset($this->cachedSchemas['infolist']);$this->sales=null;$this->start=null;$this->cachedActions=[];$this->cachedHeaderActions=[];
        $this->cacheInteractsWithHeaderActions();$this->forceRender();
    }
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
    /** The head: the sales state once, and the one action that makes the TLD sellable as the primary button. */
    protected function getHeaderActions(): array
    {
        $actions=$this->settings();$fix=$this->headFix();
        $seller=$fix==='seller'?$actions['seller']->label($this->sales()['reason']==='No registrar selected'?'Choose registrar':'Change registrar')->color('primary'):null;
        // Every sales action is always registered; visibility picks the one that shows.
        return array_values(array_filter([$this->startSellingAction()->color($fix===null?'primary':'gray'),$seller,$actions['override']->color($fix==='override'?'primary':'gray'),
            ActionGroup::make(array_values(array_filter([$seller?null:$actions['seller'],$this->stopSellingAction()])))->label('More')->button()->color('gray')]));
    }
    /** @return array{label:string,tone:string,reason:?string,invalid:bool,kind:?string} */
    private function sales(): array
    {
        if(!isset($this->sales)){$t=$this->getRecord();$snapshot=TldFacts::snapshot($t);$this->sales=[...$snapshot['sales'],'invalid'=>(bool)($snapshot['invalid_rule']??false),'kind'=>$snapshot['invalid_rule_kind']??null];}
        return $this->sales;
    }
    private ?array $sales=null;
    /** The fix that holds the primary spot: none while Start selling lifts a staff stop. */
    private function headFix(): ?string {return $this->staffStop()?null:$this->fix();}
    /** Which head button fixes the reason a TLD is not for sale, when it is on this page. */
    private function fix(): ?string
    {
        $s=$this->sales();
        if($s['label']==='For sale'){return null;}
        // Edit prices can't repair the account's defaults; the reason names where to.
        if($s['invalid']&&$s['kind']==='account_defaults'){return null;}
        if($s['invalid']||in_array($s['reason'],['Below cost','Price missing','Prices not published'],true)){return 'override';}
        return in_array($s['reason'],['No registrar selected','Not offered by this registrar'],true)||TldFacts::unsupported($s['reason'])?'seller':null;
    }
    /** Staff words for the head. Off on purpose is gray, a job for staff is amber, our own checks are blue. */
    private function headLine(): HtmlString
    {
        $t=$this->getRecord();$s=$this->sales();$reason=$s['reason'];$step=null;
        if($s['label']==='For sale'){return StatusMark::line('success','For sale','tld-'.$t->id);}
        // The TLD list reads the same reason, so both say one state.
        if($this->staffStop()){return StatusMark::line('gray','Not for sale','tld-'.$t->id,$reason);}
        $tone='gray';
        $link=static fn(?string $url,string $label)=>$url?new HtmlString(e($reason).'. <a class="domains-link" href="'.e($url).'">'.e($label).'</a>'):$reason;
        $step=match($reason){
            'Cost missing','Cost out of date'=>$reason.'. Use Sync prices below.',
            'Check registrar account',TldFacts::RENEWALS_OFF=>$link(RegistrarFacts::accountUrl((int)$t->server_id),'Open the registrar account'),
            'Over the cost limit'=>$link(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::getUrl(['account'=>$t->server_id]),'Change it in What you sell'),
            'No registrar selected'=>'No registrar selected. '.($this->settings()['seller']->isVisible()?'Choose a registrar for new orders.':'Ask staff who can change the registrar.'),
            TldFacts::TERMS_FAILED=>$reason.'. Sync prices tries again now.',
            default=>str_starts_with($reason,TldFacts::OTHER_SELLER.' ')?$reason.'. This account keeps its existing domains.':$reason};
        return StatusMark::line($tone,'Not for sale','tld-'.$t->id,$step);
    }
    /** Sales reasons for a stop that Start selling lifts. */
    private const STAFF_STOPS=['Stopped by staff','Not sold by default'];
    /** Not for sale because of a stop Start selling lifts: staff stopped it or its renewals, or it isn't sold by
     * default. */
    private function staffStop(): bool
    {return in_array($this->sales()['reason'],self::STAFF_STOPS,true)&&$this->canStart();}
    private function mayChangeSales(): bool
    {return Staff::can(Staff::POLICY)&&Staff::can(Review::permission('tld_settings'));}
    /** A stop that Start selling can lift. */
    private function canStart(): bool {return $this->mayChangeSales()&&($this->start()['can']??false);}
    /** What Start selling saves (StaffCatalogue::start), and whether something it lifts is stopped. Read once per
     * saved sales state, so a refreshed record reads again. An unreadable TLD can't start. */
    private function start(): array
    {
        $t=$this->getRecord();$key=md5((string)$t->catalogue_intent.'|'.(string)$t->catalogue_facts);
        if(($this->start[0]??null)!==$key){
            try{
                $catalogue=StaffCatalogue::routed()??throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE);
                $start=$catalogue->start($t);$on=static fn(string $key):bool=>(bool)$catalogue->setting($t,$key);
                $stopped=!$on('enabled')||!$on('renew')||!$on('register')||$start['transfers']&&!$on('transfer');
                $start['can']=$stopped&&$start['block']===null;
            }catch(\Throwable){$start=['can'=>false,'transfers'=>false,'checking'=>false];}
            $this->start=[$key,$start];
        }
        return $this->start[1];
    }
    private ?array $start=null;
    /** Starts new registrations, and transfers when the TLD sells them. A deliberate stop is never undone by price or
     * term changes. The TLD's own renewal stop clears too, since a TLD can't be sold without renewals. Account renewal
     * settings stay. When the account sells only TLDs set to Sell, this sets the TLD to Sell. */
    public function startSellingAction(): Action
    {
        $t=$this->getRecord();$server=(int)$t->server_id;$id=(int)$t->id;
        return Action::make('start_selling')->label('Start selling')->color('primary')->requiresConfirmation()->icon(null)->modalIcon('heroicon-o-play-circle')
            ->modalHeading('Start selling .'.$t->tld.'?')->modalSubmitActionLabel('Start selling')
            ->modalDescription(fn()=>'New '.($this->opensTransfers()?'registrations and transfers':'registrations')
                .' are set to sell once all availability checks pass, including registrar terms.'.($this->salePrices()?' Current prices are listed below.':''))
            ->modalContent(fn()=>$this->salePricesList())
            // A saved price account is not a registrar choice. Staff must choose where new orders go.
            ->disabled(fn():bool=>$this->sales()['reason']==='No registrar selected')
            ->extraAttributes(fn():array=>$this->sales()['reason']==='No registrar selected'?['aria-disabled'=>'true']:[])
            ->visible(fn():bool=>$this->canStart()||$this->mayChangeSales()&&$this->sales()['reason']==='No registrar selected')
            ->schema([Hidden::make('review_key')->default(fn()=>Review::ticket((int)auth()->id(),$server,$id,'tld_settings',$this->reviewStamp))])
            ->action(function(array $data)use($server,$id):void {
                $input=[];
                try{
                    $t=$this->getRecord()->fresh();
                    if((TldFacts::snapshot($t)['seller']??null)===null){Notification::make()->warning()->title('Choose a registrar for new orders.')->send();return;}
                    $start=(StaffCatalogue::routed()??throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE))->start($t);
                    if($start['block']!==null){throw new \DomainException('Explicit verified widening required.');}
                    $input=['min_years'=>1,'max_years'=>$start['years'],'sales'=>'start','verified'=>true];
                    app(StaffSettingsWrites::class)->run(new Review((int)auth()->id(),$server,$id,'tld_settings',(string)($data['review_key']??''),'Started selling from the TLD page.'),$input);
                    Notification::make()->success()->title('Change recorded')->body($this->keptTransferStop())->send();
                }catch(\Throwable $error){Notification::make()->danger()->title('Change refused')->body(SettingsActions::refusal($error,'tld_settings',$input,$server,$id))->send();}
            });
    }
    /** Start selling keeps a separate transfer stop. Say so, and name who can allow transfers in Change registrar. */
    private function keptTransferStop(): ?string
    {
        try{$stops=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($this->getRecord()->fresh()->catalogue_intent)['stops']??[];}
        catch(\Throwable){return null;}
        if(!is_array($stops)||!in_array('transfer',$stops,true)){return null;}
        return 'Transfers stay off. '.($this->settings()['seller']->isVisible()?'Allow them in More → Change registrar.':'Ask staff who can change the registrar.');
    }
    /** Retail prices that go live, one year each. */
    private function salePrices(): array
    {
        $prices=collect(TldFacts::snapshot($this->getRecord())['prices']??[]);
        $price=static fn(string $action)=>$prices->first(fn($p)=>$p['action']===$action&&$p['term']===1)['retail']??null;
        return array_filter(['Register'=>($r=$price('register'))?$r.' a year':null,'Transfer'=>$this->opensTransfers()?$price('transfer'):null,'Renew'=>($r=$price('renew'))?$r.' a year':null]);
    }
    private function opensTransfers(): bool {return (bool)$this->start()['transfers'];}
    private function salePricesList(): ?HtmlString
    {
        $rows=$this->salePrices();
        return $rows?new HtmlString('<dl class="domains-sale-prices">'.implode('',array_map(fn($k,$v)=>'<div><dt>'.e($k).'</dt><dd>'.e($v).'</dd></div>',array_keys($rows),$rows)).'</dl>'):null;
    }
    /** Stops new registrations and transfers. Domains customers already have keep renewing. Only the acquisition pause changes,
     * so the TLD's transfer and term choices wait for Start selling. */
    public function stopSellingAction(): Action
    {
        $t=$this->getRecord();$server=(int)$t->server_id;$id=(int)$t->id;
        return Action::make('stop_selling')->label('Stop selling')->color('danger')->requiresConfirmation()->modalIcon('heroicon-o-stop-circle')
            ->modalHeading('Stop selling .'.$t->tld.'?')->modalSubmitActionLabel('Stop selling')
            ->modalDescription('New registrations and transfers stop at once. Existing .'.$t->tld.' domains keep renewing.')
            ->visible(function()use($t):bool {
                if(!$this->mayChangeSales()||($catalogue=StaffCatalogue::routed())===null){return false;}
                return $catalogue->setting($t,'enabled')&&($catalogue->setting($t,'register')||$catalogue->setting($t,'transfer'));
            })
            ->schema([Hidden::make('review_key')->default(fn()=>Review::ticket((int)auth()->id(),$server,$id,'tld_settings',$this->reviewStamp))])
            ->action(function(array $data)use($server,$id):void {
                $input=[];
                try{
                    $input=['min_years'=>1,'sales'=>'stop','verified'=>true];
                    app(StaffSettingsWrites::class)->run(new Review((int)auth()->id(),$server,$id,'tld_settings',(string)($data['review_key']??''),'Stopped selling from the TLD page.'),$input);
                    Notification::make()->success()->title('Change recorded')->send();
                }catch(\Throwable $error){Notification::make()->danger()->title('Change refused')->body(SettingsActions::refusal($error,'tld_settings',$input,$server,$id))->send();}
            });
    }
    public function overrideAction(): Action {return $this->settings()['override'];}
    public function syncPricesAction(): Action {return SettingsActions::syncAll((int)$this->getRecord()->server_id,tld:(string)$this->getRecord()->tld)->name('syncPrices');}
    protected function fillForm(): void {$this->data=[];}
    public function getTitle(): string {Staff::requireView();return '.'.$this->getRecord()->tld.(($a=$this->account())!==null?' on '.$a:'');}
    public function getHeading(): HtmlString
    {
        $a=$this->account();
        return new HtmlString('<span class="font-mono domains-mono">.'.e($this->getRecord()->tld).'</span>'.($a!==null?' <span class="domains-tld-account">on '.e($a).'</span>':''));
    }
    private ?string $accountName=null;private bool $accountRead=false;
    /** A TLD has a page on each registrar account that offers it. Then the page names its account, so two pages never
     * share one title. */
    private function account(): ?string
    {
        if($this->accountRead){return $this->accountName;}
        $t=$this->getRecord();$this->accountRead=true;
        $shared=\Paymenter\Extensions\Servers\Domains\Models\Tld::where('tld',$t->tld)->where('server_id','!=',$t->server_id)->exists();
        return $this->accountName=$shared?((string)(\App\Models\Server::whereKey($t->server_id)->value('name')?:'') ?: null):null;
    }
    public function getSubheading(): string|Htmlable|null
    {return $this->headLine();}
    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $id=['record'=>$this->getRecord()];$history=$this instanceof TldHistory;
        return [NavigationItem::make('Overview')->url(TldResource::getUrl('view',$id))->isActiveWhen(fn()=>!$history),
            NavigationItem::make('History')->url(TldResource::getUrl('history',$id))->isActiveWhen(fn()=>$history)];
    }
    public function getBreadcrumbs(): array {return [$this->getResourceUrl()=>'TLDs & pricing',$this->getTitle()];}
    private function button(?Action $action, string $label): ?HtmlString
    {return $action?->isVisible()?new HtmlString($action->label($label)->link()->size('sm')->toHtml()):null;}
    private static function entry(string $label, ?string $value, string|HtmlString|null $sub = null, ?string $color = null, ?string $url = null, ?int $at = null, ?HtmlString $suffix = null, array|Html $side = [], ?string $missingTone = null): Flex
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($value)->url($url)->suffix($suffix);
        if($value!==null&&$sub!==null){$entry->belowContent($sub);}
        if($at!==null){$entry->formatStateUsing(fn()=>Sheet::moment($at));}
        if($value===null&&$missingTone!==null){
            $entry->default(\Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL)->badge()->color($missingTone)->extraAttributes(StatusMark::attributes($missingTone,'tld-missing-'.md5($label)))->belowContent($sub);
        }else{$entry=MissingEntry::apply($entry,cause:$value===null&&is_string($sub)?$sub:null,color:$color);}
        return Sheet::row($entry,$side,key:'fact_'.md5($label));
    }
    /** The rail: the newest changes, with the tab holding the rest. */
    private function activity(): Section
    {
        $t=$this->getRecord();$rows=TldFacts::history((int)$t->server_id,(int)$t->id);
        if($rows===null){return Sheet::section('Activity',[Text::make(DomainFacts::UNREADABLE)]);}
        if($rows===[]){return Sheet::section('Activity',[Text::make('No changes yet')]);}
        return Sheet::section('Activity',[Sheet::trace(array_map(fn(array $r)=>Sheet::event($r['change'],$r['reason'],$r['time'],$r['by']),array_slice($rows,0,Sheet::RAIL_STEPS)))],
            after:[Sheet::link('view_history','View all '.count($rows),TldResource::getUrl('history',['record'=>$t]))]);
    }
    public function infolist(Schema $schema): Schema
    {
        $grants=Staff::requireView();$t=$this->getRecord();$snapshot=TldFacts::snapshot($t,$grants);
        $sellerId=TldFacts::sellerId($t->tld);$seller=$sellerId===null?null:Account::where('server_id',$sellerId)->first();
        // The head says why the TLD is not for sale; a missing registrar row says only what to do.
        $sales=[self::entry('Registrar for new orders',$seller?(string)\App\Models\Server::whereKey($sellerId)->value('name'):null,$seller?'Existing domains stay with their registrar':($this->settings()['seller']->isVisible()?($this->headFix()==='seller'?self::CHOOSE_SELLER_HEAD:self::CHOOSE_SELLER):'Ask staff who can change the registrar.'),color:'primary',url:RegistrarFacts::accountUrl($sellerId),suffix:$seller&&DemoData::is($seller)?DemoData::tag(inline:true):null,missingTone:'warning'),
            self::entry('Terms',$snapshot['unreadable']?null:TldFacts::terms($t),$snapshot['unreadable']?'Saved TLD terms unreadable. Use Edit prices to repair them.':null)];
        $refresh=$snapshot['refresh']??[];
        // One row: when prices last updated, and when they update next. Sync prices sits beside it.
        $sync=[self::entry('Last updated',isset($refresh['succeeded_at'])?'set':'Not yet',
            isset($refresh['due_at'])?Sheet::moment((int)$refresh['due_at'],'Next update'):'No update scheduled. Check Site setup on the Registrar account page, and the background worker.',
            at:isset($refresh['succeeded_at'])?(int)$refresh['succeeded_at']:null,side:[$this->syncPricesAction()->label('Sync prices')])];
        if($failure=TldFacts::syncFailure((int)$t->server_id)){
            $cause=$failure['cause'];
            if(isset($failure['timestamp'])){$cause=new HtmlString(e($cause).' '.Sheet::moment((int)$failure['timestamp'])->toHtml());}
            $sync[]=self::entry('Last refresh failure','Refresh failed',$cause,'danger');
        }
        return $schema->components([
            Sheet::split([Sheet::section('Sales',$sales),Sheet::section('Price sync',$sync)],[$this->activity()]),
            // A copy renders as a button here; the menu keeps its own item view.
            Section::make('Prices by registrar account')->extraAttributes(['class'=>'domains-surface'])
                ->schema([TextEntry::make('prices')->label('Prices by registrar account')->hiddenLabel()->state(fn()=>TldFacts::accountsTable($t,$grants))->html()])->columnSpanFull(),
        ]);
    }
}
