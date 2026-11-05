<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The real queue, writer, native projection and staff rendering must agree on recovery.
// A rule-only unit fixture cannot prove that the account feed and sold facts still run.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Cost,Facts,FactsObserved,FeedObserved,OfferState,OfferWork,PricedFeed,Query,StaffCatalogue,StaffCommands};
use Paymenter\Extensions\Servers\Domains\Data\{CostUnit,RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,StaffSettingsReview as Review};

extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$com=Tld::findOrFail($tlds[0]->id);
$broken=$com->replicate();$intent=OfferState::decode($com->catalogue_intent);
unset($intent['pricing_rule']);$intent['sell']=false;
$broken->fill(['tld'=>'invalid','catalogue_intent'=>OfferState::json($intent),'catalogue_published_revision'=>null,
    'use_default_rule'=>false,'pricing_mode'=>'fixed','fixed_register'=>null,'fixed_renew'=>null,'fixed_transfer'=>null])->save();
$badIntent=$broken->catalogue_intent;
$savedPrice=$storefront->offer('com','register',1)['price'];
DB::table('domains_tlds')->where('id',$com->id)->update(['catalogue_facts'=>null,'catalogue_published_revision'=>null]);
$feed=new class($binding,$now->modify('+1 second')) implements PricedFeed {
    public int $priceCalls=0;public array $factCalls=[];
    public function __construct(private $binding,private DateTimeImmutable $now){}
    public function priceReads():int{return 1;}
    public function factsReads():int{return 1;}
    public function prices(RequestContext $context):iterable {
        $this->priceCalls++;
        // The bad row also occurs first in the provider buffer, not just local republication.
        foreach(['invalid','com'] as $name){$costs=[];
            foreach(['register'=>'6.0000','renew'=>'8.0000','transfer'=>'7.0000'] as $action=>$total){
                $costs[]=new Cost($action,1,true,$total,CostUnit::TermTotal,'synthetic',$this->now,$this->now->modify('+1 day'));
            }
            yield new FeedObserved($this->binding,$name,$costs);
        }
    }
    public function facts(string $tld,RequestContext $context):FactsObserved {
        $this->factCalls[]=$tld;
        return new FactsObserved($this->binding,$tld,new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],
            [],true,false,false,'synthetic',$this->now,$this->now->modify('+1 day')));
    }
};
$clock->instant=$now->modify('+1 second');
$work=new OfferWork($ports->writer(),$clock,new AccountReadBudget($clock,25),static fn()=>$feed,static fn()=>25);
$source=new StaffCatalogue($ports,$clock,$work,static fn()=>true);
$table=static function(string $name)use($binding,$source):array {
    $page=[];$table=new SellTable([$binding->serverId=>$binding],1,true,static function()use(&$page){return $page;},false,$source);
    $page=$table->rows($name)->get()->all();return [$table,$page[0]];
};
$work->sync($binding);
same('worked',$work->accountTurn($binding->serverId),'Unreadable unsold rule must not abort account work');
same(1,$feed->priceCalls,'The account feed runs despite the unreadable rule');
same(0,$work->status($binding)['remaining'],'The invalid feed row cannot block the durable buffer');
$isolatedState=static fn()=>[
    $broken->fresh()->catalogue_revision,
    count(array_filter(Review::historyRows(\App\Models\Server::findOrFail($binding->serverId)),static fn($row)=>($row['tld']??null)==='invalid')),
    StaffCommands::stamp($binding->serverId,$broken->id),
];
$isolatedBefore=$isolatedState();
same('worked',$work->accountTurn($binding->serverId),'Sold facts run on the following bounded turn');
[$revision,$audits,$stamp]=$isolatedState();
same($isolatedBefore[0],$revision,'Second worker turn must not bump the isolated offer revision');
same($isolatedBefore[1],$audits,'Second worker turn must not audit the isolated offer again');
same($isolatedBefore[2],$stamp,'Second worker turn must not invalidate the isolated offer review stamp');
same(['com'],$feed->factCalls,'Only the sold TLD receives automatic facts');
same('ready',$work->status($binding,'com')['state'],'Sold facts finish instead of staying Checking');
$accountStamp=StaffCommands::stamp($binding->serverId);
$work->accountTurn($binding->serverId);
same($accountStamp,StaffCommands::stamp($binding->serverId),'An idle pass leaves the account-wide staff review stamp stable too');
same($savedPrice,$storefront->offer('com','register',1)['price'],'Sold .com resumes at its saved price without a staff save');
[$sales,$row]=$table('com');same('For sale',$sales->status($row)['label'],'Healthy .com never remains stuck Checking');
same(false,$ports->readOffers(new Query($binding,['invalid'],'register'),Context::Public)[0]->available,'Unreadable .invalid stays closed');
same(null,$broken->fresh()->catalogue_published_revision,'Feed observations cannot publish unreadable custom pricing');
same($badIntent,$broken->fresh()->catalogue_intent,'The broken custom rule is not repaired or replaced');
[$sales,$row]=$table('invalid');same('Left out',$sales->cell($row,'register','price')['text'],'Unreadable custom pricing keeps its existing state');
same('Fix the pricing rule in Edit prices on .invalid.',$sales->status($row)['next'],'The repair points only to the broken TLD');
$matrix=$source->matrix(collect([$broken->fresh()]),1);
$html=view('domains::admin.sell-note',['warning'=>$matrix['warnings'][0]])->render();
expect(str_contains($html,"custom pricing can’t be read")||str_contains($html,"custom pricing can't be read"),'The existing staff omission reason remains visible');
echo "Unreadable custom pricing is isolated across local publication, feed, sold facts and staff state.\n";

// An overdue queued hint must not promise a check time that is already past.
DB::table('domains_tlds')->where('id',$com->id)->update(['catalogue_facts'=>null]);
$queue=DB::table('domains_observation_queues')->where('server_id',$binding->serverId);
$queued=OfferState::decode($queue->value('offer_work'));
$queued['facts']['com']['due_at']=$now->getTimestamp()-60;$queue->update(['offer_work'=>OfferState::json($queued)]);
[$sales,$row]=$table('com');same('Queued for the next background run.',$sales->status($row)['next'],'An overdue check shows the real queue state');

// Only custom-rule validation is contained: an account-wide writer error still stops the turn.
DB::table('domains_tlds')->where('id',$com->id)->increment('catalogue_revision');
$fault=new \Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,
    static fn()=>throw new InvalidArgumentException('Synthetic account-wide writer failure'),static fn()=>true);
$failed=new OfferWork($fault,$clock,new AccountReadBudget($clock,25),static fn()=>$feed,static fn()=>25);
$beforeCalls=$feed->priceCalls;$stopped=false;
try{$failed->accountTurn($binding->serverId);}catch(InvalidArgumentException $error){$stopped=$error->getMessage()==='Synthetic account-wide writer failure';}
expect($stopped,'An unrelated account error must propagate rather than being treated as unreadable own pricing');
same($beforeCalls,$feed->priceCalls,'An account-wide writer failure stops the feed');
same('retrying',$failed->status($binding,'com')['state'],'Account work failure replaces a stuck facts Checking state');
same('read_exception',$failed->status($binding,'com')['failure'],'Only a safe failure code is exposed');
same(null,$failed->status($binding,'com')['due_at'],'Failed work does not advertise an expired retry');
$failedSource=new StaffCatalogue($ports,$clock,$failed,static fn()=>true);
$recovery=$failedSource->nextSteps(collect([$com->fresh()]),[$binding->serverId.':com'=>$failedSource->page(collect([$com->fresh()]))[$com->id]],'register',1);
same('Check the registrar account and read prices again.',$recovery[$binding->serverId.':com']['next'],'An account failure points at the account, not another TLD’s Edit prices');
same('account',$recovery[$binding->serverId.':com']['open'],'Account failure links to its recovery page');
$work->accountTurn($binding->serverId);
same('ready',$work->status($binding,'com')['state'],'A later successful turn clears the account failure and restores facts');

// A broken shared default is an account-wide failure, even beside an unreadable override.
$shared=\Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::row(\Paymenter\Extensions\Servers\Domains\Services\PricingRules::KEY);
$shared->value=['mode'=>'invalid'];$shared->save();
DB::table('domains_tlds')->where('id',$com->id)->increment('catalogue_revision');
$stopped=false;
try{$work->accountTurn($binding->serverId);}catch(InvalidArgumentException){$stopped=true;}
expect($stopped,'Shared pricing validation must stop the account rather than being swallowed per offer');
$nativeSuccessful=true;
