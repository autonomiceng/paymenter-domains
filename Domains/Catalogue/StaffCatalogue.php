<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Catalogue\Feed\OfferWork;
use Paymenter\Extensions\Servers\Domains\Catalogue\Staff\{NextSteps, Snapshots};
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffGrants,TldFacts,TldTerms};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,OfferRow,Tld};
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferTerms;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceList,PricingRule,PricingRules};

/** Bounded Staff snapshots over bound identities; recovery only hints the existing read queue. */
final class StaffCatalogue
{
    public static function routed(): ?self { return CallerRouting::resolve(self::class); }
    /** Staff stops that Start selling can lift. */
    public const STARTS=['staff_stopped','staff_term_stopped','renewal_stopped'];
    public function __construct(private readonly CallerPorts $ports,private readonly Clock $clock,private readonly ?OfferWork $work=null,private readonly ?\Closure $readable=null) {}
    public function readable(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $b): bool
    {Staff::requireView();return $this->readable!==null&&($this->readable)($b);}
    public function progress(int $server): array
    {
        Staff::requireView();
        return $this->work?->status(AccountBindings::binding(Account::where('server_id',$server)->sole()))??['state'=>'idle'];
    }
    /** Staff-only recovery hints. Scoped sales views may also enqueue on the retained worker.
     * @param ?array $once account and settings reads (binding, readable, queue status and sync, pricing defaults) shared by
     * every action of one table build. The caller owns it for one request; this routed catalogue never keeps it. */
    public function nextSteps(Collection $tlds,array $rows,string $action,int $term,?array &$once=null,bool $queueRecovery=true): array
    {
        Staff::requireView();$out=[];$once??=[];
        $steps=new NextSteps($this,$this->clock,$this->work);
        foreach($tlds as $t){$key=$t->server_id.':'.$t->tld;$out[$key]=$steps->next($t,$rows[$key]['offers']??[],$action,$term,$once,$queueRecovery);}
        return $out;
    }
    /** Derive safe recovery text before cost-bearing offers are removed from the public staff page DTO. */
    public function recovery(Collection $tlds,string $action,int $term): array
    {
        $page=$this->snapshots($tlds);$rows=[];
        foreach($tlds as $t){$rows[$t->server_id.':'.$t->tld]=$page[$t->id];}
        return $this->nextSteps($tlds,$rows,$action,$term);
    }
    /** The row's pricing rule. The business default and card fee are read once per call; if either can't be read,
     * each row reads them itself so its own rule still decides. */
    public static function ruleOnce(Tld $t,array &$once): array
    {
        if(!array_key_exists('pricing',$once)){
            try{$fee=CardFee::read();$once['pricing']=[(new PricingRules)->defaultRule(false,$fee),$fee];}catch(\Throwable){$once['pricing']=null;}
        }
        return $once['pricing']===null?Pricing::rule($t):Pricing::rule($t,...$once['pricing']);
    }
    /** Each offer's saved cost, keyed by action and term. */
    public static function costs(array $offers): array
    {
        $costs=[];foreach($offers as $offer){if($c=$offer->observation()){$costs[$c->key()]=$c;}}
        return $costs;
    }
    /** Form preview uses the same Staff evidence, never legacy wholesale or acceptance. */
    public function preview(Tld $t,array $rule): array
    {
        $out=[];$offers=$this->snapshots(collect([$t]))[$t->id]['offers'];$costs=self::costs($offers);$now=$this->clock->now();$quotes=[];
        foreach($offers as $key=>$offer){$d=$offer->decision;$cost=$offer->observation();if($cost===null){continue;}
            $new=Retail::quote($cost,$rule,$costs);
            // Publication preserves contractual annual renewal retail without a fresh positive quote.
            if($d->action==='renew'&&$d->term===1&&($new===null||PricingRule::units($new,2)<1||!$cost->fresh($now))){$new=OfferState::money($t->catalogue_retained_annual_retail,2);}
            $quotes[$key]=$new;
        }
        $published=array_filter($quotes,static fn($q)=>$q!==null&&PricingRule::units($q,2)>=1);
        foreach($offers as $key=>$offer){$d=$offer->decision;$cost=$offer->observation();if($cost===null){continue;}
            $new=$quotes[$key];$total=Retail::cost($cost,$costs);
            $renewal=$d->action==='renew';
            $renewalShortfall=$renewal&&$new!==null&&$total!==null&&!PricingRule::covers($new,$total);
            $out[]=['action'=>$d->action,'term'=>$d->term,'cost'=>$total,'current'=>$offer->staffRetail(),'new'=>$new,'synced'=>$cost->fresh($now),
                'hold'=>$renewal?null:$offer->afterPublishing($published,$now),'alert'=>$renewalShortfall?'below_cost':null];
        }
        return $out;
    }
    public function rows(array $bindings,?string $focus): \Illuminate\Database\Eloquent\Builder
    {
        $costs=Staff::requireView()->costs;
        $q=TldFacts::bound(DB::table('domains_tlds'))->where(function($q)use($bindings):void {
            foreach($bindings as $b){$q->orWhere(fn($q)=>$q->where('domains_tlds.server_id',$b->serverId)->where('domains_tlds.account_fingerprint',$b->fingerprint));}
            if(!$bindings){$q->whereRaw('1=0');}
        });
        $cost=DB::table('domains_prices')->where('action','register')->where('term',1)->where('currency','USD');
        $q->leftJoinSub($cost,'p',fn($j)=>$j->on('p.server_id','=','domains_tlds.server_id')->on('p.tld','=','domains_tlds.tld')->on('p.account_fingerprint','=','domains_tlds.account_fingerprint'));
        $intent="case when json_valid(domains_tlds.catalogue_intent) then domains_tlds.catalogue_intent else null end";
        $extract='p.wholesale';
        $q->select('domains_tlds.id','domains_tlds.server_id','domains_tlds.registrar_key','domains_tlds.account_fingerprint','domains_tlds.tld')
            ->selectRaw('null as renew_cost,null as transfer_cost,null as high_cost,null as prices,null as read_at,1 as listed')
            ->selectRaw("case json_extract($intent, '$.sell') when true then 'sell' when false then 'skip' else null end as choice")
            ->selectRaw("json_extract($intent, '$.longest') as longest,json_extract($intent, '$.transfers') as transfer")
            ->selectRaw(($costs?'case when p.cost_enabled = 1 then cast('.$extract.' as decimal(18,4)) else null end':'null').' as register_cost');
        return OfferRow::query()->fromSub($q,'domains_offer_rows')->when($focus!==null,fn($q)=>$q->where('tld',$focus));
    }
    public function restorable(Tld $t): array
    {
        $facts=$this->page(collect([$t]))[$t->id]['facts'];
        $safe=$facts&&$facts->fresh($this->clock->now())&&\Paymenter\Extensions\Servers\Domains\Services\RegistryInputs::supported($t->registrar_key,$t->tld,$facts->registryFields)&&$facts->renewalSafe===true&&$facts->requiresNameservers===false&&$facts->autoRenewOnly===false&&in_array(1,$facts->terms['renew']??[],true);
        return ['register'=>$safe&&!empty($facts->terms['register']),'transfer'=>$safe&&in_array(1,$facts->terms['transfer']??[],true)];
    }
    /** Desired term limit stays saved, including inheritance. Current support only gates availability. */
    public function start(Tld $t): array
    {
        Staff::requireView();
        return self::startFor($t,OfferState::decode($t->catalogue_intent),OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults),
            OfferState::facts($t->catalogue_facts),$this->clock->now(),DB::table('domains_sales_policies')->where('tld',$t->tld)->first());
    }
    public static function startFor(Tld $t,array $raw,array $d,?Facts $facts,\DateTimeImmutable $now,?object $policy): array
    {
        $years=(int)($raw['longest']??$d['defaultLongest']??1);
        $actions=self::startActions($t,$raw,$d,$policy);
        return ['years'=>$years,'actions'=>$actions,'checking'=>!$facts||!$facts->fresh($now),
            'block'=>$actions===[]?null:OfferTerms::termsBlock($facts,(string)$t->registrar_key,$t->tld,
                in_array('register',$actions,true),$years,in_array('transfer',$actions,true),$now)];
    }
    /** Resume the selected seller's actions with its independent transfer restrictions. */
    public static function startActions(Tld $t,array $raw,array $d,?object $policy): array
    {
        if($policy===null||!TldTerms::matches($t,(array)$policy)){return [];}
        return array_keys(array_filter([
            'register'=>(bool)$policy->register_enabled,
            'transfer'=>$policy->transfer_enabled&&($raw['transfers']??$d['defaultTransfers']??false)
                &&!in_array('transfer',$raw['stops']??[],true),
        ]));
    }
    public function setting(Tld $t,string $key): bool|int
    {
        Staff::requireView();
        try{$raw=OfferState::decode($t->catalogue_intent);$d=OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);}
        catch(\Throwable){return in_array($key,['min_years','max_years'],true)?1:false;}
        return match($key){'min_years'=>1,'max_years'=>(int)($raw['longest']??$d['defaultLongest']??1),
            'enabled'=>($raw['sell']??$d['defaultSell']??true)&&($raw['stops']??[])!==['register','renew','transfer'],
            default=>($key==='renew'||!($raw['salesPaused']??false))&&!in_array($key,$raw['stops']??[],true)&&($key!=='transfer'||($raw['transfers']??$d['defaultTransfers']??false))};
    }
    public function own(Tld $t): bool {Staff::requireView();try{return Pricing::hasOwn($t);}catch(\Throwable){return !$t->use_default_rule;}}
    public function rule(int $server): array
    {
        $grants=Staff::requireView();$d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);
        $i=new Intent(...array_intersect_key($d,get_object_vars(new Intent)));
        return ['mode'=>$i->defaultSell?'except':'only','max_cost'=>$grants->costs?$i->maxAnnualCost:null,'years'=>$i->defaultLongest,'transfers'=>$i->defaultTransfers];
    }
    /** The saved defaults one by one for the form, so one bad value leaves the others to keep. Unreadable values are left out;
     * a missing value reads as the built-in default. The cost limit is left out for staff without Costs. */
    public function ruleFields(int $server): array
    {
        $grants=Staff::requireView();
        try{$d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);}catch(\JsonException|\TypeError){return [];}
        $d+=get_object_vars(new Intent);$fields=[];
        if(is_bool($d['defaultSell'])){$fields['mode']=$d['defaultSell']?'except':'only';}
        if(is_int($d['defaultLongest'])&&$d['defaultLongest']>=1&&$d['defaultLongest']<=10){$fields['years']=$d['defaultLongest'];}
        if(is_bool($d['defaultTransfers'])){$fields['transfers']=$d['defaultTransfers'];}
        if($grants->costs&&($d['maxAnnualCost']===null||is_string($d['maxAnnualCost'])&&PricingRule::wholesale($d['maxAnnualCost'])!==null)){$fields['max_cost']=$d['maxAnnualCost'];}
        return $fields;
    }
    public function pricing(int $server,?Tld $t=null): array
    {
        Staff::requireView();return Pricing::rule($t);
    }
    public function fee(int $server): array
    {Staff::requireView();return CardFee::read();}
    /** Sale filtering needs eligibility only, not page prices, queue history or holder counts.
     * Each load is capped at the reader's 500 identities. Results live in the caller's request only. */
    public function saleIds(Collection $tlds): array
    {
        Staff::requireView();if($tlds->count()>500){throw new \DomainException('Sale filter exceeds 500 rows.');}
        if($tlds->isEmpty()){return [];}
        $accounts=Account::whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');$queries=[];
        foreach($tlds->groupBy('server_id')as$server=>$rows){$queries[]=new Query(AccountBindings::binding($accounts[$server]),$rows->pluck('tld')->all(),'register');}
        $loaded=$this->ports->staffBatch($queries);$offers=[];
        foreach($queries as$i=>$q){foreach($loaded[$i]as$name=>$rows){$offers[$q->binding->serverId.':'.$name]=$rows;}}
        $ids=[];$once=[];
        foreach($tlds as$t){
            try{self::ruleOnce($t,$once);}catch(\Throwable){continue;}
            if(in_array(true,self::storefront($offers[$t->server_id.':'.$t->tld]??[]),true)){$ids[]=(int)$t->id;}
        }
        return $ids;
    }
    /** The storefront's rule (AcquisitionOffers::display): a registration or transfer sells only with a current annual
     * renewal on the same account. Staff "For sale" follows it, so both agree.
     * @return array{register:bool,transfer:bool} the actions the storefront offers */
    public static function storefront(array $offers): array
    {
        $renewal=isset($offers['renew:1'])&&$offers['renew:1']->decision->available;$sold=['register'=>false,'transfer'=>false];
        foreach($offers as$offer){if($renewal&&isset($sold[$offer->decision->action])&&$offer->decision->available){$sold[$offer->decision->action]=true;}}
        return $sold;
    }
    /** Each call is at most one table page. Same evaluator and observation evidence as the public adapter. */
    public function page(Collection $tlds): array { return $this->pageFor($tlds,Staff::requireView()); }
    /** One rights read covers the page: snapshot costs and the offers left for staff without costs. */
    private function pageFor(Collection $tlds,StaffGrants $grants): array
    {
        $out=$this->snapshots($tlds,$grants);
        if(!$grants->costs){foreach($out as &$row){$row['offers']=[];}}
        return $out;
    }
    private function snapshots(Collection $tlds,?StaffGrants $grants=null): array
    { return (new Snapshots($this->ports,$this->clock))->load($tlds,$grants??Staff::requireView()); }
    /** Shared settings are validated independently so a bad default cannot hide a valid own rule. */
    private static function pricingState(): array
    {
        try{$fee=CardFee::read();}catch(\DomainException|\InvalidArgumentException){$fee=null;}
        try{$default=(new PricingRules)->defaultRule(false,$fee??CardFee::NONE);}catch(\DomainException|\InvalidArgumentException){$default=null;}
        return ['fee'=>$fee,'default'=>$default];
    }
    private static function pricingCause(Tld $t,array $state,bool $unreadable=false): ?string
    {
        if($state['fee']===null){return 'card_fee';}
        if($unreadable){return 'unreadable';}
        try{$own=Pricing::hasOwn($t);if(!$own&&$state['default']===null){return 'default_rule';}
            Pricing::rule($t,$state['default'],$state['fee']);return null;
        }catch(\DomainException|\InvalidArgumentException){return 'custom_rule';}
    }
    /** Omitted-price identities only: validate saved settings without loading every off-page offer and price. */
    public function omissions(Collection $tlds): array {return array_keys($this->omissionCauses($tlds));}
    /** @return array<string,string> why each omitted TLD is left out, by server:tld */
    public function omissionCauses(Collection $tlds): array
    {
        abort_unless(Staff::requireView()->costs,403);$out=[];$state=self::pricingState();
        foreach($tlds as $t){
            // Bad account defaults are not a pricing omission: the row's status names them.
            try{OfferState::decode($t->catalogue_intent);OfferState::intent($t,[]);$unreadable=false;}
            catch(\Throwable){$unreadable=true;}
            if(($cause=self::pricingCause($t,$state,$unreadable))!==null){$out[$t->server_id.':'.$t->tld]=$cause;}
        }
        return $out;
    }
    /** Readable saved costs for one term, by TLD id and action:term, from rows bound to the TLD's current account.
     * For staff figures only, when a TLD has no offers to read them from; never a sale decision. */
    private static function boundCosts(Collection $tlds,int $years): array
    {
        $out=[];
        foreach($tlds->groupBy('server_id') as $id=>$rows){
            $a=Account::where('server_id',$id)->sole();
            $bound=$rows->filter(fn($t)=>$t->account_fingerprint===$a->account_fingerprint&&$t->registrar_key===$a->registrar_key)->keyBy('tld');
            if($bound->isEmpty()){continue;}
            $saved=[];
            foreach(OfferState::query('domains_prices')->where('server_id',$id)->whereIn('tld',$bound->keys()->all())->whereIn('term',array_unique([1,$years]))->where('currency','USD')
                ->where('account_fingerprint',$a->account_fingerprint)->where('registrar_key',$a->registrar_key)->orderBy('id')->get() as $p){
                $cost=OfferState::savedCost($p);
                if($cost===null||$cost->action!==$p->action||$cost->term!==(int)$p->term){continue;}
                $saved[(int)$bound[$p->tld]->id][$cost->key()]=$cost;
            }
            foreach($saved as $tld=>$costs){foreach($costs as $key=>$cost){
                if($cost->term===$years){$out[$tld][$key]=Retail::cost($cost,$costs);}
            }}
        }
        return $out;
    }
    /** The matrix/CSV contract uses the exact same page snapshots, chunked without an unbounded SQL IN. */
    public function matrix(Collection $tlds,int $years,string $filter='all'): array
    {
        $grants=Staff::requireView();abort_unless($grants->costs,403);$rows=[];$accounts=[];$skipped=[];$warnings=[];
        $state=self::pricingState();$fee=$state['fee'];$default=$state['default'];
        foreach($tlds->chunk(100) as $chunk){$page=$this->pageFor($chunk,$grants);$settings=[];$bound=null;
            foreach($chunk as $t){$s=$page[$t->id];$id=(int)$t->server_id;$own=$s['own'];
                if($filter==='default'&&$own||$filter==='own'&&!$own){continue;}
                if(!isset($settings[$id])){$a=Account::where('server_id',$id)->sole();$d=OfferState::decode($a->catalogue_defaults);$settings[$id]=[$d,Server::whereKey($id)->value('name')];}
                [$d,$name]=$settings[$id];$accounts[$id]=$name;
                $identity=['id'=>(int)$t->id,'tld'=>'.'.$t->tld,'server_id'=>$id,'account'=>$name,'years'=>$years,'pricing'=>$own?'Custom':'Default','currency'=>'USD'];
                if(($cause=self::pricingCause($t,$state,$s['unreadable']))!==null){
                    $skipped[]=[...$identity,'cause'=>$cause];$key=in_array($cause,['custom_rule','unreadable'],true)?$id.':'.$t->tld:$cause;
                    $warnings[$key]??=['server_id'=>$id,'account'=>$name,'cause'=>$cause,'tlds'=>[]];
                    if(!in_array('.'.$t->tld,$warnings[$key]['tlds'],true)){$warnings[$key]['tlds'][]='.'. $t->tld;}
                    continue;
                }
                $rule=Pricing::rule($t,$default,$fee);
                $row=[...$identity,'prices'=>[],
                    'formula'=>'Price = '.PricingRule::describe($rule).($rule['mode']==='fixed'?', times years.':', per year, times years.'),'fee'=>$fee,'fee_in_price'=>$rule['mode']==='markup_fee_percent'];$below=$loss=false;
                $costs=self::costs($s['offers']);
                foreach(PriceList::ACTIONS as $action=>$unused){$offer=$s['offers'][$action.':'.$years]??null;$price=$offer?->staffRetail();
                    // A TLD with no offers to read, such as under bad account defaults, still shows its saved costs and losses. Sales stay closed.
                    $cost=$s['offers']!==[]||$action!=='register'&&$years!==1?(($observed=$offer?->observation())===null?null:Retail::cost($observed,$costs))
                        :(($bound??=self::boundCosts($chunk,$years))[(int)$t->id][$action.':'.$years]??null);
                    if($action==='renew'&&$years===1){$price=OfferState::money($t->catalogue_retained_annual_retail,2);}
                    $profit=$price!==null&&$cost!==null?CardFee::profit($price,$cost,$fee):null;$under=$price!==null&&$cost!==null&&!PricingRule::covers($price,$cost);$loses=$profit!==null&&str_starts_with($profit,'-');$below|=$under;$loss|=$loses;
                    $row['prices'][$action]=['price'=>$price,'cost'=>$cost,'profit'=>$profit,'revision'=>$offer?->decision->revision->offer,'below_cost'=>$under,'loses_money'=>$loses,
                        'status'=>$price===null?($action==='transfer'&&!($s['intent']['transfers']??$s['defaults']['defaultTransfers']??false)?PriceList::TRANSFERS_OFF:'Missing'):($cost===null?'Cost missing':($under?'Below cost':($loses?'Loses money after card fee':'')))];}
                if($filter==='below_cost'&&!$below||$filter==='loses_money'&&!$loss){continue;}$rows[]=$row;
            }
        }
        return ['rows'=>$rows,'skipped'=>$skipped,'warnings'=>array_values($warnings),'accounts'=>$accounts,'years'=>$years,'explanations'=>PriceList::explain($rows)];
    }
}
