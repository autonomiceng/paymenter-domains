<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Facts, Intent, OfferState, Pricing, Query, Retail, StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferTerms;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{StaffGrants,TldFacts,TldTerms};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

/** One staff table page of TLD snapshots over bound identities: prices, sales state, seller and refresh. */
final class Snapshots
{
    public function __construct(private readonly CallerPorts $ports,private readonly Clock $clock) {}
    public function load(Collection $tlds,StaffGrants $grants): array
    {
        if($tlds->count()>100){throw new \DomainException('Staff page exceeds 100 rows.');}
        if($tlds->isEmpty()){return [];}
        $accounts=Account::whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');$queries=[];
        foreach($tlds->groupBy('server_id') as $server=>$rows){$queries[]=new Query(AccountBindings::binding($accounts[$server]),$rows->pluck('tld')->all(),'register');}
        $loaded=$this->ports->staffBatch($queries);$offers=[];
        foreach($queries as $i=>$q){foreach($loaded[$i] as $name=>$offer){$offers[$q->binding->serverId.':'.$name]=$offer;}}
        $policies=DB::table('domains_sales_policies')->whereIn('tld',$tlds->pluck('tld'))->get()->keyBy('tld');
        $sellers=$policies->pluck('server_id','tld');
        $accountNames=[];
        $refresh=DB::table('domains_observation_queues')->whereIn('server_id',$tlds->pluck('server_id'))->get()->keyBy('server_id');
        $holders=Domain::whereIn('server_id',$tlds->pluck('server_id'))->whereIn('tld',$tlds->pluck('tld'))->whereNotNull('reservation_name')
            ->selectRaw('server_id,tld,account_fingerprint,count(*) as holders')->groupBy('server_id','tld','account_fingerprint')->get()->keyBy(fn($r)=>$r->server_id.':'.$r->tld.':'.$r->account_fingerprint);
        $out=[];$pricingOnce=[];$defaultsOnce=[];
        foreach($tlds as $t){
            [$d,$badDefaults]=$defaultsOnce[$t->server_id]??=self::accountDefaults($accounts[$t->server_id]);
            $out[$t->id]=$this->row($t,$d,$badDefaults,$offers[$t->server_id.':'.$t->tld]??[],$policies[$t->tld]??null,$sellers,
                $refresh[$t->server_id]??null,(int)($holders[$t->server_id.':'.$t->tld.':'.$t->account_fingerprint]->holders??0),$accounts[$t->server_id],$grants,$pricingOnce,$accountNames);
        }
        return $out;
    }
    private function row(Tld $t,array $d,bool $badDefaults,array $offers,?object $policy,Collection $sellers,?object $queue,int $holders,
        Account $account,StaffGrants $grants,array &$pricingOnce,array &$accountNames): array
    {
        ['raw'=>$raw,'own'=>$own,'unreadable'=>$unreadable,'invalidRule'=>$invalidRule,'ruleKind'=>$ruleKind,'holderRenewal'=>$holderRenewal]
            =self::rule($t,$d,$badDefaults,$pricingOnce);
        $all=$unreadable?[]:$offers;
        $prices=$this->prices($t,$all,$invalidRule,$holderRenewal,$grants);
        [$chosen,$reason,$sold,$facts,$stopped]=$this->sales($t,$all,$raw,$d,$policy,$unreadable);
        $selected=!$unreadable&&$policy!==null&&TldTerms::chosen($t,(array)$policy,$d);
        $chosen=$invalidRule?null:$chosen;
        [$label,$refreshed]=self::label($t,$reason,$stopped,$facts,$selected,$sellers,$unreadable,$prices,$queue,$policy,$accountNames);
        $error=['invalid_rule_kind'=>$ruleKind];
        return ['selected'=>(bool)$selected,'invalid_rule'=>$invalidRule,...$error,
            'refresh'=>$refreshed,
            'prices'=>$prices,'acquisition'=>$sold,'sales'=>['label'=>$chosen?'For sale':'Not for sale','tone'=>$chosen?'success':(!$invalidRule&&TldFacts::unsupported($label)?'warning':'gray'),'reason'=>$chosen?null:($invalidRule?TldFacts::ruleProblem($error):$label)],
            'checking_sales'=>!$chosen&&!$invalidRule&&$selected&&$label===TldFacts::TERMS_CHECKING,
            'seller'=>isset($sellers[$t->tld])?(int)$sellers[$t->tld]:null,'holders'=>$holders,
            'demo'=>\Paymenter\Extensions\Servers\Domains\Support\DemoData::is($account),'intent'=>array_intersect_key(get_object_vars($unreadable?new Intent:OfferState::intent($t,$badDefaults?[]:$d)),array_flip(['sell','longest','transfers','stops','defaultTransfers','salesPaused'])),'defaults'=>self::publicDefaults($d,$grants->costs),
            'unreadable'=>$unreadable,'own'=>$own,'pricing'=>!$own?'Default pricing':(($raw['pricing_rule']['mode']??$t->pricing_mode)==='fixed'?'Fixed price':'Custom markup'),'offers'=>$all,'facts'=>$facts];
    }
    /** The row's saved settings and whether its pricing rule can be used. */
    private static function rule(Tld $t,array $d,bool $badDefaults,array &$pricingOnce): array
    {
        $unreadable=false;
        try{
            $raw=OfferState::decode($t->catalogue_intent);
            OfferState::intent($t,$badDefaults?[]:$d);
            $own=Pricing::hasOwn($t);
        }catch(\Throwable){$raw=[];$own=!$t->use_default_rule;$unreadable=true;}
        $invalidRule=$unreadable;
        $ruleKind=$unreadable?'unreadable':(!$own?'default':match($raw['pricing_rule']['mode']??$t->pricing_mode){'fixed'=>'fixed','markup_amount','markup_percent','markup_fee_percent'=>'markup',default=>'pricing'});
        if(!$unreadable){try{StaffCatalogue::ruleOnce($t,$pricingOnce);}catch(\Throwable){$invalidRule=true;}}
        // Bad account defaults stop sales, but this TLD's own settings and its holders' renewal price stay readable.
        $holderRenewal=!$invalidRule&&$badDefaults?OfferState::money($t->catalogue_retained_annual_retail,2):null;
        if(!$invalidRule&&$badDefaults){$invalidRule=true;$ruleKind='account_defaults';}
        return compact('raw','own','unreadable','invalidRule','ruleKind','holderRenewal');
    }
    private function prices(Tld $t,array $all,bool $invalidRule,?string $holderRenewal,StaffGrants $grants): array
    {
        $prices=[];
        if($holderRenewal!==null&&!isset($all['renew:1'])){$prices[]=['action'=>'renew','term'=>1,'retail'=>TldFacts::money($holderRenewal),'flags'=>[],'synced'=>false,'observed'=>null,...($grants->costs?['cost'=>null]:[])];}
        $costs=StaffCatalogue::costs($all);
        foreach($all as $offer){$decision=$offer->decision;$cost=$offer->observation();$flags=[];
            if($decision->reason!==null){$flags[]=$decision->reason;}
            if($cost!==null&&!$cost->enabled){$flags[]='not_offered';}
            if(!$cost||$cost->total===null){$flags[]='cost_missing';}
            if(!$cost?->fresh($this->clock->now())){$flags[]='cost_old';}
            $retail=$invalidRule?null:$offer->staffRetail();
            if(!$invalidRule&&$decision->action==='renew'&&$decision->term===1){$retail=OfferState::money($t->catalogue_retained_annual_retail,2);}
            if($decision->action==='renew'&&$decision->term===1&&$holderRenewal!==null){$retail=$holderRenewal;}
            $prices[]=['action'=>$decision->action,'term'=>$decision->term,'retail'=>$retail===null?null:TldFacts::money($retail),
                'flags'=>$flags,'synced'=>$cost!==null,'observed'=>$cost?->observedAt,
                ...($grants->costs?['cost'=>$cost?->total===null?null:TldFacts::money(Retail::cost($cost,$costs),4)]:[])];
        }
        return $prices;
    }
    /** @return array{0:mixed,1:?string,2:array,3:?Facts,4:bool} the offer for sale, why not, what the storefront sells,
     * the saved terms, and whether staff stopped it */
    private function sales(Tld $t,array $all,array $raw,array $d,?object $policy,bool $unreadable): array
    {
        $register=collect($all)->first(fn($o)=>$o->decision->action==='register'&&$o->decision->available);
        $transfer=$all['transfer:1']??null;$chosen=$register??($transfer?->decision->available?$transfer:null);
        $transferOnly=$policy!==null&&TldTerms::matches($t,(array)$policy)&&!$policy->register_enabled&&$policy->transfer_enabled;
        $reason=($transferOnly||in_array('register',$raw['stops']??[],true)
            ?$transfer?->decision->reason:($all['register:1']->decision->reason??null))??'unknown_facts';
        // Staff can sell it, but the storefront can't: name what it waits for instead of saying For sale.
        $sold=StaffCatalogue::storefront($all);
        if($chosen!==null&&!in_array(true,$sold,true)){
            $renewal=$all['renew:1']??null;$chosen=null;
            $reason=($renewal?->decision->reason??'account_unavailable')==='account_unavailable'?'renewals_off':$renewal->decision->reason;
        }
        try{$facts=OfferState::facts($t->catalogue_facts);}catch(\Throwable){$facts=null;}
        // One state for the TLD list and its page: a stop that Start selling can't lift names the terms block, and
        // a TLD the account doesn't sell by default says so.
        // Without a staff stop, a terms reason means the TLD is set to sell and its head offers Stop selling: say so.
        $stopped=in_array($reason,StaffCatalogue::STARTS,true);
        if(!$unreadable&&$stopped){
            if(($block=StaffCatalogue::startFor($t,$raw,$d,$facts,$this->clock->now(),$policy)['block'])!==null){$reason=$block;}
            elseif($reason==='staff_stopped'&&($raw['sell']??null)===null&&!($d['defaultSell']??true)&&($raw['stops']??[])===[]
                &&!\Paymenter\Extensions\Servers\Domains\Services\EndingChoices::hidden($t->tld)){$reason='not_sold_by_default';}
        }
        return [$chosen,$reason,$sold,$facts,$stopped];
    }
    /** @return array{0:string,1:array} the sales status words and the row's price refresh */
    private static function label(Tld $t,?string $reason,bool $stopped,?Facts $facts,bool $selected,Collection $sellers,bool $unreadable,
        array $prices,?object $queue,?object $policy,array &$accountNames): array
    {
        $label=match($reason){'staff_stopped','staff_term_stopped','renewal_stopped'=>'Stopped by staff','not_sold_by_default'=>'Not sold by default','seller_changed'=>TldFacts::NO_SELLER,'publication_pending'=>'Prices not published','renewals_off'=>TldFacts::RENEWALS_OFF,'stale_price'=>'Cost out of date','below_cost'=>'Below cost','unknown_price'=>'Cost missing','unknown_facts','stale_facts'=>TldFacts::TERMS_CHECKING,'unsupported_facts'=>($stopped?'This':'Set to sell, but this').' registrar '.OfferTerms::unsupportedCause($facts,(string)$t->registrar_key,(string)$t->tld),'unsupported_term','provider_disabled'=>'Not offered by this registrar','unpublished_price'=>'Price missing','account_unavailable'=>'Check registrar account','cost_limit'=>'Over the cost limit',default=>'Check TLD settings'};
        if(!$selected&&!isset($sellers[$t->tld])){$label=TldFacts::NO_SELLER;}
        // Another account takes this TLD's new orders: name it, so each account's page for one TLD tells the same story.
        $other=!$unreadable&&isset($sellers[$t->tld])&&(int)$sellers[$t->tld]!==(int)$t->server_id?(int)$sellers[$t->tld]:null;
        $otherSeller=static function()use(&$accountNames,$other):string{return TldFacts::OTHER_SELLER.' '.($accountNames[$other]??=(string)(\App\Models\Server::whereKey($other)->value('name')?:'another registrar account'));};
        if($other!==null&&$reason==='seller_changed'){$label=$otherSeller();}
        if($queue&&$queue->account_fingerprint!==$t->account_fingerprint){$queue=null;}
        $work=$queue?OfferState::decode($queue->offer_work):[];
        // A terms read that failed says so, instead of checking with no end. The next try is in the row's refresh.
        if(!$unreadable&&in_array($reason,['unknown_facts','stale_facts'],true)&&$label===TldFacts::TERMS_CHECKING){
            $check=$work['facts'][$t->tld]??[];
            if(!empty($check['waiting_for_evidence'])){$label=TldFacts::TERMS_EVIDENCE_MISSING;}
            elseif(!empty($check['failure'])){$label=TldFacts::TERMS_FAILED;}
        }
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
        // This account can't take new orders anyway, so its own account check is not why the TLD isn't sold here.
        if($other!==null&&$label==='Check registrar account'){$label=$otherSeller();}
        return [$label,$refreshed];
    }
    /** @return array{0:array,1:bool} the account's saved defaults, and whether they fail their own checks.
     * Bad defaults are the account's problem, never a TLD pricing error. */
    private static function accountDefaults(Account $account): array
    {
        try{$d=OfferState::decode($account->catalogue_defaults);OfferState::intent((object)['catalogue_intent'=>null],$d);return [$d,false];}
        catch(\Throwable){return [isset($d)&&is_array($d)?$d:[],true];}
    }
    private static function publicDefaults(array $d,bool $costs): array
    {unset($d['card_fee'],$d['pricing_rule'],$d['_history']);if(!$costs){unset($d['maxAnnualCost']);}return $d;}
}
