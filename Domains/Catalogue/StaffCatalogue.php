<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffGrants,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,OfferRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PriceList,PricingRule,PricingRules};

/** Bounded Staff snapshots over converted identities; recovery only hints the existing read queue. */
final class StaffCatalogue
{
    public static function routed(): ?self { return CallerRouting::resolve(self::class); }
    private const STARTS=['staff_stopped','staff_term_stopped','renewal_stopped'];
    public function __construct(private readonly CallerPorts $ports,private readonly Clock $clock,private readonly ?OfferWork $work=null,private readonly ?\Closure $readable=null) {}
    public function readable(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $b): bool
    {Staff::requireView();return $this->readable!==null&&($this->readable)($b);}
    public function progress(int $server): array
    {
        Staff::requireView();
        return $this->work?->status(AccountBindings::binding(Account::where('server_id',$server)->sole()))??['state'=>'idle'];
    }
    /** Staff-only recovery hints. Enqueue on the retained worker; never resolve a feed or change sale intent.
     * @param ?array $once account and settings reads (binding, readable, queue status and sync, pricing defaults) shared by
     * every action of one table build. The caller owns it for one request; this routed catalogue never keeps it. */
    public function nextSteps(Collection $tlds,array $rows,string $action,int $term,?array &$once=null): array
    {
        Staff::requireView();$out=[];$once??=[];
        foreach($tlds as $t){$key=$t->server_id.':'.$t->tld;$out[$key]=$this->nextStep($t,$rows[$key]['offers']??[],$action,$term,$once);}
        return $out;
    }
    /** Derive safe recovery text before cost-bearing offers are removed from the public staff page DTO. */
    public function recovery(Collection $tlds,string $action,int $term): array
    {
        $page=$this->snapshots($tlds);$rows=[];
        foreach($tlds as $t){$rows[$t->server_id.':'.$t->tld]=$page[$t->id];}
        return $this->nextSteps($tlds,$rows,$action,$term);
    }
    private function nextStep(Tld $t,array $offers,string $action,int $term,array &$once): array
    {
        $offer=$offers[$action.':'.$term]??null;$reason=$offer?->decision->reason;$now=$this->clock->now();
        $names=['register'=>'registration','renew'=>'renewal','transfer'=>'transfer'];
        $step=static fn(string $state,string $next,?string $open=null)=>compact('state','next','open');
        $missing=static fn(string $next,?string $open='tld')=>$step('missing',$next,$open);
        $waiting=static fn(array $s)=>!empty($s['local_failure'])?$missing('Check the registrar account and read prices again.','account'):
            $step('checking',isset($s['due_at'])&&$s['due_at']>=$now->getTimestamp()&&empty($s['remaining'])?'Next check '.gmdate('M j, Y, g:i A',$s['due_at']).' UTC.':'Queued for the next background run.');
        if(!$offers){return $missing('Check saved settings on .'.$t->tld.'.');}
        if($reason==='account_unavailable'){return $missing('Check account setup on Registrar account.','account');}
        try{$rule=self::ruleOnce($t,$once);}catch(\DomainException|\InvalidArgumentException){return $missing('Fix the pricing rule in Edit prices on .'.$t->tld.'.');}
        if($reason==='staff_term_stopped'&&$action==='register'){return $step('off','Use Edit terms in What you sell to change the longest registration term.','tld');}
        if($reason==='staff_term_stopped'&&$action==='transfer'){return $step('off','Transfers are off. Use Edit terms in What you sell to change that choice.','tld');}
        if(in_array($reason,self::STARTS,true)){
            try{$block=$this->start($t)['block'];}catch(\Throwable){return $missing('Check saved settings on .'.$t->tld.'.');}
            return match($block){null=>$step('off','Use Start selling on .'.$t->tld.'.','tld'),
                'unsupported_facts'=>$missing('Ask the registrar to confirm renewal and nameserver requirements for .'.$t->tld.'.',null),
                default=>$step('off','Not offered by this registrar for this term.')};
        }
        // Account reads and queue hints are per account, so the rows of one page share them.
        $server=(int)$t->server_id;
        $binding=static function()use($server,&$once){return $once['binding'][$server]??=AccountBindings::binding(Account::where('server_id',$server)->sole());};
        $readable=function($b)use($server,&$once):bool {return $once['readable'][$server]??=$this->readable($b);};
        if($reason==='publication_pending'&&$this->work){
            if(!isset($once['woken'][$server])){
                $this->work->wake($b=$binding());$status=$this->work->status($b);
                // Local publication is due on the next turn, independently of the feed date.
                unset($status['due_at']);$once['woken'][$server]=$status;
            }
            return $waiting($once['woken'][$server]);
        }
        try{$facts=OfferState::facts($t->catalogue_facts);}catch(\Throwable){return $missing('Check saved settings on .'.$t->tld.'.');}
        $human=null;
        if(!$facts||!$facts->fresh($now)||$t->registrar_key==='namesilo'
            &&!OfferState::testFactsAccepted($binding(),$t->tld,$facts,$this->clock)){
            if(!$this->work||!$readable($b=$binding())){return $missing('Check account setup on Registrar account.','account');}
            $this->work->requestFacts($b,$t->tld);$status=$this->work->status($b,$t->tld);
            if($status['state']!=='unsupported'){return $waiting($status);}
            $human=$missing('Ask the registrar to confirm terms for .'.$t->tld.'.',null);
        }
        $expiredRefusal=static function(string $key)use($offers,$now):bool {
            $cost=($offers[$key]??null)?->observation();
            return $cost!==null&&!$cost->enabled&&!$cost->fresh($now);
        };
        if($facts?->fresh($now)&&!in_array($term,$facts->terms[$action]??[],true)&&!$expiredRefusal($action.':'.$term)){return $step('off','Not offered by this registrar for this term.');}
        if($facts?->fresh($now)&&!in_array(1,$facts->terms['renew']??[],true)&&!$expiredRefusal('renew:1')){return $step('off','This registrar does not offer annual renewal for this TLD.');}
        if($reason==='unsupported_facts'){$human=$missing('Ask the registrar to confirm renewal and nameserver requirements for .'.$t->tld.'.',null);}
        $costs=[];foreach($offers as $offer){if($c=$offer->observation()){$costs[$c->key()]=$c;}}
        foreach(Retail::terms($action,$term) as [$needed,$years]){
            $key=$needed.':'.$years;
            $cost=$offers[$key]??null;$observation=$cost?->observation();
            if($observation&&$observation->fresh($now)&&!$observation->enabled){return $step('off','Not offered by this registrar for this term.');}
            if(!$observation||!$observation->fresh($now)||$cost->staffRetail()===null
                ||$key==='renew:1'&&OfferState::money($t->catalogue_retained_annual_retail,2)===null){
                if($observation?->fresh($now)){
                    $quote=Retail::quote($observation,$rule,$costs);
                    if($quote===null||PricingRule::units($quote,2)<1){return $missing('Set a positive '.$names[$observation->action].' price in Edit prices on .'.$t->tld.'.');}
                }
                if(!$this->work||!$readable($b=$binding())){return $missing('Check account setup on Registrar account.','account');}
                $status=$once['synced'][$server]??$once['status'][$server]??=$this->work->status($b);
                if(!$observation&&($status['succeeded_at']??0)>$now->getTimestamp()-86400&&empty($status['remaining'])){
                    [$needed,$years]=explode(':',$key);$name=$names[$needed];
                    return $missing('Ask the registrar for a '.$years.'-year '.$name.' cost for .'.$t->tld.'.',null);
                }
                if(!isset($once['synced'][$server])){$this->work->sync($b,RequestOrigin::Background);$once['synced'][$server]=$this->work->status($b);}
                return $waiting($once['synced'][$server]);
            }
        }
        if($human!==null){return $human;}
        return match($reason){
            'seller_changed'=>$missing($this->sellerStep($t,$action)),
            'below_cost'=>$missing('Raise the price in Edit prices on .'.$t->tld.'.'),
            'cost_limit'=>$missing('Change the cost limit under Default for new TLDs in What you sell.',null),
            'unpublished_price'=>$missing('Set a positive '.$names[$action].' price in Edit prices on .'.$t->tld.'.'),
            default=>$step('ready',''),
        };
    }
    /** The row's pricing rule. The business default and card fee are read once per call; if either can't be read,
     * each row reads them itself so its own rule still decides. */
    private static function ruleOnce(Tld $t,array &$once): array
    {
        if(!array_key_exists('pricing',$once)){
            try{$fee=CardFee::read();$once['pricing']=[(new PricingRules)->defaultRule(false,$fee),$fee];}catch(\Throwable){$once['pricing']=null;}
        }
        return $once['pricing']===null?Pricing::rule($t):Pricing::rule($t,...$once['pricing']);
    }
    /** This account is already the chosen registrar but its seller row has the action off: name that switch. */
    private function sellerStep(Tld $t,string $action): string
    {
        $p=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$t->tld)->first();
        $chosen=$p&&(int)$p->server_id===(int)$t->server_id&&$p->account_fingerprint===$t->account_fingerprint&&$p->registrar_key===$t->registrar_key;
        return $chosen&&in_array($action,['register','transfer'],true)&&!$p->{$action.'_enabled'}
            ?'Allow '.($action==='register'?'registrations':'transfers').' in More → Change registrar on .'.$t->tld.'.'
            :'Choose the registrar in More on .'.$t->tld.'.';
    }
    /** Form preview uses the same Staff evidence, never legacy wholesale or acceptance. */
    public function preview(Tld $t,array $rule): array
    {
        $out=[];$offers=$this->snapshots(collect([$t]))[$t->id]['offers'];$costs=[];
        foreach($offers as $offer){if($cost=$offer->observation()){$costs[$cost->key()]=$cost;}}
        foreach($offers as $offer){$d=$offer->decision;$cost=$offer->observation();if($cost===null){continue;}
            $new=Retail::quote($cost,$rule,$costs);
            // Publication preserves contractual annual renewal retail without a fresh positive quote.
            if($d->action==='renew'&&$d->term===1&&($new===null||PricingRule::units($new,2)<1||!$cost->fresh($this->clock->now()))){$new=OfferState::money($t->catalogue_retained_annual_retail,2);}
            $under=$new!==null&&$cost->total!==null&&!PricingRule::covers($new,$cost->total);
            $out[]=['action'=>$d->action,'term'=>$d->term,'cost'=>$cost->total,'current'=>$offer->staffRetail(),'new'=>$new,'synced'=>$cost->fresh($this->clock->now()),
                'hold'=>$d->action==='renew'?null:($under?'below_cost':$d->reason),'alert'=>$d->action==='renew'&&$under?'below_cost':null];
        }
        return $out;
    }
    public function rows(array $bindings,?string $focus): \Illuminate\Database\Eloquent\Builder
    {
        $costs=Staff::requireView()->costs;
        $q=TldFacts::bound(DB::table('domains_tlds'))->where('catalogue_converted',true)->where(function($q)use($bindings):void {
            foreach($bindings as $b){$q->orWhere(fn($q)=>$q->where('domains_tlds.server_id',$b->serverId)->where('domains_tlds.account_fingerprint',$b->fingerprint));}
            if(!$bindings){$q->whereRaw('1=0');}
        });
        $cost=DB::table('domains_prices')->where('action','register')->where('term',1)->where('currency','USD');
        $q->leftJoinSub($cost,'p',fn($j)=>$j->on('p.server_id','=','domains_tlds.server_id')->on('p.tld','=','domains_tlds.tld')->on('p.account_fingerprint','=','domains_tlds.account_fingerprint'));
        $intent="case when json_valid(domains_tlds.catalogue_intent) then domains_tlds.catalogue_intent else null end";
        $extract="json_extract(p.catalogue_observation, '$.total')";
        if(DB::getDriverName()!=='sqlite'){$extract='json_unquote('.$extract.')';}
        $q->select('domains_tlds.id','domains_tlds.server_id','domains_tlds.account_fingerprint','domains_tlds.tld')
            ->selectRaw('null as renew_cost,null as transfer_cost,null as high_cost,null as prices,null as read_at,1 as listed')
            ->selectRaw("case json_extract($intent, '$.sell') when true then 'sell' when false then 'skip' else null end as choice")
            ->selectRaw("json_extract($intent, '$.longest') as longest,json_extract($intent, '$.transfers') as transfer")
            ->selectRaw(($costs?'cast('.$extract.' as decimal(18,4))':'null').' as register_cost');
        return OfferRow::query()->fromSub($q,'domains_offer_rows')->when($focus!==null,fn($q)=>$q->where('tld',$focus));
    }
    public function restorable(Tld $t): array
    {
        $facts=$this->page(collect([$t]))[$t->id]['facts'];
        $safe=$facts&&$facts->fresh($this->clock->now())&&\Paymenter\Extensions\Servers\Domains\Services\RegistryInputs::supported($t->registrar_key,$t->tld,$facts->registryFields)&&$facts->renewalSafe===true&&$facts->requiresNameservers===false&&$facts->autoRenewOnly===false&&in_array(1,$facts->terms['renew']??[],true);
        return ['register'=>$safe&&!empty($facts->terms['register']),'transfer'=>$safe&&in_array(1,$facts->terms['transfer']??[],true)];
    }
    /** Desired term limit stays saved, including inheritance. Current support only gates availability.
     * Transfers describe the intersection of TLD preference, independent stop and selected seller policy. */
    public function start(Tld $t): array
    {
        Staff::requireView();
        return self::startFor($t,OfferState::decode($t->catalogue_intent),OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults),
            OfferState::facts($t->catalogue_facts),$this->clock->now(),DB::table('domains_sales_policies')->where('tld',$t->tld)->first());
    }
    private static function startFor(Tld $t,array $raw,array $d,?Facts $facts,\DateTimeImmutable $now,?object $policy): array
    {
        $longest=(int)($raw['longest']??$d['defaultLongest']??1);$transfers=(bool)($raw['transfers']??$d['defaultTransfers']??false);
        $transfers=$transfers&&!in_array('transfer',$raw['stops']??[],true)&&$policy
            &&(int)$policy->server_id===(int)$t->server_id&&$policy->account_fingerprint===$t->account_fingerprint&&$policy->registrar_key===$t->registrar_key&&(bool)$policy->transfer_enabled;
        $checking=!$facts||!$facts->fresh($now);$years=$longest;
        return ['years'=>$years,'transfers'=>$transfers,'checking'=>$checking,'block'=>Writer::termsBlock($facts,(string)$t->registrar_key,$t->tld,true,$years,$transfers,$now)];
    }
    public function setting(Tld $t,string $key): bool|int
    {
        Staff::requireView();
        try{$raw=OfferState::decode($t->catalogue_intent);$d=OfferState::decode(Account::where('server_id',$t->server_id)->sole()->catalogue_defaults);}
        catch(\Throwable){return in_array($key,['min_years','max_years'],true)?1:false;}
        return match($key){'min_years'=>1,'max_years'=>(int)($raw['longest']??$d['defaultLongest']??1),
            'enabled'=>!($raw['salesPaused']??false)&&($raw['sell']??$d['defaultSell']??true)&&($raw['stops']??[])!==['register','renew','transfer'],
            default=>($key==='renew'||!($raw['salesPaused']??false))&&!in_array($key,$raw['stops']??[],true)&&($key!=='transfer'||($raw['transfers']??$d['defaultTransfers']??false))};
    }
    public function own(Tld $t): bool {Staff::requireView();try{return Pricing::hasOwn($t);}catch(\Throwable){return !$t->use_default_rule;}}
    public function rule(int $server): array
    {
        $grants=Staff::requireView();$d=OfferState::decode(Account::where('server_id',$server)->sole()->catalogue_defaults);
        $i=new Intent(...array_intersect_key($d,get_object_vars(new Intent)));
        return ['mode'=>$i->defaultSell?'except':'only','max_cost'=>$grants->costs?$i->maxAnnualCost:null,'years'=>$i->defaultLongest,'transfers'=>$i->defaultTransfers];
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
            foreach($offers[$t->server_id.':'.$t->tld]??[]as$offer){
                if(in_array($offer->decision->action,['register','transfer'],true)&&$offer->decision->available){$ids[]=(int)$t->id;break;}
            }
        }
        return $ids;
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
    {
        $grants??=Staff::requireView();if($tlds->count()>100){throw new \DomainException('Staff page exceeds 100 rows.');}
        if($tlds->isEmpty()){return [];}
        $accounts=Account::whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');$queries=[];
        foreach($tlds->groupBy('server_id') as $server=>$rows){$queries[]=new Query(AccountBindings::binding($accounts[$server]),$rows->pluck('tld')->all(),'register');}
        $loaded=$this->ports->staffBatch($queries);$offers=[];
        foreach($queries as $i=>$q){foreach($loaded[$i] as $name=>$offer){$offers[$q->binding->serverId.':'.$name]=$offer;}}
        $policies=DB::table('domains_sales_policies')->whereIn('tld',$tlds->pluck('tld'))->get()->keyBy('tld');
        $sellers=$policies->pluck('server_id','tld');
        $refresh=DB::table('domains_observation_queues')->whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');
        $holders=Domain::whereIn('server_id',$tlds->pluck('server_id'))->whereIn('tld',$tlds->pluck('tld'))->whereNotNull('reservation_name')
            ->selectRaw('server_id,tld,account_fingerprint,count(*) as holders')->groupBy('server_id','tld','account_fingerprint')->get()->keyBy(fn($r)=>$r->server_id.':'.$r->tld.':'.$r->account_fingerprint);
        $out=[];$pricingOnce=[];
        foreach($tlds as $t){
            $d=OfferState::decode($accounts[$t->server_id]->catalogue_defaults);$unreadable=false;
            try{
                $raw=OfferState::decode($t->catalogue_intent);
                OfferState::intent($t,$d);
                $own=Pricing::hasOwn($t);
            }catch(\Throwable){$raw=[];$own=!$t->use_default_rule;$unreadable=true;}
            $invalidRule=$unreadable;
            $ruleKind=$unreadable?'unreadable':(!$own?'default':match($raw['pricing_rule']['mode']??$t->pricing_mode){'fixed'=>'fixed','markup_amount','markup_percent','markup_fee_percent'=>'markup',default=>'pricing'});
            if(!$unreadable){try{self::ruleOnce($t,$pricingOnce);}catch(\Throwable){$invalidRule=true;}}
            $all=$unreadable?[]:($offers[$t->server_id.':'.$t->tld]??[]);$prices=[];
            foreach($all as $offer){$decision=$offer->decision;$cost=$offer->observation();$flags=[];
                if($decision->reason!==null){$flags[]=$decision->reason;}
                if($cost!==null&&!$cost->enabled){$flags[]='not_offered';}
                if(!$cost||$cost->total===null){$flags[]='cost_missing';}
                if(!$cost?->fresh($this->clock->now())){$flags[]='cost_old';}
                $retail=$invalidRule?null:$offer->staffRetail();
                if(!$invalidRule&&$decision->action==='renew'&&$decision->term===1){$retail=OfferState::money($t->catalogue_retained_annual_retail,2);}
                $prices[]=['action'=>$decision->action,'term'=>$decision->term,'retail'=>$retail===null?null:TldFacts::money($retail),
                    'flags'=>$flags,'synced'=>$cost!==null,'observed'=>$cost?->observedAt,
                    ...($grants->costs?['cost'=>$cost?->total===null?null:TldFacts::money($cost->total,4)]:[])];
            }
            $register=collect($all)->first(fn($o)=>$o->decision->action==='register'&&$o->decision->available);
            $transfer=$all['transfer:1']??null;$chosen=$register??($transfer?->decision->available?$transfer:null);
            $reason=(in_array('register',$raw['stops']??[],true)?$transfer?->decision->reason:($all['register:1']->decision->reason??null))??'unknown_facts';
            try{$facts=OfferState::facts($t->catalogue_facts);}catch(\Throwable){$facts=null;}
            // One state for the TLD list and its page: a stop that Start selling can't lift names the terms block, and
            // a TLD the account doesn't sell by default says so.
            // Without a staff stop, a terms reason means the TLD is set to sell and its head offers Stop selling: say so.
            $stopped=in_array($reason,self::STARTS,true);
            if(!$unreadable&&$stopped){
                if(($block=self::startFor($t,$raw,$d,$facts,$this->clock->now(),$policies[$t->tld]??null)['block'])!==null){$reason=$block;}
                elseif($reason==='staff_stopped'&&($raw['sell']??null)===null&&!($d['defaultSell']??true)&&($raw['stops']??[])===[]
                    &&!\Paymenter\Extensions\Servers\Domains\Services\EndingChoices::hidden($t->tld)){$reason='not_sold_by_default';}
            }
            $label=match($reason){'staff_stopped','staff_term_stopped','renewal_stopped'=>'Stopped by staff','not_sold_by_default'=>'Not sold by default','seller_changed'=>'No registrar selected','publication_pending'=>'Prices not published','stale_price'=>'Cost out of date','below_cost'=>'Below cost','unknown_price'=>'Cost missing','unknown_facts','stale_facts'=>'Set to sell; checking registrar terms','unsupported_facts'=>($stopped?'This':'Set to sell, but this')." registrar's requirements aren't supported",'unsupported_term','provider_disabled'=>'Not offered by this registrar','unpublished_price'=>'Price missing','account_unavailable'=>'Check registrar account','cost_limit'=>'Over the cost limit',default=>'Check TLD settings'};
            $policy=$policies[$t->tld]??null;
            $selected=!$unreadable&&$policy&&(int)$policy->server_id===(int)$t->server_id&&$policy->account_fingerprint===$t->account_fingerprint&&$policy->registrar_key===$t->registrar_key
                &&\Paymenter\Extensions\Servers\Domains\Admin\Support\TldTerms::chosen($t,(array)$policy,$d);
            $chosen=$invalidRule?null:$chosen;
            if(!$selected&&!isset($sellers[$t->tld])){$label=TldFacts::NO_SELLER;}
            $queue=$refresh[$t->server_id]??null;
            if($queue&&$queue->account_fingerprint!==$t->account_fingerprint){$queue=null;}
            $work=$queue?OfferState::decode($queue->offer_work):[];
            // Only seller-policy writes establish selection time; pricing revisions do not.
            $selectedAt=$policy?->updated_at;
            try{$selectedAt=$selectedAt?\Carbon\CarbonImmutable::parse($selectedAt)->getTimestamp():null;}catch(\Throwable){$selectedAt=null;}
            $refreshed=['succeeded_at'=>$queue->offer_feed_succeeded_at??null,'due_at'=>$work['feed_due']??null,
                'failure'=>$queue->offer_feed_failure??null,'failed_at'=>$queue->offer_feed_failed_at??null,'selected_at'=>$selectedAt];
            $confirmed=TldFacts::feedConfirmed($refreshed);
            if(!$unreadable&&!collect($prices)->contains(fn($price)=>$price['synced'])&&($selected||isset($sellers[$t->tld]))){
                if($confirmed){$label="Not in the registrar's price feed; not for sale";}
                // Keep an account-unavailable status. A first read failure cannot establish a missing feed entry.
                elseif($reason==='account_unavailable'||$refreshed['failure']){$label='Check registrar account';}
            }
            $error=['invalid_rule_kind'=>$ruleKind];
            $out[$t->id]=['selected'=>(bool)$selected,'invalid_rule'=>$invalidRule,...$error,
                'refresh'=>$refreshed,
                'prices'=>$prices,'acquisition'=>['register'=>$register!==null,'transfer'=>$transfer?->decision->available??false],'sales'=>['label'=>$chosen?'For sale':'Not for sale','tone'=>$chosen?'success':'gray','reason'=>$chosen?null:($invalidRule?TldFacts::ruleProblem($error):$label)],
                'seller'=>isset($sellers[$t->tld])?(int)$sellers[$t->tld]:null,'holders'=>(int)($holders[$t->server_id.':'.$t->tld.':'.$t->account_fingerprint]->holders??0),
                'demo'=>\Paymenter\Extensions\Servers\Domains\Support\DemoData::is($accounts[$t->server_id]),'intent'=>array_intersect_key(get_object_vars($unreadable?new Intent:OfferState::intent($t,$d)),array_flip(['sell','longest','transfers','stops','defaultTransfers','salesPaused'])),'defaults'=>$this->publicDefaults($d,$grants->costs),
                'unreadable'=>$unreadable,'own'=>$own,'pricing'=>!$own?'Default pricing':(($raw['pricing_rule']['mode']??$t->pricing_mode)==='fixed'?'Fixed price':'Custom markup'),'offers'=>$all,'facts'=>$facts];
        }
        return $out;
    }
    private function publicDefaults(array $d,bool $costs): array
    {unset($d['card_fee'],$d['pricing_rule']);if(!$costs){unset($d['maxAnnualCost']);}return $d;}
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
        if($unreadable){return 'custom_rule';}
        try{$own=Pricing::hasOwn($t);if(!$own&&$state['default']===null){return 'default_rule';}
            Pricing::rule($t,$state['default'],$state['fee']);return null;
        }catch(\DomainException|\InvalidArgumentException){return 'custom_rule';}
    }
    /** Omitted-price identities only: validate saved settings without loading every off-page offer and price. */
    public function omissions(Collection $tlds): array
    {
        abort_unless(Staff::requireView()->costs,403);$out=[];$state=self::pricingState();
        $accounts=Account::whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');
        foreach($tlds as $t){
            try{$d=OfferState::decode($accounts[$t->server_id]->catalogue_defaults);$raw=OfferState::decode($t->catalogue_intent);OfferState::intent($t,$d);$unreadable=false;}
            catch(\Throwable){$unreadable=true;}
            if(self::pricingCause($t,$state,$unreadable)!==null){$out[]=$t->server_id.':'.$t->tld;}
        }
        return $out;
    }
    /** The matrix/CSV contract uses the exact same page snapshots, chunked without an unbounded SQL IN. */
    public function matrix(Collection $tlds,int $years,string $filter='all'): array
    {
        $grants=Staff::requireView();abort_unless($grants->costs,403);$rows=[];$accounts=[];$skipped=[];$warnings=[];
        $state=self::pricingState();$fee=$state['fee'];$default=$state['default'];
        foreach($tlds->chunk(100) as $chunk){$page=$this->pageFor($chunk,$grants);$settings=[];
            foreach($chunk as $t){$s=$page[$t->id];$id=(int)$t->server_id;$own=$s['own'];
                if($filter==='default'&&$own||$filter==='own'&&!$own){continue;}
                if(!isset($settings[$id])){$a=Account::where('server_id',$id)->sole();$d=OfferState::decode($a->catalogue_defaults);$settings[$id]=[$d,Server::whereKey($id)->value('name')];}
                [$d,$name]=$settings[$id];$accounts[$id]=$name;
                $identity=['id'=>(int)$t->id,'tld'=>'.'.$t->tld,'server_id'=>$id,'account'=>$name,'years'=>$years,'pricing'=>$own?'Custom':'Default','currency'=>'USD'];
                if(($cause=self::pricingCause($t,$state,$s['unreadable']))!==null){
                    $skipped[]=[...$identity,'cause'=>$cause];$key=$cause==='custom_rule'?$id.':'.$t->tld:$cause;
                    $warnings[$key]??=['server_id'=>$id,'account'=>$name,'cause'=>$cause,'tlds'=>[]];
                    if(!in_array('.'.$t->tld,$warnings[$key]['tlds'],true)){$warnings[$key]['tlds'][]='.'. $t->tld;}
                    continue;
                }
                $rule=Pricing::rule($t,$default,$fee);
                $row=[...$identity,'prices'=>[],
                    'formula'=>'Price = '.PricingRule::describe($rule).($rule['mode']==='fixed'?', times years.':', per year, times years.'),'fee'=>$fee,'fee_in_price'=>$rule['mode']==='markup_fee_percent'];$below=$loss=false;
                foreach(PriceList::ACTIONS as $action=>$unused){$offer=$s['offers'][$action.':'.$years]??null;$cost=$offer?->observation()?->total;$price=$offer?->staffRetail();
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
