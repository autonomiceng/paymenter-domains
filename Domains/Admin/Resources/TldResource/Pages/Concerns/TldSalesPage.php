<?php
// Enom 444f31c TLD pricing layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\Concerns;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\TldHistory;
use Filament\Actions\{Action,ActionGroup};
use Filament\Navigation\NavigationItem;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RegistrarFacts,Staff,TldFacts,TldRegistrar,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,State};
/** What both TLD tabs share: the sales actions, the head and the tab bar. The Overview and History pages each keep their own body. */
trait TldSalesPage
{
    public string $reviewStamp='';
    /** Page names for the shared settings actions. Default pricing is edited in What you sell, and saving prices publishes them. */
    private const LABELS=['seller'=>'Change registrar','override'=>'Edit prices'];
    public function mount(int|string $record): void
    {parent::mount($record);$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$this->getRecord()->server_id,(int)$this->getRecord()->id);}
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        if($action->getName()==='seller'){
            $t=$this->getRecord();$seller=TldFacts::sellerId($t->tld);
            $target=$seller===null?null:\Paymenter\Extensions\Servers\Domains\Models\Tld::where('tld',$t->tld)->where('server_id',$seller)->value('id');
            if($target!==null&&(int)$target!==(int)$t->id){$this->redirect(TldResource::getUrl('view',['record'=>$target]));return;}
        }
        // Writers load their own models. Rebuild the page so the next change needs no reload.
        $t=$this->getRecord()->refresh();$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$t->server_id,(int)$t->id);
        unset($this->cachedSchemas['infolist'],$this->cachedSchemas['content']);$this->sales=null;$this->snap=null;$this->start=null;$this->registrarChoices=null;$this->cachedActions=[];$this->cachedHeaderActions=[];
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
    private function tldActions(): array
    {
        $actions=$this->settings();$fix=$this->headFix();
        if($this->registrarBlocked()&&$this->snap()['seller']===null){
            $actions['seller']->label('Registrar choices')->modalHeading('Registrar choices');
        }
        // The dialog keeps the button's verb.
        $verb=$this->sales()['reason']==='No registrar selected'?'Choose registrar':'Change registrar';
        $seller=$fix==='seller'?$actions['seller']->label($verb)->modalHeading($verb)->color('primary'):null;
        // Every sales action is always registered; visibility picks the one that shows.
        return array_values(array_filter([$this->startSellingAction()->color($fix===null?'primary':'gray'),$seller,$actions['override']->color($fix==='override'?'primary':'gray'),
            ActionGroup::make(array_values(array_filter([$seller?null:$actions['seller'],$this->stopSellingAction()])))->label('More')->button()->color('gray')]));
    }
    /** @return array{label:string,tone:string,reason:?string,invalid:bool,kind:?string} */
    private function sales(): array
    {
        if(!isset($this->sales)){$t=$this->getRecord();$snapshot=$this->snap();$this->sales=[...$snapshot['sales'],'invalid'=>(bool)($snapshot['invalid_rule']??false),'kind'=>$snapshot['invalid_rule_kind']??null];}
        return $this->sales;
    }
    private ?array $sales=null;private ?array $snap=null;
    private function snap(): array {return $this->snap??=TldFacts::snapshot($this->getRecord(),Staff::requireView());}
    /** The fix that holds the primary spot: none while Start selling lifts a staff stop. */
    private function headFix(): ?string
    {
        if($this->staffStop()){return null;}
        $fix=$this->fix();
        if($fix==='seller'&&(!$this->registrarAlternative()||!TldFacts::canChangeSeller())){return null;}
        return $fix;
    }
    private ?array $registrarChoices=null;
    private function registrarChoices(): array
    {return $this->registrarChoices??=TldRegistrar::choices((int)$this->getRecord()->id);}
    private function registrarAlternative(): bool
    {
        return TldRegistrar::selectable($this->registrarChoices(),$this->snap()['seller']??null);
    }
    private function registrarBlocked(): bool
    {return $this->fix()==='seller'&&!$this->registrarAlternative();}
    /** Which head button fixes the reason a TLD is not for sale, when it is on this page. */
    private function fix(): ?string
    {
        $s=$this->sales();
        if($s['label']==='For sale'){return null;}
        // Edit prices can't repair the account's defaults or settings it can't read; the reason names where to.
        if($s['invalid']&&in_array($s['kind'],['account_defaults','unreadable'],true)){return null;}
        if($s['invalid']||in_array($s['reason'],['Below cost','Price missing','Prices not published'],true)){return 'override';}
        return in_array($s['reason'],['No registrar selected','Not offered by this registrar'],true)||TldFacts::unsupported($s['reason'])?'seller':null;
    }
    /** Browser tabs keep the account, so two accounts' pages for one TLD never share a title. */
    protected function browserTitle(): ?string {$a=$this->account();return $a===null?null:'.'.$this->getRecord()->tld.' on '.$a;}
    /** The head: the TLD, its sales state, and the link that names where the reason is fixed. The reason itself is the
     * Sales notice, so the head says only the state. */
    protected function head(): Head
    {
        $t=$this->getRecord();$s=$this->sales();$reason=(string)$s['reason'];$a=$this->account();
        // Only the account for new orders is named: on another account's row the name would read as the registrar.
        $head=Head::make('.'.$t->tld.($a!==null&&($this->snap()['seller']??null)===(int)$t->server_id?' on '.$a:''),Reader::Staff)->tag((bool)($this->snap()['demo']??false),false)
            ->state($this->saleState(),'tld-'.$t->id);
        if($s['label']==='For sale'||$this->staffStop()){return $head;}
        $url=match(true){
            in_array($reason,['Check registrar account',TldFacts::RENEWALS_OFF],true)=>RegistrarFacts::accountUrl((int)$t->server_id),
            $reason==='Over the cost limit'=>\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::getUrl(['account'=>$t->server_id]),
            default=>null};
        return $url===null?$head:$head->link($reason==='Over the cost limit'?'Change it in What you sell':'Open the registrar account',$url);
    }
    private function saleState(): State
    {
        $sales = $this->sales();

        return match (true) {
            $sales['label'] === 'For sale' => State::ForSale,
            $this->snap()['checking_sales'] ?? false => State::Checking,
            default => State::NotForSale,
        };
    }

    /** Why the TLD is not for sale, as the sentence the Sales notice says. Null while it is for sale. */
    private function saleReason(): ?string
    {
        $s=$this->sales();$reason=(string)$s['reason'];
        if($s['label']==='For sale'){return null;}
        if($this->staffStop()){return rtrim($reason,'.').'.';}
        if($this->registrarBlocked()){
            return rtrim($reason,'.').'. '.TldRegistrar::none($this->getRecord()->tld,TldRegistrar::selectable($this->registrarChoices()));
        }
        if($this->fix()==='seller'&&$reason!=='No registrar selected'&&!TldFacts::canChangeSeller()){
            return rtrim($reason,'.').'. '.TldFacts::ASK_SELLER;
        }
        if($reason===TldFacts::TERMS_EVIDENCE_MISSING){return $reason.'.';}
        if(($this->snap()['selected']??false)&&!\Paymenter\Extensions\Servers\Domains\Admin\Support\TldTerms::read($this->getRecord())&&!str_contains($reason,'registrar terms')){
            // Expired terms were read; they are out of date, and Sync prices refreshes them.
            return ($reason===''?'':rtrim($reason,'.').'. ').(\Paymenter\Extensions\Servers\Domains\Admin\Support\TldTerms::stale($this->getRecord())
                ?'Registrar terms are out of date. Use Sync prices below.':"Registrar terms couldn't be read. Sync prices tries again now.");
        }
        return match($reason){
            'Cost missing','Cost out of date'=>$reason.'. Use Sync prices below.',
            'No registrar selected'=>'No registrar selected. '.($this->settings()['seller']->isVisible()?'Choose a registrar for new orders.':'Ask staff who can change the registrar.'),
            TldFacts::TERMS_FAILED=>$reason.'. Sync prices tries again now.',
            default=>str_starts_with($reason,TldFacts::OTHER_SELLER.' ')?$reason.'. This account keeps its existing domains.':rtrim($reason,'.').'.'};
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
                $stopped=!$on('enabled')||!$on('renew')||array_filter($start['actions'],static fn(string $action):bool=>!$on($action))!==[];
                $start['can']=$stopped&&$start['actions']!==[]&&$start['block']===null;
            }catch(\Throwable){$start=['can'=>false,'actions'=>[]];}
            $this->start=[$key,$start];
        }
        return $this->start[1];
    }
    private ?array $start=null;
    /** Resume new orders admitted by the selected registrar. The TLD's own renewal stop clears too. */
    public function startSellingAction(): Action
    {
        $t=$this->getRecord();$server=(int)$t->server_id;$id=(int)$t->id;
        return Action::make('start_selling')->label('Start selling')->color('primary')->requiresConfirmation()->icon(null)->modalIcon('heroicon-o-play-circle')
            ->modalHeading('Start selling .'.$t->tld.'?')->modalSubmitActionLabel('Start selling')
            ->modalDescription(fn()=>$this->startDescription())
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
                    if($start['actions']===[]){throw new \DomainException('No acquisition actions to resume.');}
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
    private function startDescription(): ?string
    {
        $actions=$this->start()['actions'];
        $orders=match($actions){
            ['register','transfer']=>'registrations and transfers',
            ['register']=>'registrations',
            ['transfer']=>'transfers',
            default=>null,
        };
        return $orders===null?null:'New '.$orders.' are set to sell once all availability checks pass, including registrar terms.'
            .$this->salePricesText($actions);
    }
    /** Annual retail prices for the actions this confirmation restores. */
    private function salePricesText(array $actions): string
    {
        $prices=collect($this->snap()['prices']??[]);
        $rows=[];
        foreach([...$actions,'renew'] as $action){
            $retail=$prices->first(fn($p)=>$p['action']===$action&&$p['term']===1)['retail']??null;
            if($retail!==null){$rows[]=ucfirst($action).' '.$retail.($action==='transfer'?'':' a year');}
        }
        return $rows?' Prices: '.implode(', ',$rows).'.':'';
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
    private ?string $accountName=null;private bool $accountRead=false;
    /** A TLD has a page on each registrar account that offers it. Then the page names its account, so two pages never
     * share one title. */
    private function account(): ?string
    {
        if($this->accountRead){return $this->accountName;}
        $t=$this->getRecord();$this->accountRead=true;
        $shared=TldFacts::bound(\Paymenter\Extensions\Servers\Domains\Models\Tld::query())->where('tld',$t->tld)->where('server_id','!=',$t->server_id)->exists();
        return $this->accountName=$shared?((string)(\App\Models\Server::whereKey($t->server_id)->value('name')?:'') ?: null):null;
    }
    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $id=['record'=>$this->getRecord()];$history=$this instanceof TldHistory;
        return [NavigationItem::make('Overview')->url(TldResource::getUrl('view',$id))->isActiveWhen(fn()=>!$history),
            NavigationItem::make('History')->url(TldResource::getUrl('history',$id))->isActiveWhen(fn()=>$history)];
    }
    public function getBreadcrumbs(): array {return [$this->getResourceUrl()=>'TLDs & pricing',$this->head()->title];}
}
