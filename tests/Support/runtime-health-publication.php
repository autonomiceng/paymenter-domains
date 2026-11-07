<?php
// Real retained offer worker and unpublished synthetic offers. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts,FactsObserved,OfferState,OfferWork,PricedFeed,Projection,Writer};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Data\RequestContext;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use Illuminate\Support\Facades\DB;

// Arrange pending projection work directly: publishing through the Writer here would precede the tested fault.
$unpublished=static function(Tld $prototype,string $name)use($clock):Tld {
    $t=$prototype->replicate();$t->tld=$name;$t->catalogue_published_revision=null;
    $b=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
    $t->catalogue_facts=OfferState::encodeFacts(fixtureNameSiloFacts($b,$name,new Facts(
        ['register'=>$b->registrarKey==='enom'?[1,3]:[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$clock->now(),$clock->now()->modify('+1 day'))));
    $t->save();
    foreach(Price::where('server_id',$prototype->server_id)->where('tld',$prototype->tld)->get() as $price){$copy=$price->replicate();$copy->tld=$name;$copy->save();}
    $policy=SalesPolicy::where('tld',$prototype->tld)->sole()->replicate();$policy->tld=$name;
    $policy->server_id=$b->serverId;$policy->registrar_key=$b->registrarKey;$policy->account_fingerprint=$b->fingerprint;
    SalesPolicy::withPolicyWrite(static fn()=>$policy->save());
    return $t;
};
$feed=new class implements PricedFeed {
    public function priceReads():int{return 1;}
    public function factsReads():int{return 0;}
    public function prices(RequestContext $context):iterable{throw new LogicException('No provider I/O in local publication health.');}
    public function facts(string $tld,RequestContext $context):FactsObserved{throw new LogicException('No provider I/O in local publication health.');}
};
$publicationWorker=static fn()=>new OfferWork(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),
    $clock,$budget,static fn()=>$feed,static fn()=>0);
$cursor=static fn()=>(int)(OfferState::decode(DB::table('domains_observation_queues')->where('server_id',$server)->value('offer_work'))['local_cursor']??0);
// Enroll both queues while identity is readable; corruption below cannot silently remove pending work.
foreach($tlds as $t){$publicationWorker()->wake(AccountBindings::binding(Account::where('server_id',$t->server_id)->sole()));}
