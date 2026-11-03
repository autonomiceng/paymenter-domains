<?php
// Native rows, encrypted acceptance and permanent products need the real database fixture; no provider I/O.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Currency,Product,Server};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,SalesPolicy,SellRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,CatalogueSync,EnomTestSendLimit,NativeCatalogue,PriceFill,PriceSync,SellAutomation,SellRule,StaffSettingsReview as Review,StaffSettingsWrites,Storefront};
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,
    'domains.enom_test_sends_per_day'=>10000,'domains.enom_test_sends_per_minute'=>100000]);
// Prepare retained C6 catalogue behavior before exercising the extension lifecycle.
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic automatic sales','type'=>'server','extension'=>'Domains','enabled'=>true]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction(setupAction('configure',$server->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token','reason'=>'Connect synthetic account'])
    ->callMountedAction()->assertHasNoActionErrors();
$account=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($account);
$write=static function(string $action,array $data)use($staff,$server):void {
    $r=new Review($staff->id,$server->id,null,$action,Review::ticket($staff->id,$server->id,null,$action,Review::stamp($server->id,null)),'Synthetic selling review');
    app(StaffSettingsWrites::class)->run($r,$data);
};
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'20.00','years'=>1,'transfers'=>false]]]);
$clock=new class implements Clock {
    public \DateTimeImmutable $at;
    public function __construct(){$this->at=new \DateTimeImmutable;}
    public function now(): \DateTimeImmutable{return $this->at;}
};
app()->instance(Clock::class,$clock);
$GLOBALS['catalogueList']=['com','org'];$GLOBALS['catalogueBulk']=static fn()=>['com'=>['10.00','10.00','10.00'],'org'=>['10.00','10.00','10.00']];
$stamp=SellRule::stamp($server->id);
app(PriceFill::class)->run($account);
same($stamp,SellRule::stamp($server->id),'A price fill keeps an open rule form usable');
same(0,Product::where('server_id',$server->id)->count(),'An account pause permits no automatic publication');
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>true]));
$sync=static function()use($account,$clock):array {$clock->at=$clock->at->modify('+2 minutes');return app(PriceSync::class)->run($account->fresh(),$clock,null);};
$publish=static function()use($server):void {foreach(Tld::where('server_id',$server->id)->get() as $t){app(NativeCatalogue::class)->keepCurrent($t,app(Clock::class)->now(),true);}};
$sync();$publish();
same(['com','org'],array_column(app(Storefront::class)->endings(),'tld'),'New priced TLDs sell without a staff publish or seller click');
same(0,count(array_filter(Review::historyRows($server),static fn($e)=>isset($e['before']['retail']))),'A first import adds no retail-change history');
$failures=[];
$regression=static function(bool $ok,string $name)use(&$failures):void {
    echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok){$failures[]=$name;}
};
// Real audit refusal must not stop independent reads or relabel a committed fill as failed.
$clock->at=$clock->at->modify('+2 minutes');
SellRow::create(['server_id'=>$server->id,'account_fingerprint'=>$binding->fingerprint,'tld'=>'net','register_cost'=>'10.0000','renew_cost'=>'10.0000',
    'high_cost'=>'10.0000','prices'=>[1=>['register'=>'10.0000','renew'=>'10.0000']],'read_at'=>$clock->now()->getTimestamp()]);
$audit=$server->settings()->where('key',Review::KEY)->sole();$audit->encrypted=false;$audit->save();
try{
    $GLOBALS['catalogueList']=['com','org','net'];
    DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$server->id],['account_fingerprint'=>$binding->fingerprint,'catalogue_due'=>0,'catalogue_cursor'=>0]);
    $refused=false;try{app(PriceSync::class)->daily($account->fresh());}catch(\Throwable){$refused=true;}
    $regression(!$refused&&Price::where(['server_id'=>$server->id,'tld'=>'com','action'=>'register','term'=>1])->sole()->observed_at->getTimestamp()===$clock->now()->getTimestamp()
        &&!isset(CatalogueAcceptances::load($binding)->endings['net']),'A refused rule refresh still reads current costs without accepting the refused TLD');
    $clock->at=$clock->at->modify('+1 day');
    $GLOBALS['catalogueBulk']=static fn()=>['com'=>['10.00','10.00','10.00'],'org'=>['10.00','10.00','10.00'],'net'=>['10.00','10.00','10.00']];
    app(PriceFill::class)->daily($account->fresh());$filled=PriceFill::last($binding);
    $regression((int)$filled->filled_at===$clock->now()->getTimestamp()&&$filled->failed_at===null&&$filled->failure===null
        &&SellRule::rows($binding)->where('tld','net')->sole()->read_at===$clock->now()->getTimestamp()
        &&!isset(CatalogueAcceptances::load($binding)->endings['net']),'A saved price fill stays successful when its rule refresh refuses');
}finally{
    $audit->encrypted=true;$audit->save();SellRule::rows($binding)->where('tld','net')->delete();
    $GLOBALS['catalogueList']=['com','org'];$GLOBALS['catalogueBulk']=static fn()=>['com'=>['10.00','10.00','10.00'],'org'=>['10.00','10.00','10.00']];
}
$sync();$publish();
// Explicitly clearing the seller is a staff decision, not an invitation to auto-select again.
$com=Tld::where(['server_id'=>$server->id,'tld'=>'com'])->sole();$policy=SalesPolicy::where('tld','com')->sole();
$staffWrite=static function(string $action,array $data)use($staff,$server,$com):void {
    $r=new Review($staff->id,$server->id,$com->id,$action,Review::ticket($staff->id,$server->id,$com->id,$action,Review::stamp($server->id,$com->id)),'Keep the staff sales decision');
    app(StaffSettingsWrites::class)->run($r,$data);
};
$staffWrite('seller',['seller'=>'','register'=>false,'transfer'=>false]);$publish();
$regression($policy->fresh()->server_id===null&&array_column(app(Storefront::class)->endings(),'tld')===['org'],'An explicitly cleared seller stays empty and cannot sell');
$staffWrite('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false]);
Domain::create(['server_id'=>$server->id,'registrar_key'=>'enom','account_fingerprint'=>$binding->fingerprint,'user_id'=>900,'service_id'=>900,
    'domain'=>'example.com','reservation_name'=>'example.com','sld'=>'example','tld'=>'com','status'=>'active']);
// A staff registration stop survives both the ordinary price read and publication tick.
$flags=['min_years'=>1,'max_years'=>1,'enabled'=>true,'renew'=>true,'register'=>false,'transfer'=>false];
$staffWrite('tld_settings',$flags);$publish();same(false,$com->fresh()->register_enabled,'A registration stop holds across a sweep before sync');$sync();$publish();
$regression(!$com->fresh()->register_enabled&&SellRule::rows($binding)->where('tld','com')->sole()->choice==='skip'
    &&array_column(app(Storefront::class)->endings(),'tld')===['org'],'A staff registration stop survives automation');
same(true,$com->fresh()->renew_enabled,'A registration stop retains holder renewals');
same([1],CatalogueAcceptances::load($binding)->endings['com']['renew'],'A registration stop retains holder renewal acceptance');
if(!$com->fresh()->register_enabled){
    $clock->at=$clock->at->modify('+3 days');app(PriceFill::class)->run($account->fresh());
    $staffWrite('tld_settings',[...$flags,'register'=>true,'verified'=>true]);
    expect(!in_array('com',array_column(app(Storefront::class)->endings(),'tld'),true),'Clearing an old stop still needs new publication evidence');
    $sync();$publish();
    same(true,$com->fresh()->register_enabled,'Staff can clear the stop and let automation restore registrations');
    same(null,SellRule::rows($binding)->where('tld','com')->sole()->choice,'Turning registrations on clears the row stop');
}
// Only these must resume explicit membership; a legacy All save must keep its other rows selling.
$write('acceptance',['sell'=>['rule'=>['mode'=>'only','max_cost'=>'20.00','years'=>1,'transfers'=>false],'choices'=>['com'=>'sell','org'=>'sell']]]);
$staffWrite('tld_settings',$flags);$publish();same(false,$com->fresh()->register_enabled,'Only these keeps a registration stop before sync');$sync();$publish();
$staffWrite('tld_settings',[...$flags,'register'=>true,'verified'=>true]);$sync();$publish();
same('sell',SellRule::rows($binding)->where('tld','com')->sole()->choice,'Restoring Only these retains explicit membership');
same(['com','org'],array_column(app(Storefront::class)->endings(),'tld'),'Only these restores a staff-cleared stop');
$write('acceptance',['sell'=>['choices'=>['org'=>null]]]);$publish();same(['com'],array_column(app(Storefront::class)->endings(),'tld'),'Only these keeps an unselected row off before sync');
$write('acceptance',['sell'=>['choices'=>['org'=>'sell']]]);$publish();
$server->settings()->where('key',SellRule::KEY)->firstOrFail()->update(['value'=>['mode'=>'all','max_cost'=>'20.0000','years'=>1,'transfers'=>false]]);
SellRule::rows($binding)->where('tld','org')->update(['choice'=>'skip']);
$staffWrite('tld_settings',$flags);$publish();same(false,$com->fresh()->register_enabled,'Legacy All keeps a registration stop before sync');$sync();$publish();
same(['org'],array_column(app(Storefront::class)->endings(),'tld'),'A new stop normalizes legacy All without stopping its other rows');
$staffWrite('tld_settings',[...$flags,'register'=>true,'verified'=>true]);$sync();$publish();
// A row transfer stop must hold even while old transfer acceptance and prices still exist.
config(['domains.enom_writes.transfer'=>true]);$write('test_transfer',['accept'=>true,'verified'=>true]);
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'20.00','years'=>1,'transfers'=>true]]]);$sync();$publish();
$staffWrite('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>true]);
$staffWrite('tld_settings',[...$flags,'register'=>true]);$publish();
$regression(!$com->fresh()->transfer_enabled,'A staff transfer stop survives a sweep without sync');
if(!$com->fresh()->transfer_enabled){
    $staffWrite('tld_settings',[...$flags,'register'=>true,'transfer'=>true,'verified'=>true]);$publish();
    same(true,$com->fresh()->transfer_enabled,'Staff can clear the transfer stop without a sync');
    same(null,SellRule::rows($binding)->where('tld','com')->sole()->transfer,'Restoring transfers clears the row stop');
}
$write('test_transfer',['accept'=>false]);$publish();same(false,$com->fresh()->transfer_enabled,'The account transfer switch holds across a sweep without sync');
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'20.00','years'=>1,'transfers'=>false]]]);$publish();
$staffWrite('seller',['seller'=>(string)$server->id,'register'=>false,'transfer'=>false]);$publish();same([],array_values(array_filter(app(Storefront::class)->endings(),static fn($e)=>$e['tld']==='com')),'Seller action stops hold before sync');
$staffWrite('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false]);config(['domains.enom_writes.transfer'=>false]);
// An over-limit bulk row can still sell until acceptance is narrowed; show the actual state.
SellRow::where(['server_id'=>$server->id,'tld'=>'com'])->update(['high_cost'=>'30.0000']);
\Carbon\Carbon::setTestNow($clock->now());
Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class,['account'=>$server->id])
    ->assertTableColumnStateSet('status','For sale',SellRow::where(['server_id'=>$server->id,'tld'=>'com'])->sole())->assertSee('Over the cost limit');
\Carbon\Carbon::setTestNow();
SellRow::where(['server_id'=>$server->id,'tld'=>'com'])->update(['high_cost'=>'10.0000']);
$products=Product::where('server_id',$server->id)->pluck('id')->all();
$history=Review::historyRows($server);$publish();same($history,Review::historyRows($server),'An unchanged sweep adds no audit or product writes');
same($stamp===SellRule::stamp($server->id),false,'Turning orders on invalidates an open rule form');
// A price over the rule closes acquisition. When it falls, the same products sell again.
SellRow::where('server_id',$server->id)->where('tld','com')->update(['high_cost'=>'30.0000']);
$sync();$publish();same(['org'],array_column(app(Storefront::class)->endings(),'tld'),'A cost rise closes new sales');
same([1],CatalogueAcceptances::load($binding)->endings['com']['renew'],'A cost withdrawal retains holder renewals');
SellRow::where('server_id',$server->id)->where('tld','com')->update(['high_cost'=>'10.0000']);
$sync();$publish();same(['com','org'],array_column(app(Storefront::class)->endings(),'tld'),'A cost fall restores sales');
same($products,Product::where('server_id',$server->id)->pluck('id')->all(),'Restoration retains native identities');
// A newer detailed quote over the cap wins over the bulk table, and recovers on the next good quote.
$GLOBALS['cataloguePrice']='30.0000';$sync();$publish();same([],app(Storefront::class)->endings(),'Newer detailed costs over the cap cannot sell');
$GLOBALS['cataloguePrice']='10.0000';$sync();$publish();same(['com','org'],array_column(app(Storefront::class)->endings(),'tld'),'A safe detailed quote recovers without staff');
// Each changed TLD gets one retail history entry; an unchanged repeat read adds none.
$auditKeys=array_column(Review::historyRows($server),'key');
$GLOBALS['cataloguePrice']='11.0000';$sync();$publish();
$changed=array_values(array_filter(Review::historyRows($server),static fn($e)=>!in_array($e['key'],$auditKeys,true)&&$e['actor_id']===null&&isset($e['before']['retail'])));
$entry=collect($changed)->firstWhere('tld_id',$com->id);
$regression(count($changed)===2&&count(array_unique(array_column($changed,'tld_id')))===2&&($entry['before']['retail']['register:1:USD']??null)==='11.99000000'
    &&($entry['after']['retail']['register:1:USD']??null)==='12.99000000'
    &&Price::where(['server_id'=>$server->id,'tld'=>'com','action'=>'register','term'=>1])->sole()->retail==='12.99000000',
    'Automatic retail changes have one history entry per TLD with the old and new prices');
$history=Review::historyRows($server);$sync();$publish();same($history,Review::historyRows($server),'An unchanged repeat price read adds no history');
$GLOBALS['cataloguePrice']='10.0000';$sync();$publish();
// An explicit missing renewal cost closes sales immediately and preserves the holder's renewal agreement.
SellRow::where('server_id',$server->id)->where('tld','com')->update(['renew_cost'=>null,'high_cost'=>null]);
SellAutomation::accept($account->fresh(),$clock->now());same([],CatalogueAcceptances::load($binding)->endings['com']['register'],'A missing renewal cost cannot leave new sales on');
same([1],CatalogueAcceptances::load($binding)->endings['com']['renew'],'Missing costs retain holder renewal authority');
SellRow::where('server_id',$server->id)->where('tld','com')->update(['renew_cost'=>'10.0000','high_cost'=>'10.0000']);$sync();$publish();
// A cap save must close the checkout window before any sync, even while TEST processing is paused.
$rates=[];
foreach([['register',1,'10.0000'],['register',2,'30.0000'],['register',3,'10.0000'],['renew',1,'10.0000']] as [$action,$term,$cost]){
    $rates[]=new Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action,$term,$cost,'offline_fixture',$clock->now(),$clock->now()->modify('+1 day'));
}
$sellers=app(Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class);
foreach([true,false] as $processing){
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'40.00','years'=>1,'transfers'=>false],'terms'=>['com'=>['longest'=>3]]]]);
app(Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::class)->import(
    new Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($binding,'com',$rates,[1,2,3],true,true),CatalogueAcceptances::load($binding),
    fn($s,$a)=>app(AccountBindings::class)->requireReadableLocked($s,$a),$clock->now(),null,true);
$publish();
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>$processing]));
$oldSelection=$sellers->selection('com','register',2,$clock->now());
same(1,count(DB::transaction(fn()=>$sellers->admit([$oldSelection],$clock->now()))),'The accepted term admits before the lower cap, including paused TEST preview');
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'20.00','years'=>1,'transfers'=>false]]]);
$publish();
$admitted=true;try{DB::transaction(fn()=>$sellers->admit([$oldSelection],$clock->now()));}catch(\DomainException){$admitted=false;}
$public=collect(app(Storefront::class)->endings())->firstWhere('tld','com');
$regression(!$admitted&&CatalogueAcceptances::load($binding)->endings['com']['register']===[1,3]&&$public['terms']===[1,3],
    'A saved cap immediately removes an expensive term while '.($processing?'running':'paused'));
same(1,count(DB::transaction(fn()=>$sellers->admit([$sellers->selection('com','register',1,$clock->now())],$clock->now()))),'A safe term still admits');
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>true]));
}
// Scheduled detail reads keep costs outside staff's bounds without granting those terms publication proof.
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'40.00','years'=>1,'transfers'=>false]]]);
$GLOBALS['catalogueMaximum']['com']=3;$sync();$publish();
$staffWrite('tld_settings',[...$flags,'register'=>true]);$publish();
$read=true;try{$sync();}catch(\DomainException){$read=false;}
$third=Price::where(['server_id'=>$server->id,'tld'=>'com','action'=>'register','term'=>3])->sole();
$selected=true;try{$sellers->selection('com','register',3,$clock->now());}catch(\DomainException){$selected=false;}
$regression($read&&!isset($third->eligibility['catalogue_revision'])&&!$selected&&(int)$com->fresh()->max_years===1,
    'A scheduled import keeps out-of-bounds terms cost-only and unselectable');
unset($GLOBALS['catalogueMaximum']['com']);
$write('acceptance',['sell'=>['rule'=>['mode'=>'except','max_cost'=>'20.00','years'=>1,'transfers'=>false],'terms'=>['com'=>['longest'=>1]]]]);$sync();$publish();
// An ineligible bulk row must retain the old audited refresh of existing native products.
$com=Tld::where(['server_id'=>$server->id,'tld'=>'com'])->sole();
$price=Price::where(['server_id'=>$server->id,'tld'=>'com','action'=>'register','term'=>1])->sole();
$price->update(['eligibility'=>[...$price->eligibility,'rule_hash'=>'outdated'],'revision'=>$price->revision+1]);
SellRow::where('server_id',$server->id)->where('tld','com')->update(['read_at'=>$clock->now()->getTimestamp()-172801]);
$before=Review::historyRows($server);$publish();
expect(Review::historyRows($server)!==$before,'Existing product refresh remains audited when bulk costs are stale');
same(false,app(Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::class)->behindRule($com,$clock->now()),'Existing product refresh is retained');
SellRow::where('server_id',$server->id)->where('tld','com')->update(['read_at'=>$clock->now()->getTimestamp()]);
// A different seller, a staff stop and an account pause remain authoritative.
$policy=SalesPolicy::where('tld','com')->sole();
SalesPolicy::withPolicyWrite(fn()=>$policy->update(['server_id'=>999,'revision'=>$policy->revision+1]));
$publish();same(999,$policy->fresh()->server_id,'Automation never replaces another seller');
$org=Tld::where(['server_id'=>$server->id,'tld'=>'org'])->sole();$org->update(['renew_enabled'=>false]);$publish();same(false,$org->fresh()->renew_enabled,'A renewal stop holds before sync');
$org->update(['enabled'=>false,'renew_enabled'=>true]);$publish();same(false,$org->fresh()->enabled,'A staff TLD stop survives');
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>false]));
$GLOBALS['catalogueBulk']=static fn()=>['com'=>['10.00','10.00','10.00'],'org'=>['10.00','10.00','10.00'],'net'=>['10.00','10.00','10.00']];
app(PriceFill::class)->run($account->fresh());$publish();
expect(!isset(CatalogueAcceptances::load($binding)->endings['net']),'A paused account does not accept new sales');
$entries=Review::historyRows($server); // The same staff history must include system writes.
expect(count(array_filter($entries,static fn($e)=>$e['actor_id']===null))>0,'Automatic changes are audited');
// A list larger than the remaining allowance still makes progress, with no list read on continuation.
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>true]));
$more=array_map(static fn($c)=>'s'.$c,range('a','l'));
$GLOBALS['catalogueBulk']=static fn()=>array_fill_keys(['com','org',...$more],['10.00','10.00','10.00']);
app(PriceFill::class)->run($account->fresh());$clock->at=$clock->at->modify('+2 minutes');
$used=EnomTestSendLimit::usage('catalogue-uid')['day'];
config(['domains.enom_test_sends_per_day'=>intdiv(($used+24)*10+2,3)]);
expect(PriceSync::calls(CatalogueAcceptances::load($binding))>PriceSync::backgroundLeft($binding,$clock),'The whole list cannot fit in this allowance');
DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$server->id],['account_fingerprint'=>$binding->fingerprint,'catalogue_due'=>0,'catalogue_cursor'=>0]);
$reads=count($GLOBALS['catalogueCalls']);
(new PriceSync(app(AccountBindings::class),app(CatalogueSync::class),$clock))->daily($account->fresh());
$imported=Tld::where('server_id',$server->id)->whereIn('tld',$more)->count();
expect($imported>0&&$imported<count($more),'A bounded scheduled batch progresses instead of skipping the whole day');
same(false,in_array('GetTLDList',array_column(array_slice($GLOBALS['catalogueCalls'],$reads),'Command'),true),'A scheduled batch uses the saved list');
config(['domains.enom_test_sends_per_day'=>10000]);
// Live deployment switches and processing alone do not authorize automatic sales before Activate.
$live=Server::create(['name'=>'Synthetic inactive Live','type'=>'server','extension'=>'Domains','enabled'=>true]);
$la=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$live->id,'registrar_key'=>'enom','environment'=>'live','stable_identity'=>'inactive-live-uid']));
Account::withVerifiedBindingWrite(fn()=>$la->update(['live_site'=>'https://billing.example.com']));
$lb=CatalogueAcceptances::binding($la);$live->settings()->create(['key'=>SellRule::KEY,'type'=>'array','encrypted'=>false,'value'=>['mode'=>'except','max_cost'=>null,'years'=>1,'transfers'=>false]]);
config(['app.url'=>'https://billing.example.com','domains.enom_identity.environment'=>'live','domains.enom_live.site'=>'https://billing.example.com',
    'domains.enom_live.catalogue'=>true,'domains.enom_live.registration'=>true,'domains.enom_live.accounts'=>['inactive-live-uid'=>['fingerprint'=>$lb->fingerprint,'daily_call_cap'=>1000]]]);
same(true,SellAutomation::enabled($la->fresh()),'Local preparation can supply the prices and seller needed by Activate');
same(false,Paymenter\Extensions\Servers\Domains\Services\EnomTestRegistration::sellable($lb),'Saving keys or deployment switches cannot replace Activate');
// Offline verified-credential fixture and already-read detail evidence: no Live transport is used.
$credentials=['uid'=>'inactive-live-uid','token'=>'synthetic-live-token'];
foreach(['domains_credentials'=>json_encode($credentials,JSON_THROW_ON_ERROR),
    'domains_verified_credentials'=>hash_hmac('sha256',$lb->fingerprint.json_encode($credentials,JSON_THROW_ON_ERROR),(string)config('app.key'))] as $key=>$value){
    $live->settings()->create(['key'=>$key,'encrypted'=>true,'type'=>'string','value'=>$value]);
}
SellRow::create(['server_id'=>$live->id,'account_fingerprint'=>$lb->fingerprint,'tld'=>'xyz','register_cost'=>'10.0000','renew_cost'=>'10.0000',
    'high_cost'=>'10.0000','prices'=>[1=>['register'=>'10.0000','renew'=>'10.0000']],'read_at'=>$clock->now()->getTimestamp()]);
SellAutomation::accept($la->fresh(),$clock->now());$accepted=CatalogueAcceptances::load($lb);
$rates=array_map(fn($action)=>new Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action,1,'10.0000','offline_fixture',$clock->now(),$clock->now()->modify('+1 day')),['register','renew']);
config(['domains.enom_live.registration'=>false]);same(false,SellAutomation::enabled($la->fresh()),'The new-TLD guard is exercised with automation off');
app(Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::class)->import(new Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($lb,'xyz',$rates,[1],true,true),$accepted,
    fn($s,$a)=>app(AccountBindings::class)->requireReadableLocked($s,$a),$clock->now(),null,true);
$lt=Tld::where('server_id',$live->id)->sole();$regression(!$lt->enabled,'An import cannot enable a new TLD while automation is off');
// Restore the independent, enabled preparation fixture after the disabled-import check.
config(['domains.enom_live.registration'=>true]);$lt->update(['enabled'=>true]);app(NativeCatalogue::class)->keepCurrent($lt,$clock->now(),true);
same((int)$live->id,SalesPolicy::where('tld','xyz')->sole()->server_id,'The seller is prepared without a per-TLD staff action');
same(2,Product::where('server_id',$live->id)->count(),'Hidden products are prepared before Activate');
expect(!in_array('xyz',array_column(app(Storefront::class)->endings(),'tld'),true),'Prepared Live products cannot sell before Activate');
$ready=Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::summary($la->fresh(),['registration'=>true,'renewal'=>false,'management'=>false,'transfer'=>false]);
expect(!str_contains(implode(' ',$ready['missing']),'Costs and prices Missing'),'Activate can recognize system-prepared prices and seller');
same(false,$la->fresh()->processing_enabled,'Preparation never turns orders on');
// Native model/settings reads are the regression; a pure validator cannot detect catalogue N+1 queries.
$activationClass=Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::class;
$scaleNames=['xyz'];
foreach(range('a','z') as $left){foreach(range('a','z') as $right){$scaleNames[]='scale'.$left.$right;if(count($scaleNames)===599){break 2;}}}
$accepted=CatalogueAcceptances::load($lb);$scaleEndings=[];
foreach($scaleNames as $ending){$scaleEndings[$ending]=$accepted->endings['xyz'];}
$accepted=CatalogueAcceptances::save($lb,$accepted->revision,$scaleEndings);
$templates=Price::where('server_id',$live->id)->get();
foreach($templates as $price){$price->eligibility=[...$price->eligibility,'catalogue_revision'=>$accepted->revision];$price->revision++;$price->save();}
$retired=$lt->replicate();$retired->tld='retired';$retired->enabled=false;$retired->save();
foreach($templates as $price){$copyPrice=$price->replicate();$copyPrice->tld='retired';$copyPrice->save();}
$activationQueries=0;$activationMeasuring=false;$priceReads=0;$snapshotTlds=[];
Price::retrieved(static function(Price $price)use(&$activationMeasuring,&$snapshotTlds):void{
    if($activationMeasuring){$snapshotTlds[]=$price->tld;}
});
DB::listen(static function($query)use(&$activationQueries,&$activationMeasuring,&$priceReads):void{
    if($activationMeasuring){$activationQueries++;if(str_contains($query->sql,'"domains_prices"')){$priceReads++;}}
});
$measureActivation=static function(int $size)use($activationClass,$la,&$activationQueries,&$activationMeasuring,&$priceReads,&$snapshotTlds):int{
    $activationQueries=$priceReads=0;$snapshotTlds=[];$activationMeasuring=true;
    try{$summary=$activationClass::summary($la->fresh(),['registration'=>true,'renewal'=>false,'management'=>false,'transfer'=>false]);}
    finally{$activationMeasuring=false;}
    same($size,$summary['tlds'],'Every enabled TLD appears in the cost review');
    same($size,$summary['counts']['register'],'Registration term count follows the catalogue');
    same(1,$priceReads,'Summary and readiness share one batched price read');
    expect(!in_array('retired',$snapshotTlds,true),'The display never loads retained prices for a disabled TLD');
    same($size,count(array_unique($snapshotTlds)),'The display loads prices for every enabled TLD');
    expect(!str_contains(implode(' ',$summary['missing']),'Costs and prices Missing'),'Batched evidence still validates');
    echo 'Activation '.$size.' TLDs: '.$activationQueries.' queries'."\n";
    return $activationQueries;
};
$smallQueries=$measureActivation(1);
foreach(array_slice($scaleNames,1) as $offset=>$ending){
    $copy=$lt->replicate();$copy->tld=$ending;$copy->save();
    foreach($templates as $price){$copyPrice=$price->replicate();$copyPrice->tld=$ending;$copyPrice->save();}
    SalesPolicy::withPolicyWrite(fn()=>SalesPolicy::create(['tld'=>$ending,'server_id'=>$live->id,'registrar_key'=>'enom',
        'account_fingerprint'=>$lb->fingerprint,'revision'=>1,'register_enabled'=>true,'transfer_enabled'=>false]));
    if($offset===1){same($smallQueries,$measureActivation(3),'Three-TLD query count stays flat');}
}
same($smallQueries,$measureActivation(599),'599-TLD query count stays flat');
// A display snapshot never survives a later readiness check, including the locked Activate path.
$changed=Price::where('server_id',$live->id)->where('tld','xyz')->where('action','renew')->sole();
$changed->update(['hold_reason'=>'unknown_price','revision'=>$changed->revision+1]);
$priceMissing=static fn(array $problems):bool=>str_contains(implode(' ',$problems),'Costs and prices Missing');
expect($priceMissing($activationClass::summary($la->fresh(),['registration'=>true,'renewal'=>false,'management'=>false,'transfer'=>false])['missing']),'A new render detects changed renewal cost');
expect($priceMissing(DB::transaction(fn()=>$activationClass::problems($la->fresh(),['registration'=>true]))),'Locked readiness independently detects a cost changed after display');
$changed->update(['hold_reason'=>null,'retail'=>'1.00','eligibility'=>[...$changed->eligibility,'staff_alert'=>'below_cost'],'revision'=>$changed->revision+1]);
$warning=$activationClass::summary($la->fresh(),['registration'=>true,'renewal'=>false,'management'=>false,'transfer'=>false]);
same('xyz',$warning['exceptions'][0]['tld'],'A below-cost renewal is a visible named exception');
expect(!$priceMissing($warning['missing'])&&$warning['exceptions'][0]['note']!=='','Below-cost renewal stays billable with an exception note');
// Mixed review: readable costs and a below-cost warning survive another TLD's missing renewal.
$bad=Price::where('server_id',$live->id)->where('tld','scaleab')->where('action','renew')->sole();
$bad->update(['hold_reason'=>'unknown_price','revision'=>$bad->revision+1]);
$mixedFeatures=['registration'=>true,'renewal'=>true,'management'=>false,'transfer'=>false];
$mixed=$activationClass::summary($la->fresh(),$mixedFeatures);
same(1196,count($mixed['costs']),'Only the two unreadable action rows are omitted from the mixed review');
same(['register'=>598,'transfer'=>0,'renew'=>598],$mixed['counts'],'Readable action counts remain available');
same(['10.0000','10.0000'],$mixed['range'],'Readable costs still have a range');
expect(in_array('scaleaa',array_column($mixed['costs'],'tld'),true),'An ordinary valid TLD remains in the review');
same('xyz',$mixed['exceptions'][0]['tld'],'The below-cost TLD remains a visible exception beside missing prices');
same(['scaleab','scaleab'],array_column($mixed['failures'],'tld'),'Every failed action names its TLD');
same(['register','renew'],array_column($mixed['failures'],'action'),'Registration and renewal failures are distinguished');
same([1,1],array_column($mixed['failures'],'term'),'Failed action terms are retained');
expect($priceMissing($mixed['missing']),'A partial display does not report price readiness');
$mixedHtml=view('domains::admin.live-activation',['summary'=>$mixed])->render();
foreach(['.scaleaa','.xyz: Below cost','.scaleab register (1 year)','.scaleab renew (1 year)','Sync prices if costs are missing.'] as $text){
    expect(str_contains($mixedHtml,$text),'Mixed cost review renders '.$text);
}
foreach(['Prices needing attention','Price exceptions'] as $label){
    expect(str_contains($mixedHtml,'<div tabindex="0" role="region" aria-label="'.$label.'" class="domains-scroll domains-scroll-short'),'Cost review list has a labeled keyboard-scrollable region: '.$label);
}
expect($priceMissing(DB::transaction(fn()=>$activationClass::problems($la->fresh(),$mixedFeatures))),'Activate independently refuses the missing cost after a partial display');

config(['app.url'=>'http://domains-tests.invalid','domains.enom_identity.environment'=>'test']);
if($failures!==[]){throw new \RuntimeException(implode("; ",$failures));}
echo "Automatic sales passed.\n";$nativeSuccessful=true;
