<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, PricingRule};

/** One local readiness projection for the checklist and reviewed Activate. The reader's readiness
 * callback checks deployment/runtime/credentials, independently of a saved Live activation receipt. */
final class OfferReadiness
{
    public static function routed(): ?self { return CallerRouting::resolve(self::class); }
    public function __construct(private readonly OfferReader $reader,private readonly Capabilities $capabilities,private readonly Clock $clock) {}
    public function stamp(Account $a): string { return StaffCommands::stamp((int)$a->server_id); }
    public function review(Account $a,array $features,bool $lock=false): array
    {
        if($lock){
            if(\Illuminate\Support\Facades\DB::transactionLevel()===0){throw new \LogicException('Readiness locks require a transaction.');}
            \App\Models\Server::whereKey($a->server_id)->lockForUpdate()->firstOrFail();
            $current=Account::where('server_id',$a->server_id)->lockForUpdate()->sole();
            CatalogueAcceptances::binding($a)->requireMatch(CatalogueAcceptances::binding($current));$a=$current;
        }
        $b=CatalogueAcceptances::binding($a);$costs=$failures=[];$counts=['registration'=>0,'transfer'=>0];
        $defaults=OfferState::decode($a->catalogue_defaults);
        Tld::where('server_id',$b->serverId)->where('account_fingerprint',$b->fingerprint)->where('catalogue_converted',true)
            ->orderBy('id')->chunkById(25,function($tlds)use($b,$features,$lock,$defaults,&$costs,&$failures,&$counts):void {
                $names=$tlds->pluck('tld')->all();$queries=[];
                foreach(['register','renew','transfer'] as $action){foreach($action==='register'?range(1,10):[1] as $term){$queries[]=new Query($b,$names,$action,$term);}}
                $loaded=null;
                if($lock){
                    // Lock the complete ordered page prefix before decoding any individual snapshot.
                    try{$loaded=$this->reader->loadBatch($queries,Context::Staff,true);}catch(\Throwable){}
                }
                $display=$lock?null:$this->reader->displayBatch([new Query($b,$names,'register')],Context::Staff)[0];
                if($lock){$tlds=Tld::whereKey($tlds->pluck('id'))->orderBy('id')->lockForUpdate()->get();}
                foreach($tlds as $i=>$t){
                    try {
                        $local=$loaded;
                        if($lock&&$local===null){
                            $rowQueries=array_map(fn($q)=>new Query($b,[$t->tld],$q->action,$q->term),$queries);
                            $local=$this->reader->loadBatch($rowQueries,Context::Staff,true);
                        }
                        $intent=OfferState::decode($t->catalogue_intent);
                        new Intent(...array_intersect_key($intent,array_flip(['sell','longest','transfers','stops'])));
                        $rows=[];
                        foreach($queries as $j=>$q){
                            $rows[$q->action.':'.$q->term]=$lock
                                ? $local[$j][$loaded===null?0:$i]
                                : ($display[$t->tld][$q->action.':'.$q->term]??null);
                        }
                    } catch (\Throwable) {
                        foreach(['registration'=>'register','transfer'=>'transfer','renewal'=>'renew'] as $feature=>$action){
                            if($features[$feature]??false){$failures[]=['tld'=>$t->tld,'action'=>$action,'term'=>1,'note'=>'Offer unreadable. Check the TLD settings.'];}
                        }
                        continue;
                    }
                    $sell=$intent['sell']??$defaults['defaultSell']??true;
                    foreach(['registration'=>'register','transfer'=>'transfer','renewal'=>'renew'] as $feature=>$action){
                        if(!($features[$feature]??false)){continue;}
                        if($action!=='renew'&&(!$sell||in_array($action,$intent['stops']??[],true)
                            ||$action==='transfer'&&!($intent['transfers']??$defaults['defaultTransfers']??false))){continue;}
                        $terms=$action==='register'?range(1,$intent['longest']??$defaults['defaultLongest']??1):[1];
                        foreach($terms as $term){$row=$rows[$action.':'.$term];
                            if($action!=='renew'&&in_array($row?->decision->reason,['seller_changed','unsupported_term'],true)){continue;}
                            try{
                                if(!$row){throw new \DomainException('Offer unreadable.');}
                                $observation=$row->observation();
                                if(!$observation?->enabled||!$observation->fresh($this->clock->now())){throw new \DomainException('Current cost missing.');}
                                if($action==='renew'){
                                    $this->capabilities->requireRenewal($b,$t->tld,$lock?$t:null);
                                    $retail=UpgradeEvidence::money($t->catalogue_retained_annual_retail,2);
                                    if($retail===null||PricingRule::minor($retail)<=0){throw new \DomainException('Annual retail missing.');}
                                    $renew=null;
                                }else{$p=$row->price();$retail=$p->retail;$renew=$rows['renew:1']->price();$counts[$feature]++;}
                                $costs[]=['tld'=>$t->tld,'action'=>$action,'term'=>$term,'cost'=>$observation->total,'price'=>$retail,
                                    'note'=>$row->decision->warning,'renewCost'=>$renew?->wholesale,'renewPrice'=>$renew?->retail];
                            }catch(\Throwable){$failures[]=['tld'=>$t->tld,'action'=>$action,'term'=>$term,'note'=>'Costs or prices Missing. Check the TLD price list and pricing rule; Sync prices if costs are missing.'];}
                        }
                    }
                }
            });
        $missing=$failures!==[];
        foreach($counts as $feature=>$count){$missing=$missing||($features[$feature]??false)&&$count===0;}
        return ['costs'=>$costs,'failures'=>$failures,'missing'=>$missing?'Costs and prices Missing. Choose what you sell, Sync prices, then check the enabled TLDs and registrar on the TLD list.':null];
    }
}
