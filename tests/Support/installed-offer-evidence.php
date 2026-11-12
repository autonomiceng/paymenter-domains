<?php
// Explicit independent synthetic evidence for installed financial fixtures; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Cost,Facts,FactsObserved,FeedObserved,Query};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
/** This is fixture evidence, never a claim that price replies prove renewal or registry support. */
function installedOfferEvidence($binding,$clock,bool $transfer=false,int $freshDays=1): Tld
{
    $ports=CallerPorts::routed();$q=new Query($binding,['com'],'register');
    $revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
    $at=$clock->now();$until=$at->modify('+'.$freshDays.' days');$costs=[];
    foreach(['register','renew',...($transfer?['transfer']:[])] as $action){$costs[]=new Cost($action,1,true,'10.0000',CostUnit::TermTotal,'synthetic',$at,$until);}
    $ports->apply(new FeedObserved($binding,'com',$costs),$revision());
    $ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>$transfer?[1]:[]],[],true,false,false,'synthetic',$at,$until)),$revision());
    return Tld::where('server_id',$binding->serverId)->where('tld','com')->sole();
}

/** Independent renewal-support premise for financial cases advancing to the following year. */
function installedRenewalFacts($binding,DateTimeImmutable $observedAt,DateTimeImmutable $until,string $cost='10.0000'): void
{
    $ports=CallerPorts::routed();$query=new Query($binding,['com'],'renew');
    $ports->apply(new FeedObserved($binding,'com',[new Cost('renew',1,true,$cost,CostUnit::TermTotal,'independent_synthetic_annual_cost',$observedAt,$until)]),
        $ports->readOffers($query,Context::Staff)[0]->revision);
    $ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[],'renew'=>[1],'transfer'=>[]],[],true,false,false,'independent_synthetic_annual_support',$observedAt,$until)),
        $ports->readOffers($query,Context::Staff)[0]->revision);
}

/** Only failed synthetic setup emits bounded local reasons; never changes sale guards. */
function installedOfferFailure($binding,$clock,array $actions): void
{
    $ports=\Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts::routed();
    foreach($actions as $action){
        $row=$ports->readOffers(new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],$action),\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Public)[0];
        fwrite(STDERR,json_encode(['fixture_offer'=>$action,'at'=>$clock->now()->format(DATE_ATOM),'available'=>$row->available,'reason'=>$row->reason],JSON_THROW_ON_ERROR)."\n");
    }
}
