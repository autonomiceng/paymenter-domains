<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CardFee,PricingRule,StaffSettingsReview as Review};

/** Dormant translator. Writer owns the reviewed transaction; wake only enqueues bounded local work. */
final class StaffCommands
{
    public const NO_SELLER='No registrar chosen for new orders.';
    public const OTHER_SELLER='Another registrar account takes new orders.';
    public const ACTIONS=['acceptance','override','remove_override','default_pricing','tld_settings','seller','publish','import'];
    public function __construct(private readonly Writer $writer,private readonly AccountBindings $bindings,
        private readonly \Closure $wake,private readonly ?\Closure $sync=null) {}

    /** Columns only background price and terms reads and publication write. A staff save never depends on them being
     * unchanged since the page opened: the stamp below covers everything staff edit, so it is what catches another staff
     * edit made after the dialog opened. Publishing rewrites the kept renewal price, so a save made while the last one is
     * still publishing is not someone else's change. */
    private const BACKGROUND=['catalogue_revision'=>true,'catalogue_published_revision'=>true,'catalogue_facts'=>true,'catalogue_retained_annual_retail'=>true];
    /** Raw hash of what staff set: credentials, accounts, settings, TLD choices and the seller rows. Never decrypts credentials.
     * Saved costs, terms and publication are left out, so background reads can't make a save look like someone else's change. */
    public static function stamp(int $server,?int $tld=null): string
    {
        $ending=$tld?Tld::whereKey($tld)->where('server_id',$server)->value('tld'):null;
        $raw=static fn($q)=>(DB::transactionLevel()>0?$q->lockForUpdate():$q)->get()->map(static fn($r)=>(array)$r)->all();
        $staff=static fn($q)=>array_map(static fn(array $r)=>array_diff_key($r,self::BACKGROUND),$raw($q));
        $names=Tld::when($server!==Review::ALL_ACCOUNTS,fn($q)=>$q->where('server_id',$server))->when($tld,fn($q)=>$q->whereKey($tld))->pluck('tld');
        $ids=Server::where('extension','Domains')->orderBy('id')->pluck('id');
        return 'offer:'.hash('sha256',OfferState::json([UpgradeGate::generation(),
            $raw(DB::table((new Server)->getTable())->whereIn('id',$ids)->orderBy('id')),
            $raw(DB::table('domains_accounts')->whereIn('server_id',$ids)->orderBy('server_id')),
            Account::whereIn('server_id',$ids)->orderBy('server_id')->get()->map(\Paymenter\Extensions\Servers\Domains\Services\ApiCallLimits::forAccount(...))->all(),
            $raw(DB::table('settings')->where('settingable_type',(new Server)->getMorphClass())->whereIn('settingable_id',$ids)
                ->whereRaw("`key` NOT LIKE ? ESCAPE '!'",[str_replace('_','!_',Review::KEY).'%'])->orderBy('id')),
            $staff(DB::table('domains_tlds')->when($ending,fn($q)=>$q->where('tld',$ending),fn($q)=>$q->when($server!==Review::ALL_ACCOUNTS,fn($q)=>$q->where('server_id',$server)))->orderBy('id')),
            $raw(DB::table('domains_sales_policies')->whereIn('tld',$names)->orderBy('tld')),\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::stamp(DB::transactionLevel()>0),$server,$tld]));
    }
    public function publish(int $id,?Review $review=null): void
    {
        $t=Tld::findOrFail($id);
        if($review!==null){$this->run($review,[]);return;}
        $b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
        $d=OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);
        $this->writer->apply(Edit::republish($b,$t->tld),$this->revision($t,$d));
    }
    /** TLDs one staff save publishes before it answers. Each is one locked write, so hundreds could outrun the request;
     * the rest go back on sale in the background turn (OfferWork::BATCH a minute). */
    public const REPUBLISH_NOW = 50;
    /** After an account-wide change made every offer stale, republish at once the TLDs this account sells, the way Edit
     * prices > Save and publish does, so customers keep buying them. The background turn still covers the rest.
     * Returns the TLDs that could not be published (each one keeps its reason on its TLD page) and how many sold TLDs
     * were left for the background turn past $limit. @return array{failed:list<string>,left:int} */
    public function republishSold(int $server,int $limit=self::REPUBLISH_NOW): array
    {
        if(DB::transactionLevel()!==0){throw new \LogicException('Staff commands require the outer writer.');}
        $account=Account::where('server_id',$server)->sole();$b=AccountBindings::binding($account);$failed=[];$done=0;$left=0;
        $policies=DB::table('domains_sales_policies')->where('server_id',$server)->where('account_fingerprint',$b->fingerprint)->where('registrar_key',$b->registrarKey)
            ->where(fn($q)=>$q->where('register_enabled',true)->orWhere('transfer_enabled',true))->get()->keyBy('tld');
        $rows=Tld::where('server_id',$server)->where('account_fingerprint',$b->fingerprint)->where('registrar_key',$b->registrarKey)
            ->where('catalogue_converted',true)->whereIn('tld',$policies->keys())->orderBy('id')->get();
        foreach($rows as $t){
            try{
                $d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);
                if(!OfferState::sold($b,$t,$d,$policies[$t->tld])){continue;}
                $revision=$this->revision($t,$d);
                try{$current=OfferState::equivalent(OfferState::decode($t->catalogue_published_revision),get_object_vars($revision));}catch(\JsonException|\TypeError){$current=false;}
                if($current){continue;}
                if($done>=$limit){$left++;continue;}
                $done++;$this->writer->apply(Edit::republish($b,$t->tld),$revision);
            }catch(\Throwable $error){
                // One TLD that can't publish must not stop its siblings; it stays pending for the background turn.
                if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains account republish failed.',['tld_id'=>(int)$t->id,'exception_class'=>$error::class]);}
                $failed[]=$t->tld;
            }
        }
        return ['failed'=>$failed,'left'=>$left];
    }
    public function run(Review $review,array $data): array
    {
        if(!in_array($review->action,self::ACTIONS,true)){throw new \DomainException('Unsupported catalogue action.');}
        if(DB::transactionLevel()!==0){throw new \LogicException('Staff commands require the outer writer.');}
        if($review->action==='default_pricing'){
            try{$fee=CardFee::validate(['percent'=>$data['fee_percent']??'', 'fixed'=>$data['fee_fixed']??'']);}
            catch(\InvalidArgumentException){throw new \InvalidArgumentException(CardFee::INVALID);}
            $review->input($data);return $this->writer->defaultPricing($review,$this->rule($data,$fee),$fee);
        }
        $account=Account::where('server_id',$review->serverId)->sole();$binding=AccountBindings::binding($account);
        $query=Tld::where('server_id',$review->serverId)->where('catalogue_converted',true);
        if($review->tldId!==null){$query->whereKey($review->tldId);}
        $sell=$data['sell']??[];$names=array_unique(array_keys(($sell['choices']??[])+($sell['terms']??[])));
        if(count($names)>25){throw new \DomainException('Review at most 25 TLDs at once.');}
        $defaults=$review->action==='acceptance'&&isset($sell['rule']);
        $rows=(clone $query)->when($names!==[],fn($q)=>$q->whereIn('tld',$names))->orderBy('id')->limit($names===[]?1:25)->get();
        if($rows->isEmpty()){throw new \DomainException('Converted TLD required.');}
        $entries=[];$failed=[];
        foreach($names as $name){if(!$rows->contains('tld',$name)){$failed[$name]='Converted TLD required.';}}
        if($defaults){
            // A new rule replaces the saved defaults whole, so unreadable old defaults never block their repair.
            $saved=OfferState::replaceableDefaults($account->catalogue_defaults,$review->serverId);$intent=Intent::fromRule($sell['rule']);
            // Staff without Costs never see the cost limit, so their save keeps the saved one. The revision check catches a change since.
            if(!\App\Models\User::with('role')->find($review->actorId)?->hasPermission(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::COSTS)){
                $cost=PricingRule::wholesale($saved['maxAnnualCost']??null);
                $intent=new Intent($intent->defaultSell,$intent->defaultLongest,$intent->defaultTransfers,$cost);
            }
            $command=Edit::defaults($binding,$rows->first()->tld,$intent);
            $entries[]=[$command,$this->revision($rows->first(),$saved)];
        }
        if(!$defaults||$names!==[]){foreach($rows as $t){
            if($defaults&&!in_array($t->tld,$names,true)){continue;}
            try{
                $raw=OfferState::decode($t->catalogue_intent);$intent=$this->intent($raw);
                // Replacing or removing a rule must not require reading the broken old rule.
                $rule=in_array($review->action,['override','remove_override'],true)?null:Pricing::own($t);
                $command=match($review->action){
                    'publish'=>Edit::republish($binding,$t->tld),
                    'import'=>Edit::requestSync($binding,$t->tld),
                    'override','remove_override'=>Edit::override($binding,$t->tld,$intent,$review->action==='override'?$this->rule($data,CardFee::read()):null),
                    'seller'=>$this->seller($t,$data),
                    'acceptance'=>$this->row($binding,$t,$raw,$sell,$rule),
                    'tld_settings'=>isset($data['retained_annual_retail'])?Edit::retainRenewal($binding,$t->tld,$data['retained_annual_retail']):$this->settings($binding,$t,$raw,$data,$rule),
                    default=>throw new \DomainException('Unsupported catalogue action.')};
                $target=$command->binding()->serverId===$t->server_id?$t:Tld::where('server_id',$command->binding()->serverId)->where('tld',$t->tld)->first();
                if(!$target){throw new \DomainException('Seller offer missing.');}
                $targetDefaults=OfferState::decode(Account::where('server_id',$target->server_id)->sole()->catalogue_defaults);
                $entries[]=[$command,$this->revision($target,$targetDefaults)];
            }catch(\DomainException|\InvalidArgumentException $e){$failed[$t->tld]=$e->getMessage();}
        }}
        // Every picked TLD was refused for one reason: nothing is saved, and that reason is the refusal.
        if($entries===[]&&$failed!==[]&&count(array_unique($failed))===1){throw new \DomainException(reset($failed));}
        if(count($entries)>25||$entries===[]){throw new \DomainException('Review 1 to 25 valid commands.');}
        if($review->action==='import'&&$this->sync===null){throw new \LogicException('Offer sync composition required.');}
        $review->input([...$data,...($sell===[]?[]:['changes'=>[],'rule'=>$sell['rule']??null,'rule_changed'=>false,'rows'=>[]])]);
        $batch=$this->writer->reviewed($review,$entries,static fn()=>self::stamp($review->serverId,$review->tldId));
        $result=$batch['rows'];$failed=[...$failed,...$batch['failures']];
        if($defaults){($this->wake)($binding);}
        if(in_array($review->action,['acceptance','tld_settings','seller'],true)){
            foreach($entries as [$command]){if(isset($result[$command->tld()])){OfferScheduler::routed()?->requestFacts($command->binding(),$command->tld());}}
        }
        if($review->action==='import'){if($this->sync===null){throw new \LogicException('Offer sync composition required.');}($this->sync)($binding);}
        return ['pending'=>$defaults,'published'=>$defaults?0:count($result),'failed'=>array_map(static fn($name)=>'.'.$name,array_keys($failed)),'rows'=>$result,'failures'=>$failed];
    }
    private function revision(Tld $t,array $d): Revision
    {return OfferState::revision($t,$d,DB::table('domains_sales_policies')->where('tld',$t->tld)->first());}
    private function intent(array $raw): Intent
    {return new Intent(...array_intersect_key($raw,get_object_vars(new Intent)));}
    private function rule(array $data,array $fee): array
    {return PricingRule::validate(['mode'=>$data['mode']??null,'markup'=>$data['markup']??'0','round_up'=>$data['round_up']??'none',
        'fixed'=>['register'=>$data['fixed_register']??null,'renew'=>$data['fixed_renew']??null,'transfer'=>$data['fixed_transfer']??null],
        ...(($data['mode']??null)==='markup_fee_percent'?['card_fee'=>$fee]:[])]);}
    private function row($binding,Tld $t,array $raw,array $sell,?array $rule): Edit
    {
        $name=$t->tld;$choice=$sell['choices'][$name]??null;
        if(array_key_exists($name,$sell['choices']??[])){
            if(!in_array($choice,['sell','skip',null],true)){throw new \DomainException('Invalid sell choice.');}
            // Sell does nothing until this account takes new orders for the TLD, so it is refused with the step that does.
            if($choice==='sell'){
                $policy=DB::table('domains_sales_policies')->where('tld',$name)->first();
                if($policy===null||$policy->server_id===null){throw new \DomainException(self::NO_SELLER);}
                if((int)$policy->server_id!==$binding->serverId){throw new \DomainException(self::OTHER_SELLER);}
            }
            $raw['sell']=$choice===null?null:$choice==='sell';
        }
        $terms=$sell['terms'][$name]??[];
        foreach(['longest'=>'longest','transfer'=>'transfers'] as $key=>$field){if(array_key_exists($key,$terms)){$raw[$field]=$terms[$key];}}
        return Edit::override($binding,$name,$this->intent($raw),$rule,$terms['transfer']??null);
    }
    private function settings($binding,Tld $t,array $raw,array $data,?array $rule): Edit
    {
        if(($data['min_years']??1)!=1){throw new \DomainException('Offer terms start at one year.');}
        if(in_array($data['sales']??null,['start','stop'],true)){return $this->sales($binding,$t,$raw,$data,$rule);}
        if(($data['enabled']??false)&&(($data['register']??false)||($data['transfer']??false))){
            $defaults=OfferState::decode(Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
            $raw=[...$raw,...get_object_vars($this->intent($raw)->allowSales(array_keys(array_filter(['register'=>$data['register']??false,'transfer'=>$data['transfer']??false]))))];
            $raw['sell']=($defaults['defaultSell']??true)?null:true;
        }
        $raw['salesPaused']=false;
        $raw['longest']=(int)($data['max_years']??1);$raw['transfers']=(bool)($data['transfer']??false);$raw['stops']=[];
        foreach(['register','renew','transfer'] as $action){if(!($data['enabled']??false)||!($data[$action]??false)){$raw['stops'][]=$action;}}
        if(in_array('renew',$raw['stops'],true)&&Domain::where(['server_id'=>$binding->serverId,'tld'=>$t->tld,'account_fingerprint'=>$binding->fingerprint])->whereNotNull('reservation_name')->exists()){
            throw new \DomainException('Retained holders require annual renewal support.');}
        // A verified checkbox is administrative intent only; this command never creates or ages Facts.
        return Edit::override($binding,$t->tld,$this->intent($raw),$rule,array_key_exists('transfer',$data)?$raw['transfers']&&!in_array('transfer',$raw['stops'],true):null);
    }
    /** The TLD page pauses and resumes sales. The acquisition pause and sell choice change, so the TLD's own transfer
     * and term choices survive a Stop, and Start preserves independent transfer restrictions. Start also lifts the TLD's
     * renewal stop, and sets Sell only when the TLD doesn't sell already. */
    private function sales($binding,Tld $t,array $raw,array $data,?array $rule): Edit
    {
        $stops=$raw['stops']??[];
        if($data['sales']==='stop'){
            $raw['salesPaused']=true;
            return Edit::override($binding,$t->tld,$this->intent($raw),$rule);
        }
        $d=OfferState::decode(Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
        $raw['salesPaused']=false;
        $raw['stops']=array_values(array_diff($stops,['register','renew']));
        if(!($raw['sell']??$d['defaultSell']??true)){$raw['sell']=($d['defaultSell']??true)?null:true;}
        return Edit::override($binding,$t->tld,$this->intent($raw),$rule);
    }
    private function seller(Tld $t,array $data): Edit
    {
        $id=($data['seller']??'')===''?(int)$t->server_id:(int)$data['seller'];
        $account=Account::where('server_id',$id)->first()??throw new \DomainException('Seller account missing.');
        $b=AccountBindings::binding($account);
        $actions=($data['seller']??'')===''?[]:array_keys(array_filter(['register'=>(bool)($data['register']??false),'transfer'=>(bool)($data['transfer']??false)]));
        $target=Tld::where('server_id',$id)->where('tld',$t->tld)->first()??throw new \DomainException('Seller offer missing.');
        if($actions!==[]&&($prices=SellerAvailability::state($account,$target))!==SellerAvailability::READY){throw new \DomainException($prices===SellerAvailability::UNREADABLE?'Seller price unreadable.':'Seller price missing.');}
        $intent=null;
        if($actions!==[]){
            $raw=OfferState::decode($target->catalogue_intent);$intent=$this->intent($raw)->allowSales($actions);
            $defaults=OfferState::decode($account->catalogue_defaults);
            $intent=new Intent(...[...get_object_vars($intent),'sell'=>($raw['sell']??null)===true?true:(($defaults['defaultSell']??true)?null:true)]);
        }
        return Edit::seller($b,$t->tld,$actions,$intent);
    }
}
