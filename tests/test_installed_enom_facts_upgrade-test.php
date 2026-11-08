<?php
// Native queue, admitted feed, writer and owner term evaluator must agree after upgrade.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,CallerRouting,Context,Edit,Facts,FactsObserved,OfferScheduler,OfferState,OfferWork,Query,TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Installation,Runtime};
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
    'domains.enom_identity.verify'=>true,'domains.enom_identity.daily_call_cap'=>1000]);
Runtime::register();app(Installation::class)->install();
$staff=staffUser(array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));Auth::setUser($staff);
$clock=new class implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public DateTimeImmutable $at;
    public function __construct(){ $this->at=new DateTimeImmutable('2026-11-06T12:00:00Z'); }
    public function now():DateTimeImmutable{return $this->at;}
};
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$server=Server::create(['name'=>'Synthetic upgrade TEST','type'=>'server','extension'=>'Domains','enabled'=>true]);
$b=app(AccountBindings::class)->configure($server->id,'enom',Environment::Test,['uid'=>'secret-uid','token'=>'secret-token']);
$GLOBALS['catalogueBulk']=static fn($years)=>['com'=>['10.00','8.95','10.00']];$GLOBALS['catalogueList']=['com'];
$ports=CallerPorts::routed();$scheduler=OfferScheduler::routed();$worker=CallerRouting::resolve(OfferWork::class);
$scheduler->sync($b);$worker->accountTurn($server->id);
$q=new Query($b,['com'],'register');$revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
$old=new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'enom_tld_facts',
    $clock->now()->modify('-1 day'),$clock->now()->modify('+29 days'));
$ports->apply(new FactsObserved($b,'com',$old),$revision());
$ports->apply(Edit::seller($b,'com',['register']),$revision());
// Reproduce the retained month-long queue entry from the demo, not a new cold facts request.
$queue=DB::table('domains_observation_queues')->where('server_id',$server->id);
$work=OfferState::decode((clone $queue)->value('offer_work'));
$work['facts']['com']=['sold_only'=>true,'due_at'=>$old->freshUntil->getTimestamp(),'succeeded_at'=>$old->observedAt->getTimestamp(),'tries'=>0];
$queue->update(['offer_work'=>OfferState::json($work)]);
same('17.9000',OfferState::cost(OfferState::decode(DB::table('domains_prices')->where('server_id',$server->id)->where('action','renew')->where('term',2)->value('catalogue_observation')))->total,'Fresh reviewed two-year cost already exists');
// Completion of retained provider work is independent of upgraded term authority.
$before=count($GLOBALS['catalogueCalls']);
same(true,$worker->settled($b,$clock->now()->modify('-1 second'),['com']),'Completed old facts settle without current expansion');
same($before,count($GLOBALS['catalogueCalls']),'Settlement is read-only and never calls the provider');
same(false,$worker->settled($b,$clock->now(),['com']),'Same-second completion cannot prove a prior attempt');
$broken=$work;$broken['facts']['com']['failure']='provider_transport_failed';
$queue->update(['offer_work'=>OfferState::json($broken)]);
same(false,$worker->settled($b,$clock->now()->modify('-1 second'),['com']),'Failed facts cannot settle an interrupted refresh');
$queue->update(['offer_work'=>OfferState::json($work)]);
$scheduler->requestFacts($b,'com');
same($clock->now()->getTimestamp(),$scheduler->status($b,'com')['due_at'],'New reviewed authority wakes retained fresh facts now');
$before=count($GLOBALS['catalogueCalls']);$worker->accountTurn($server->id);
$facts=OfferState::facts(Tld::where('server_id',$server->id)->where('tld','com')->sole()->catalogue_facts);
same(2,count($GLOBALS['catalogueCalls'])-$before,'Normal refresh rereads real independent facts once');
same($clock->now()->format(DATE_ATOM),$facts->observedAt->format(DATE_ATOM),'Provider refresh replaces the pre-upgrade observation');
same(TermSupport::REVIEWED_EVIDENCE['enom']['com'],$facts->expansion,'Stored facts carry current reviewed evidence');
$costs=[];foreach(DB::table('domains_prices')->where('server_id',$server->id)->get() as $p){$c=OfferState::cost(OfferState::decode($p->catalogue_observation));$costs[$c->key()]=$c;}
same(true,TermSupport::renewal('enom','com',$facts,$costs,$clock->now()->modify('+1 year'),$clock->now(),environment:Environment::Test)[2]['available'],'Owner evaluator offers two years from refreshed facts');
same(false,TermSupport::renewal('enom','com',$facts,$costs,$clock->now()->modify('+1 year'),$clock->now(),environment:Environment::Live)[2]['available'],'Live longer terms remain held');
$before=count($GLOBALS['catalogueCalls']);$scheduler->requestFacts($b,'com');$worker->accountTurn($server->id);
same($before,count($GLOBALS['catalogueCalls']),'Current evidence retains its normal freshness without repeated reads');
// Customer and staff consumers reject a changed approval before refresh, including an unsold TLD.
$caps = new \Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities($ports, $clock, static fn() => null);
$t = Tld::where('server_id', $server->id)->where('tld', 'com')->sole();
$original = $t->catalogue_facts; $intent = $t->catalogue_intent;
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_intent' => OfferState::json(['sell' => false])]);
$prices = DB::table('domains_prices')->where('server_id', $server->id)->get();
$before = count($GLOBALS['catalogueCalls']);
foreach (['term_evidence', 'renewal_families'] as $key) {
    $changed = OfferState::decode($original);
    if ($key === 'term_evidence') { $changed[$key]['entries']['renew'][2]['version'] = 'withdrawn-approval'; }
    else { $changed[$key]['entries']['ordinary']['valid_until'] = '2028-11-05T00:00:00+00:00'; }
    DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::json($changed)]);
    $terms = $caps->renewalTerms($b, $t->fresh(), $prices, $clock->now()->modify('+1 year'));
    same([false, 'unknown_facts'], [$terms[2]['available'], $terms[2]['reason']], 'Customer rejects superseded approval before facts refresh: '.$key);
    same(true, $terms[1]['available'], 'Superseded longer approval preserves annual renewal');
    $readiness = $caps->termReadiness($b, 'com');
    same([false, 'unknown_facts'], [$readiness['renew'][2]['available'], $readiness['renew'][2]['reason']], 'Staff rejects superseded approval before facts refresh: '.$key);
    same('annual_only', $readiness['families']['ordinary']['state'], 'Superseded family cannot appear reviewed');
}
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => $original, 'catalogue_intent' => $intent]);
same(true, $caps->renewalTerms($b, $t->fresh(), $prices, $clock->now()->modify('+1 year'))[2]['available'], 'Current approval restores customer longer terms');
same(true, $caps->termReadiness($b, 'com')['renew'][2]['available'], 'Current approval restores staff longer terms');
same($before, count($GLOBALS['catalogueCalls']), 'Approval validation performs no provider reads');
// A changed reviewed version is stale too; repeated normal hints must preserve a failed read's backoff.
$stale=OfferState::decode(Tld::where('server_id',$server->id)->where('tld','com')->sole()->catalogue_facts);
$stale['term_evidence']['entries']['renew'][2]['version']='previous-reviewed-version';
DB::table('domains_tlds')->where('server_id',$server->id)->where('tld','com')->update(['catalogue_facts'=>OfferState::json($stale)]);
// Reproduce a previously successful queue row from the old approval as well.
$work=OfferState::decode((clone $queue)->value('offer_work'));unset($work['facts']['com']['authority']);
$queue->update(['offer_work'=>OfferState::json($work)]);
$scheduler->sync($b);
$wire=$GLOBALS['catalogueWire'];$GLOBALS['catalogueWire']=static fn($h,$o,$f)=>$f['Command']==='GetTLDDetails'?false:$wire($h,$o,$f);
$worker->accountTurn($server->id);$worker->accountTurn($server->id);$failed=$scheduler->status($b,'com');
expect($failed['due_at']>$clock->now()->getTimestamp(),'Failed authority refresh backs off');
$scheduler->requestFacts($b,'com');same($failed['due_at'],$scheduler->status($b,'com')['due_at'],'Repeated authority hint does not reset failure backoff');
$GLOBALS['catalogueWire']=$wire;
echo "Retained Enom facts upgrade: ok\n";$nativeSuccessful=true;
