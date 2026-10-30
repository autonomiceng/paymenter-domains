<?php
// Native settings writer and one-use review regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services {
    function hrtime(bool $asNumber=false): mixed {return $GLOBALS['staffProbeNanoseconds']??\hrtime($asNumber);}
}
namespace {
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites,PricingRules,CatalogueAcceptances};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
$f=commerceFixture(true);extract($f);tldPanel();pinStaffClock($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY,Staff::COSTS];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);app()->instance(StaffSettingsWrites::class,$writer);
$review=static fn(string $action,?int $ending=null,?int $account=null,?string $stamp=null,?string $key=null,string $reason='Synthetic reviewed change')=>new Review($u->id,$account??$t->server_id,$ending,$action,
    $key??Review::ticket($u->id,$account??$t->server_id,$ending,$action,$stamp??Review::stamp($account??$t->server_id,$ending)),$reason);
$syncConfig=['domains.enom_catalogue.enabled'=>config('domains.enom_catalogue.enabled'),'domains.enom_identity.environment'=>config('domains.enom_identity.environment')];
config(['domains.enom_catalogue.enabled'=>true,'domains.enom_identity.environment'=>'test']);
$fixed=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'1.00','fixed_renew'=>'1.00','fixed_transfer'=>'1.00'];
foreach(array_diff(array_keys(Review::ACTIONS),staffNoButtonActions()) as $action){
    $page=Livewire::actingAs($u)->test(in_array($action,staffSetupActions(),true)?RegistrarAccount::class:ViewTld::class,
        in_array($action,staffSetupActions(),true)?['account'=>$t->server_id]:['record'=>$t->id]);
    $name=in_array($action,staffSetupActions(),true)?setupAction($action,$t->server_id):$action;
    if(in_array($action,['test_renewal','test_management','live_renewal','live_management'],true)){$page->assertActionDoesNotExist($name);continue;} // Deployment opt-in is off.
    if($action==='enroll_sandbox'){$page->assertActionDoesNotExist($name);continue;}
    if($action==='import'){
        $page->assertActionDoesNotExist($name);
        $syncName='sync_prices_'.$t->server_id;
        $sync=Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds::class)->mountAction($syncName)->assertActionMounted($syncName);
        same(['review_key'],array_keys($sync->get('mountedActions.0.data')),'Account-wide sync asks only for its review key');
        continue;
    }
    $page->mountAction($name)->assertActionMounted($name)->setActionData(['reason'=>'x'])->callMountedAction()->assertHasActionErrors(['reason'=>'min']);
}
// Every settings action is hidden without its own permission, even with view/manage access.
$u->role->update(['permissions'=>[Staff::VIEW,Staff::POLICY]]);
foreach(array_keys(Review::ACTIONS) as $action){
    $setup=in_array($action,staffSetupActions(),true);
    $page=Livewire::actingAs($u)->test($setup?RegistrarAccount::class:ViewTld::class,$setup?['account'=>$t->server_id]:['record'=>$t->id]);
    if($action==='import'){
        $page->assertActionDoesNotExist(setupAction($action,$t->server_id));
        Livewire::actingAs($u)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds::class)->assertActionHidden('sync_prices_'.$t->server_id);
    }
    elseif(in_array($action,staffNoButtonActions(),true)){$page->assertActionDoesNotExist($action);}
    elseif(in_array($action,['configure','processing'],true)){$page->assertActionHidden(setupAction($action,$t->server_id));}elseif($setup){$page->assertActionDoesNotExist(setupAction($action,$t->server_id));}else{$page->assertActionHidden($action);}
}
$u->role->update(['permissions'=>$permissions]);config($syncConfig);
$staleFirst=['processing','delivery_review','alert_review'];
foreach([...$staleFirst,...array_values(array_diff(array_keys(Review::ACTIONS),$staleFirst))] as $action){
    if($action==='enroll_sandbox'){continue;} // Enrollment has its own fresh-server review regression.
    $setup=in_array($action,staffSetupActions(),true);
    $bad=$review($action,$setup?null:$t->id,null,str_repeat('0',64));
    tldRefusal(fn()=>$writer->run($bad,['registrar'=>'enom','environment'=>'test','credentials'=>['token'=>'account-enom'],'history_action'=>'register','confirmed'=>true,'enabled'=>false,'delivery_id'=>1,'alert_id'=>1], $now),'Settings changed. Reload this page.');
}
foreach([str_repeat('é',4),str_repeat('é',501),"valid\xff"] as $bad){commerceReject(fn()=>$writer->run($review('default_rule',$t->id,reason:$bad),$fixed,$now));}
$r=$review('default_rule',$t->id,reason:str_repeat('🦦',500));same(['published'=>1,'failed'=>[]],$writer->run($r,$fixed,$now),'Default publishes the TLD that follows it');same('fixed',(new PricingRules)->defaultRule($t->server_id)['mode'],'Default saved');
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'Below cost'],TldFacts::salesState($t->fresh()),'Published below-cost default is not for sale');
$auditBeforeReplay=Review::historyRows(App\Models\Server::findOrFail($t->server_id));
same(['completed','publication_completed'],array_column($auditBeforeReplay,'phase'),'Default save and publication outcome audited');
$historyBeforeReplay=TldFacts::history($t->server_id);
tldRefusal(fn()=>$writer->run($r,$fixed,$now),'Settings changed. Reload this page.'); // This write changed its stamp.
same($historyBeforeReplay,TldFacts::history($t->server_id),'Replay audit unchanged');
same($auditBeforeReplay,Review::historyRows(App\Models\Server::findOrFail($t->server_id)),'Refused replay retains exact saved audit');
expect(in_array('Default pricing changed',array_column(TldFacts::history($t->server_id,999),'change'),true),'Shared default appears in sibling history');
// Missing renewal cost: the default is saved, but this TLD keeps its published prices and says so.
$renewalCost=Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'renew','term'=>1])->sole();
Price::whereKey($renewalCost->id)->update(['wholesale'=>null]);
same(['published'=>0,'failed'=>['.com']],$writer->run($review('default_rule',$t->id),[...$fixed,'fixed_renew'=>'2.00'],$now->modify('+2 days')),'Missing-cost TLD is named');
Price::whereKey($renewalCost->id)->update(['wholesale'=>$renewalCost->wholesale]);
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'Prices not published'],TldFacts::salesState($t->fresh()),'Unpublished rule blocks ready status');
$rows=TldFacts::prices($t->fresh());expect($rows&&collect($rows)->every(fn($r)=>in_array('unpublished',$r['flags'],true)),'Unpublished rule has visible alert');
$same=['min_years'=>1,'max_years'=>3,'enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true];
$stamp=Review::stamp($t->server_id,$t->id);$key=Review::ticket($u->id,$t->server_id,$t->id,'tld_settings',$stamp);
tldRefusal(fn()=>$writer->run($review('tld_settings',$t->id,key:$key),$same,$now),'Terms unchanged.');
same($stamp,Review::stamp($t->server_id,$t->id),'No-op settings action leaves its reviewed stamp unchanged');
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('tld_settings')
    ->setActionData([...$same,'review_key'=>$key,'reason'=>'Replay unchanged native confirmation'])->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified('Nothing to save')->assertSet('mountedActions',[]);
$r=$review('publish',$t->id);$before=Price::where('server_id',$t->server_id)->pluck('revision','id')->all();$writer->run($r,[],$now);
foreach(Price::where('server_id',$t->server_id)->get() as $p){same($before[$p->id]+1,$p->revision,'Revision incremented');same($p->action==='renew'?null:'below_cost',$p->hold_reason,'Margin rule');same('below_cost',$p->eligibility['staff_alert'],'Staff alert');}
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'Below cost'],TldFacts::salesState($t->fresh()),'Below-cost sales never reported ready');
same('2.00',$prices->renewal($t->server_id,$t->account_fingerprint,'com','USD',$now)->retail,'Below-cost renewal billed at configured retail');
commerceReject(fn()=>$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now));
$nativePage=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('override')->assertActionMounted('override');
$nativeKey=$nativePage->get('mountedActions.0.data.review_key');
$nativePage->setActionData(['pricing'=>'custom',...$fixed,'reason'=>'Confirm native publication'])->callMountedAction()->assertNotified('Prices published')->assertSet('mountedActions',[]);
$nativePage->mountAction('override')->setActionData(['pricing'=>'custom',...$fixed,'fixed_renew'=>'3.00','reason'=>'Publish again with no reload'])->callMountedAction()->assertHasNoActionErrors();same('3.00',$prices->renewal($t->server_id,$t->account_fingerprint,'com','USD',$now)->retail,'A second save on one page lands');
tldRefusal(fn()=>$writer->run($review('override',$t->id,null,null,$nativeKey),$fixed,$now),'Settings changed. Reload this page.');
$r=$review('override',$t->id);$writer->run($r,[...$fixed,'fixed_register'=>'20.00','fixed_renew'=>'21.00','fixed_transfer'=>'22.00'],$now);
expect(!$t->fresh()->use_default_rule,'Override enabled');$writer->run($review('publish',$t->id),[],$now);
$r=$review('remove_override',$t->id);$writer->run($r,[],$now);expect($t->fresh()->use_default_rule,'Override removed');
$stale=Review::stamp($t->server_id,$t->id);$writer->run($review('default_rule',$t->id),[...$fixed,'fixed_renew'=>'30.00'],$now);
tldRefusal(fn()=>$writer->run($review('publish',$t->id,null,$stale),[],$now),'Settings changed. Reload this page.');
$r=$review('default_rule',$t->id);$u->role->update(['permissions'=>[Staff::VIEW,Staff::POLICY]]);commerceReject(fn()=>$writer->run($r,$fixed,$now));$u->role->update(['permissions'=>$permissions]);
$writer->run($review('default_rule',$t->id),['mode'=>'markup_percent','markup'=>'30','round_up'=>'none'],$now);$writer->run($review('publish',$t->id),[],$now);
$holding=Domain::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);$generation=$holding->authority_generation;
$writer->run($review('seller',$t->id),['seller'=>$tlds[1]->server_id,'register'=>true,'transfer'=>true],$now);
same($tlds[1]->server_id,SalesPolicy::where('tld','com')->sole()->server_id,'New seller selected');same($t->server_id,$holding->fresh()->server_id,'Holder retains account');same($generation,$holding->fresh()->authority_generation,'Holder generation immutable');
$writer->run($review('acceptance',null),['changes'=>['com'=>null]],$now);$a=Account::where('server_id',$t->server_id)->sole();$accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding($a));
same([],$accepted->endings['com']['register'],'New sales revoked');same([1],$accepted->endings['com']['renew'],'Holder annual renewal retained');
commerceReject(fn()=>$writer->run($review('acceptance',null,$tlds[1]->server_id),['changes'=>['com'=>['terms'=>[1,3],'transfer'=>false]]],$now));
$writer->run($review('acceptance',null),['changes'=>['com'=>['terms'=>[1,3],'transfer'=>false]]],$now);
expect(!$t->fresh()->register_enabled,'Widening cannot restore disabled sales');
$writer->run($review('history'),['history_action'=>'register','accept'=>true,'verified'=>true,'timezone'=>'America/Los_Angeles','lag'=>60,'retention'=>86400],$now);
expect(\Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::load(CatalogueAcceptances::binding($a))!==null,'History accepted');
$writer->run($review('history'),['history_action'=>'register','accept'=>false],$now);
same(null,\Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances::load(CatalogueAcceptances::binding($a)),'History revoked');
$writer->run($review('tld_settings',$t->id),['min_years'=>1,'max_years'=>1,'enabled'=>true,'renew'=>true],$now);
commerceReject(fn()=>$writer->run($review('tld_settings',$t->id),['min_years'=>1,'max_years'=>3,'enabled'=>true,'renew'=>true],$now));
commerceReject(fn()=>$writer->run($review('tld_settings',$t->id),['min_years'=>1,'max_years'=>1,'enabled'=>false,'renew'=>false],$now));
$r=$review('configure');$writer->run($r,['registrar'=>'enom','environment'=>'test','credentials'=>['token'=>'account-enom']],$now);
expect(!Account::where('server_id',$t->server_id)->sole()->processing_enabled,'Rotation pauses processing');commerceReject(fn()=>$writer->run($r,['registrar'=>'enom','environment'=>'test','credentials'=>['token'=>'account-enom']],$now));
// Freeze monotonic time so the explicit-clock probes can require exactly ten seconds.
$GLOBALS['staffProbeNanoseconds']=0;
foreach(['2001-01-01T00:00:00Z','2040-01-01T00:00:00Z'] as $at){
    $explicit=new DateTimeImmutable($at);$otherClock=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock {public function now(): DateTimeImmutable{return new DateTimeImmutable('2026-10-18T12:00:00Z');}};
    app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$otherClock);
    FakeRegistrar::$readClaims=[];$writer->run($review('probe'),[],$explicit);
    same([['milliseconds'=>10000,'remaining'=>0]],FakeRegistrar::$readClaims,'Explicit '.$at.' probe has ten seconds and one read');
}
unset($GLOBALS['staffProbeNanoseconds']);
app()->forgetInstance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
FakeRegistrar::$readClaims=[];$writer->run($review('probe'),[]);$calls=$reads->calls;
same(1,count(FakeRegistrar::$readClaims),'Omitted-time probe makes one read');$claim=FakeRegistrar::$readClaims[0];
expect($claim['milliseconds']>0&&$claim['milliseconds']<=10000,'Omitted-time probe retains a bounded deadline');
same(0,$claim['remaining'],'Omitted-time probe consumes its one-read budget');
commerceReject(fn()=>(new StaffSettingsWrites($native,$sellers,$bindings))->run($review('probe'),[]));same($calls,$reads->calls,'Default seam never calls provider');
foreach([['configure',['registrar'=>'enom','environment'=>'test','credentials'=>['token'=>'wrong-account']]],['probe',[]]] as [$action,$data]){
    $r=$review($action);if($action==='configure'){commerceReject(fn()=>$writer->run($r,$data,$now));}else{$writer->run($r,$data);}
}
$body=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->html().Livewire::actingAs($u)->test(RegistrarSetup::class)->html().Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->html();
foreach(['account-enom','wrong-account','account-namesilo'] as $secret){expect(!str_contains($body,$secret),'No credentials rendered');}
$history=Review::historyRows(App\Models\Server::find($t->server_id));
foreach(['verification_requested','verification_refused','connection_requested','connection_finished'] as $phase){expect(count(array_filter($history,fn($r)=>$r['phase']===$phase))>=1,'Requested and failed/finished phases retained');}
$stamp=Review::stamp($t->server_id,$t->id);$unrelated=Price::where('server_id',$tlds[1]->server_id)->firstOrFail();$unrelated->update(['revision'=>$unrelated->revision+1]);
same($stamp,Review::stamp($t->server_id,$t->id),'Unrelated registrar price refresh leaves review current');
expect(collect(TldFacts::prices($t->fresh()))->contains(fn($r)=>$r['action']!=='renew'&&in_array('needs_sync',$r['flags'],true)),'Acceptance changes disclose account import hold');
$audit=App\Models\Server::find($t->server_id)->settings()->where('key',Review::KEY)->sole();expect($audit->encrypted,'Encrypted audit');
$rows=json_decode($audit->value,true);foreach($rows as $row){expect(isset($row['actor_id'],$row['reason'],$row['before'],$row['after'],$row['at']),'Complete audit');}
expect(!str_contains($audit->getRawOriginal('value'),'Synthetic reviewed change'),'Raw audit encrypted');
$unknown=Price::where('server_id',$t->server_id)->where('action','register')->where('term',1)->sole();$unknown->update(['wholesale'=>null,'revision'=>$unknown->revision+1]);
$writer->run($review('publish',$t->id),[],$now);same(null,$unknown->fresh()->retail,'Unknown cost never zero');
// Corrupt audit refuses atomically; a foreign-actor ticket never authorizes a write.
$foreign=Review::ticket($u->id+100,$t->server_id,$t->id,'publish',Review::stamp($t->server_id,$t->id));
commerceReject(fn()=>$writer->run($review('publish',$t->id,null,null,$foreign),[],$now));
$other=App\Models\Server::find($tlds[1]->server_id);$setting=$other->settings()->where('key',CatalogueAcceptances::KEY)->sole();$setting->value='invalid';$setting->save();
$settings=['min_years'=>1,'max_years'=>1,'enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true];
$writer->run($review('tld_settings',$tlds[1]->id,$other->id),[...$settings,'transfer'=>false],$now);
same(false,$tlds[1]->fresh()->transfer_enabled,'Corrupt acceptance permits narrowing');
$writer->run($review('tld_settings',$tlds[1]->id,$other->id),[...$settings,'transfer'=>false,'enabled'=>false],$now);
expect(!$tlds[1]->fresh()->enabled,'Corrupt acceptance permits pausing new sales');
foreach([[...$settings,'max_years'=>3,'enabled'=>false,'verified'=>true],[...$settings,'verified'=>true]] as $attempt){
    tldRefusal(fn()=>$writer->run($review('tld_settings',$tlds[1]->id,$other->id),$attempt,$now),'Catalogue acceptance is unavailable.');
}
$writer->run($review('acceptance',null,$other->id),['changes'=>['com'=>['terms'=>[1],'transfer'=>false]]],$now);
same(2,CatalogueAcceptances::load(CatalogueAcceptances::binding(Account::where('server_id',$other->id)->sole()))->revision,'Repair advances beyond retained import proof');
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\PublicationEligibility::require($tlds[1]->fresh(),'register',1),'Repair cannot reuse earlier import proof');
$audit->value='invalid';$audit->save();$before=Price::pluck('revision','id')->all();
commerceReject(fn()=>$writer->run($review('publish',$t->id),[],$now));same($before,Price::pluck('revision','id')->all(),'Corrupt audit rolls back writes');
$u->role->update(['permissions'=>[Staff::VIEW]]);Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('override');
echo "Native settings confirmations, denial, stale/replay, audit, pricing, seller, acceptance and fake setup passed.\n";$nativeSuccessful=true;
}
