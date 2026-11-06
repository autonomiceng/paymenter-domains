<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic reviewed terms for the owner calendar matrix only.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Cost,Facts,FactsObserved,FeedObserved,Query};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;

// This fixture tests the full ten-year horizon, independently of the production TEST approval's two-year scope.
// Store explicit reviewed facts and exact totals through the real catalogue writer; keep every admission gate.
$registrationPrepare=$prepare;$ownerCataloguePorts=$ports;
$prepare=static function(...$args)use($registrationPrepare,$ownerCataloguePorts):array{
    $result=$registrationPrepare(...$args);$binding=$result[1];
    $at=new DateTimeImmutable('2026-10-18T00:00:00Z');$until=new DateTimeImmutable('2028-11-05T00:00:00Z');
    $proof=['source'=>'synthetic_owner_calendar','version'=>'calendar-1','reviewed_at'=>'2026-10-01T00:00:00Z',
        'valid_until'=>$until->format(DATE_ATOM),'terms'=>range(1,10),'price_unit'=>'term_total','rule'=>'synthetic_exact_totals'];
    $terms=[];$costs=[];
    foreach(range(1,10) as $years){
        $terms[$years]=$proof;
        $costs[]=new Cost('renew',$years,true,($years*8).'.0000',CostUnit::TermTotal,'synthetic_owner_calendar',$at,$until);
    }
    $facts=new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic_owner_calendar',$at,$until,
        ['term_evidence'=>['format'=>1,'entries'=>['renew'=>$terms]],'renewal_families'=>['format'=>1,'entries'=>['ordinary'=>$proof+
            ['states'=>['Active'],'expiry_precision'=>'date','horizon_years'=>10,'safe_boundary'=>'before_confirmed_expiry']]]]);
    $query=new Query($binding,['com'],'renew');
    $revision=static fn()=>$ownerCataloguePorts->readOffers($query,Context::Staff)[0]->revision;
    $ownerCataloguePorts->apply(new FactsObserved($binding,'com',$facts),$revision());
    $ownerCataloguePorts->apply(new FeedObserved($binding,'com',$costs),$revision());
    return $result;
};
