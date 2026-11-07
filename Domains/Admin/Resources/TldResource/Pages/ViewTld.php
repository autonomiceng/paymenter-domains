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
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
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
        unset($this->cachedSchemas['infolist']);$this->sales=null;$this->canStart=null;$this->cachedActions=[];$this->cachedHeaderActions=[];
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
    /** @return array{label:string,tone:string,reason:?string,invalid:bool} */
    private function sales(): array
    {
        if(!isset($this->sales)){$t=$this->getRecord();$snapshot=TldFacts::snapshot($t);$this->sales=[...$snapshot['sales'],'invalid'=>(bool)($snapshot['invalid_rule']??false)];}
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
        if($s['invalid']||in_array($s['reason'],['Below cost','Price missing','Prices not published'],true)){return 'override';}
        return in_array($s['reason'],['No registrar selected','Not offered by this registrar'],true)?'seller':null;
    }
    /** Staff words for the head. Off on purpose is gray, a job for staff is amber, our own checks are blue. */
    private function headLine(): HtmlString
    {
        $t=$this->getRecord();$s=$this->sales();$reason=$s['reason'];$step=null;
        if($s['label']==='For sale'){return StatusMark::line('success','For sale','tld-'.$t->id);}
        if($this->staffStop()){
            $reason=$reason==='Stopped by staff'&&$this->offByDefault()?'Not sold by default':'Stopped by staff';
            return StatusMark::line('gray','Not for sale','tld-'.$t->id,$reason);
        }
        $tone=match(true){$s['invalid']=>'warning',in_array($reason,['Not offered by this registrar','Over the cost limit','Stopped by staff'],true)=>'gray',
            in_array($reason,['Checking registrar terms'],true)=>'info',default=>'warning'};
        $link=static fn(?string $url,string $label)=>$url?new HtmlString(e($reason).'. <a class="domains-link" href="'.e($url).'">'.e($label).'</a>'):$reason;
        $step=match($reason){
            'Cost missing','Cost out of date'=>$reason.'. Use Sync prices below.',
            'Check registrar account'=>$link(RegistrarFacts::accountUrl((int)$t->server_id),'Open the registrar account'),
            'Over the cost limit'=>$link(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::getUrl(['account'=>$t->server_id]),'Change it in What you sell'),
            default=>$reason};
        return StatusMark::line($tone,'Not for sale','tld-'.$t->id,$step);
    }
    /** Sell is unset and this account only sells TLDs set to Sell. */
    private function offByDefault(): bool
    {
        try{$raw=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($this->getRecord()->catalogue_intent);
            $d=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode(Account::where('server_id',$this->getRecord()->server_id)->sole()->catalogue_defaults);}
        catch(\Throwable){return false;}
        return ($raw['sell']??null)===null&&!($d['defaultSell']??true)&&($raw['stops']??[])===[];
    }
    /** Not for sale because of a stop Start selling lifts: staff stopped it, or stopped its renewals. */
    private function staffStop(): bool
    {return in_array($this->sales()['reason'],['Stopped by staff','Check TLD settings'],true)&&$this->canStart();}
    /** Transfers count only when this TLD sells them: its own choice, or the account's when it has none.
     * A stop saves transfers as off too, so a stopped TLD follows the account. */
    private function sellsTransfers(Tld $t): bool
    {
        try{$raw=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->catalogue_intent);
            $d=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);}
        catch(\Throwable){return false;}
        $own=$raw['transfers']??null;
        return (bool)($own===null||($own===false&&in_array('transfer',$raw['stops']??[],true))?($d['defaultTransfers']??false):$own);
    }
    private function mayChangeSales(): bool
    {return Staff::can(Staff::POLICY)&&Staff::can(Review::permission('tld_settings'));}
    /** A stop that Start selling can lift, read once per request. */
    private function canStart(): bool
    {
        $t=$this->getRecord();
        if(!$this->mayChangeSales()){return false;}
        // Keyed by the saved sales state, so a refreshed record reads again.
        $key=md5((string)$t->catalogue_intent.'|'.(string)$t->catalogue_facts);
        if(($this->canStart[0]??null)===$key){return $this->canStart[1];}
        $this->canStart=[$key,$this->readCanStart($t)];
        return $this->canStart[1];
    }
    private function readCanStart(Tld $t): bool
    {
        $catalogue=StaffCatalogue::routed();
        $on=static fn(string $key):bool=>(bool)($catalogue?->setting($t,$key)??$t->{$key==='enabled'?'enabled':$key.'_enabled'});
        if(!$on('enabled')||!$on('renew')){return true;}
        $transfers=$this->sellsTransfers($t);
        if($on('register')&&($on('transfer')||!$transfers)){return false;}
        // Only now read what the registrar terms allow: the heavier read.
        try{$accepted=$this->acceptedSales();}catch(\Throwable){return false;}
        return (!$on('register')&&$accepted['register'])||($transfers&&!$on('transfer')&&$accepted['transfer']);
    }
    private ?array $canStart=null;
    /** @return array{register:bool,transfer:bool} what the saved registrar terms allow, and transfers only if this TLD sells them */
    private function acceptedSales(): array
    {
        if(($catalogue=StaffCatalogue::routed())!==null){$t=$this->getRecord();$a=$catalogue->restorable($t);return ['register'=>$a['register'],'transfer'=>$a['transfer']&&$this->sellsTransfers($t)];}
        throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE);
    }
    /** Starts new registrations and transfers. A deliberate stop is never undone by price or term changes.
     * The TLD's own renewal stop clears too, since a TLD can't be sold without renewals. Account renewal settings stay. */
    public function startSellingAction(): Action
    {
        $t=$this->getRecord();$server=(int)$t->server_id;$id=(int)$t->id;
        return Action::make('start_selling')->label('Start selling')->color('primary')->requiresConfirmation()->icon(null)->modalIcon('heroicon-o-play-circle')
            ->modalHeading('Start selling .'.$t->tld.'?')->modalSubmitActionLabel('Start selling')
            ->modalDescription(fn()=>'New '.($this->opensTransfers()?'registrations and transfers':'registrations').' open at once'.($this->salePrices()?', at these prices.':'.'))
            ->modalContent(fn()=>$this->salePricesList())
            ->visible(fn():bool=>$this->canStart())
            ->schema([Hidden::make('review_key')->default(fn()=>Review::ticket((int)auth()->id(),$server,$id,'tld_settings',$this->reviewStamp))])
            ->action(function(array $data)use($server,$id):void {
                $input=[];
                try{
                    $t=$this->getRecord()->fresh();$catalogue=StaffCatalogue::routed();
                    $input=['min_years'=>$catalogue?->setting($t,'min_years')??$t->min_years,'max_years'=>$catalogue?->setting($t,'max_years')??$t->max_years,'enabled'=>true,'renew'=>true,...$this->acceptedSales(),'verified'=>true];
                    if(!$input['register']&&!$input['transfer']){throw new \DomainException('Acquisition acceptance unavailable.');}
                    app(StaffSettingsWrites::class)->run(new Review((int)auth()->id(),$server,$id,'tld_settings',(string)($data['review_key']??''),'Started selling from the TLD page.'),$input);
                    Notification::make()->success()->title('Change recorded')->send();
                }catch(\Throwable $error){Notification::make()->danger()->title('Change refused')->body(SettingsActions::refusal($error,'tld_settings',$input,$server,$id))->send();}
            });
    }
    /** Retail prices that go live, one year each. */
    private function salePrices(): array
    {
        $prices=collect(TldFacts::snapshot($this->getRecord())['prices']??[]);
        $price=static fn(string $action)=>$prices->first(fn($p)=>$p['action']===$action&&$p['term']===1)['retail']??null;
        return array_filter(['Register'=>($r=$price('register'))?$r.' a year':null,'Transfer'=>$this->opensTransfers()?$price('transfer'):null,'Renew'=>($r=$price('renew'))?$r.' a year':null]);
    }
    private function opensTransfers(): bool
    {try{return $this->acceptedSales()['transfer'];}catch(\Throwable){return false;}}
    private function salePricesList(): ?HtmlString
    {
        $rows=$this->salePrices();
        return $rows?new HtmlString('<dl class="domains-sale-prices">'.implode('',array_map(fn($k,$v)=>'<div><dt>'.e($k).'</dt><dd>'.e($v).'</dd></div>',array_keys($rows),$rows)).'</dl>'):null;
    }
    /** Stops new registrations and transfers. Domains customers already have keep renewing. */
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
                    $t=$this->getRecord()->fresh();$catalogue=StaffCatalogue::routed();
                    $input=['min_years'=>$catalogue?->setting($t,'min_years')??$t->min_years,'max_years'=>$catalogue?->setting($t,'max_years')??$t->max_years,
                        'enabled'=>true,'register'=>false,'transfer'=>false,'renew'=>(bool)($catalogue?->setting($t,'renew')??$t->renew_enabled),'verified'=>true];
                    app(StaffSettingsWrites::class)->run(new Review((int)auth()->id(),$server,$id,'tld_settings',(string)($data['review_key']??''),'Stopped selling from the TLD page.'),$input);
                    Notification::make()->success()->title('Change recorded')->send();
                }catch(\Throwable $error){Notification::make()->danger()->title('Change refused')->body(SettingsActions::refusal($error,'tld_settings',$input,$server,$id))->send();}
            });
    }
    public function overrideAction(): Action {return $this->settings()['override'];}
    public function syncPricesAction(): Action {return SettingsActions::syncAll((int)$this->getRecord()->server_id)->name('syncPrices');}
    protected function fillForm(): void {$this->data=[];}
    public function getTitle(): string {Staff::requireView();return '.'.$this->getRecord()->tld;}
    public function getHeading(): HtmlString
    {return new HtmlString('<span class="font-mono domains-mono">'.e($this->getTitle()).'</span>');}
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
    private static function entry(string $label, ?string $value, string|HtmlString|null $sub = null, ?string $color = null, ?string $url = null, ?int $at = null, ?HtmlString $suffix = null, array|Html $side = []): Flex
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($value)->url($url)->suffix($suffix);
        if($value!==null&&$sub!==null){$entry->belowContent($sub);}
        if($at!==null){$entry->formatStateUsing(fn()=>Sheet::moment($at));}
        return Sheet::row(MissingEntry::apply($entry,cause:$value===null&&is_string($sub)?$sub:null,color:$color),$side,key:'fact_'.md5($label));
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
        $sales=[self::entry('Registrar for new orders',$seller?(string)\App\Models\Server::whereKey($sellerId)->value('name'):null,$seller?'Existing domains stay with their registrar':($this->headFix()==='seller'?self::CHOOSE_SELLER_HEAD:self::CHOOSE_SELLER),color:'primary',url:RegistrarFacts::accountUrl($sellerId),suffix:$seller&&DemoData::is($seller)?DemoData::tag(inline:true):null),
            self::entry('Terms',$snapshot['unreadable']?null:TldFacts::terms($t),$snapshot['unreadable']?'Saved TLD terms unreadable. Use Edit prices to repair them.':null)];
        $refresh=$snapshot['refresh']??[];
        // One row: when prices last updated, and when they update next. Sync prices sits beside it.
        $sync=[self::entry('Last updated',isset($refresh['succeeded_at'])?'set':'Not yet',
            isset($refresh['due_at'])?Sheet::moment((int)$refresh['due_at'],'Next update'):'No update scheduled. Check Site setup on the Registrar account page, and the background worker.',
            at:isset($refresh['succeeded_at'])?(int)$refresh['succeeded_at']:null,side:[$this->syncPricesAction()->label('Sync prices')])];
        if($failure=TldFacts::syncFailure((int)$t->server_id)){$sync[]=self::entry('Last refresh failure','Refresh failed',$failure['cause'],'danger');}
        return $schema->components([
            Sheet::split([Sheet::section('Sales',$sales),Sheet::section('Price sync',$sync)],[$this->activity()]),
            // A copy renders as a button here; the menu keeps its own item view.
            Section::make('Prices by registrar account')->extraAttributes(['class'=>'domains-surface'])
                ->schema([TextEntry::make('prices')->label('Prices by registrar account')->hiddenLabel()->state(fn()=>TldFacts::accountsTable($t,$grants))->html()])->columnSpanFull(),
        ]);
    }
}
