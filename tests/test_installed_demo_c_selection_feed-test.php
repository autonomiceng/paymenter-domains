<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,FactsObserved,FeedObserved,Intent,OfferWork,PricedFeed,Query};
use Paymenter\Extensions\Servers\Domains\Data\RequestContext;
use Paymenter\Extensions\Servers\Domains\Models\{Account,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget};

// Native writer, queue and staff projection must agree; only the feed and clock are synthetic.
// Set the clock before creating rows; no selection or TLD timestamps are patched by this test.
pinStaffClock(new DateTimeImmutable('2026-10-18T12:00:00Z'));
extract(commerceFixture());tldPanel();Auth::setUser(staffUser(array_keys(Staff::permissions())));
$b=AccountBindings::binding(Account::where('server_id',$tlds[0]->server_id)->sole());
$writer=$ports->writer();$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;echo 'FAIL '.$message."\n";}};
$stamp=static fn(string $tld)=>SalesPolicy::where('tld',$tld)->sole();
$apply=static function($command)use($writer):void{
    $revision=$writer->readOffers(new Query($command->binding(),[$command->tld()],'register'),Context::Staff)[0]->revision;
    $writer->apply($command,$revision);
};
$created=$stamp('com')->created_at;
$check($created?->getTimestamp()===$now->getTimestamp(),'Writer insert records creation time');
$clock->instant=$now->modify('+1 minute');
$apply(Edit::seller($b,'com',['register']));
$check($stamp('com')->updated_at?->getTimestamp()===$clock->now()->getTimestamp(),'Writer seller update records selection time');
$clock->instant=$now->modify('+2 minutes');
$apply(Edit::override($b,'com',new Intent(sell:true,longest:3,transfers:true),sellerTransfers:true));
$check($stamp('com')->transfer_enabled&&$stamp('com')->updated_at?->getTimestamp()===$clock->now()->getTimestamp(),'Transfers On records selection time');
$check($stamp('com')->created_at==$created,'Selection updates preserve creation time');
$clock->instant=$now->modify('+3 minutes');
$apply(new FeedObserved($b,'absent',[]));
$apply(Edit::override($b,'absent',new Intent(sell:true,longest:1)));
$apply(Edit::seller($b,'absent',['register']));
$t=Tld::where('server_id',$b->serverId)->where('tld','absent')->sole();
$check(TldFacts::snapshot($t)['selected'],'Missing ending was selected through writer');
$check(str_contains(TldFacts::priceState(TldFacts::snapshot($t))['cause'],'Price feed not read yet:'),'Never-read feed stays unread');
$clock->instant=$now->modify('+4 minutes');
$feed=new class implements PricedFeed {
    public int $reads=0;
    public function priceReads():int{return 1;}
    public function factsReads():int{return 0;}
    public function prices(RequestContext $context):iterable{$this->reads++;return [];}
    public function facts(string $tld,RequestContext $context):FactsObserved{throw new LogicException('No facts reader');}
};
$worker=new OfferWork($writer,$clock,new AccountReadBudget($clock,25),static fn()=>$feed,static fn()=>25);
$worker->sync($b);$worker->accountTurn($b->serverId);
$check($feed->reads===1&&$worker->status($b)['succeeded_at']===$clock->now()->getTimestamp(),'Empty account feed actually succeeds through worker');
$fact=TldFacts::snapshot($t->fresh());$message="Not in the registrar's price feed; not for sale";
$check(str_starts_with(TldFacts::priceState($fact)['cause'],$message),'Writer selection followed by successful omitted feed shows feed gap');
$check($fact['sales']['reason']===$message,'Sales status agrees with price cell');
$check(TldFacts::priceState($fact)['tone']==='danger','Selected missing feed price stays red');
expect($failures===[],implode("\n",$failures));$nativeSuccessful=true;echo "Writer selection and successful omitted-feed regression passed.\n";
