<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Reviewed TEST terms for the owner calendar matrix only.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Cost,Facts,FactsObserved,FeedObserved,Query,TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;

// Publish the current two-year TEST approval after registration, keeping annual-only matrices separate.
$publishOwnerCalendarTerms=static function($binding)use($ownerCataloguePorts,$clock):void{
    $clock->instant=$clock->now()->modify('+1 second');$at=$clock->now();$until=new DateTimeImmutable('2028-11-05T00:00:00Z');
    $costs=[];
    foreach([1,2] as $years){
        $costs[]=new Cost('renew',$years,true,($years*8).'.0000',CostUnit::TermTotal,'synthetic_owner_calendar',$at,$until);
    }
    $facts=TermSupport::attach('com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,
        'synthetic_owner_calendar',$at,$until),TermSupport::evidence($binding,'com'));
    $query=new Query($binding,['com'],'renew');
    $revision=static fn()=>$ownerCataloguePorts->readOffers($query,Context::Staff)[0]->revision;
    $ownerCataloguePorts->apply(new FactsObserved($binding,'com',$facts),$revision());
    $ownerCataloguePorts->apply(new FeedObserved($binding,'com',$costs),$revision());
};
