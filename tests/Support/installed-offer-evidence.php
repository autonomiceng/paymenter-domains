<?php
// Explicit independent synthetic evidence for installed financial fixtures; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Cost,Facts,FactsObserved,FeedObserved,Query};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
/** This is fixture evidence, never a claim that price replies prove renewal or registry support. */
function installedOfferEvidence($binding,$clock,bool $transfer=false): Tld
{
    $ports=CallerPorts::routed();$q=new Query($binding,['com'],'register');
    $revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
    $at=$clock->now();$until=$at->modify('+1 day');$costs=[];
    foreach(['register','renew',...($transfer?['transfer']:[])] as $action){$costs[]=new Cost($action,1,true,'10.0000',CostUnit::TermTotal,'synthetic',$at,$until);}
    $ports->apply(new FeedObserved($binding,'com',$costs),$revision());
    $ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>$transfer?[1]:[]],[],true,false,false,'synthetic',$at,$until)),$revision());
    return Tld::where('server_id',$binding->serverId)->where('tld','com')->sole();
}

/** Independent renewal-support premise for financial cases advancing to the following year. */
function installedRenewalFacts($binding,DateTimeImmutable $observedAt,DateTimeImmutable $until): void
{
    $ports=CallerPorts::routed();$query=new Query($binding,['com'],'renew');
    $ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[],'renew'=>[1],'transfer'=>[]],[],true,false,false,'independent_synthetic_annual_support',$observedAt,$until)),
        $ports->readOffers($query,Context::Staff)[0]->revision);
}
