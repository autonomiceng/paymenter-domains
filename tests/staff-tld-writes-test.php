<?php
// Native settings writer and one-use review regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites,PricingRules,CatalogueAcceptances};
$f=commerceFixture(true);extract($f);tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY,Staff::COSTS];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);app()->instance(StaffSettingsWrites::class,$writer);
$review=static fn(string $action,?int $ending=null,?int $account=null,?string $stamp=null,?string $key=null,string $reason='Synthetic reviewed change')=>new Review($u->id,$account??$t->server_id,$ending,$action,
    $key??Review::ticket($u->id,$account??$t->server_id,$ending,$action,$stamp??Review::stamp($account??$t->server_id,$ending)),$reason);
$fixed=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'1.00','fixed_renew'=>'1.00','fixed_transfer'=>'1.00'];
foreach(array_keys(Review::ACTIONS) as $action){
    $page=Livewire::actingAs($u)->test(in_array($action,['configure','probe','acceptance','import','history','processing','delivery_review','alert_review','enroll_sandbox'],true)?RegistrarSetup::class:ViewTld::class,
        in_array($action,['configure','probe','acceptance','import','history','processing','delivery_review','alert_review','enroll_sandbox'],true)?[]:['record'=>$t->id]);
    $name=in_array($action,['configure','probe','acceptance','import','history','processing','delivery_review','alert_review','enroll_sandbox'],true)?\Filament\Actions\Testing\TestAction::make($action.'_'.$t->server_id)->schemaComponent('account_'.$t->server_id,'content'):$action;
    if($action==='enroll_sandbox'){$page->assertActionDisabled($name);continue;}
    $page->mountAction($name)->assertActionMounted($name)->setActionData(['reason'=>'x'])->callMountedAction()->assertHasActionErrors(['reason'=>'min']);
}
// Every settings action is hidden without its own permission, even with view/manage access.
$u->role->update(['permissions'=>[Staff::VIEW,Staff::POLICY]]);
foreach(array_keys(Review::ACTIONS) as $action){
    $setup=in_array($action,['configure','probe','acceptance','import','history','processing','delivery_review','alert_review','enroll_sandbox'],true);
    $page=Livewire::actingAs($u)->test($setup?RegistrarSetup::class:ViewTld::class,$setup?[]:['record'=>$t->id]);
    if($setup){$page->assertActionDoesNotExist(\Filament\Actions\Testing\TestAction::make($action.'_'.$t->server_id)->schemaComponent('account_'.$t->server_id,'content'));}else{$page->assertActionHidden($action);}
}
$u->role->update(['permissions'=>$permissions]);
foreach(array_keys(Review::ACTIONS) as $action){
    if($action==='enroll_sandbox'){continue;} // Enrollment has its own fresh-server review regression.
    $setup=in_array($action,['configure','probe','acceptance','import','history','processing','delivery_review','alert_review','enroll_sandbox'],true);
    $bad=$review($action,$setup?null:$t->id,null,str_repeat('0',64));
    tldRefusal(fn()=>$writer->run($bad,['registrar'=>'enom','environment'=>'test','credentials'=>['token'=>'account-enom'],'history_action'=>'register','confirmed'=>true], $now),'Settings changed. Reload this page.');
}
foreach([str_repeat('é',4),str_repeat('é',501),"valid\xff"] as $bad){commerceReject(fn()=>$writer->run($review('default_rule',$t->id,reason:$bad),$fixed,$now));}
$r=$review('default_rule',$t->id,reason:str_repeat('🦦',500));$writer->run($r,$fixed,$now);same('fixed',(new PricingRules)->defaultRule($t->server_id)['mode'],'Default saved');
expect(str_contains(TldFacts::salesState($t->fresh()),'publish required'),'Unpublished rule blocks ready status');
expect(str_contains(implode(' ',array_column(TldFacts::prices($t->fresh()),'warning')),'Rule changed'),'Unpublished rule has visible alert');
tldRefusal(fn()=>$writer->run($r,$fixed,$now),'Settings changed. Reload this page.'); // This write changed its stamp.
same(1,count(TldFacts::history($t->server_id)),'Replay audit unchanged');
expect(str_contains(implode(' ',TldFacts::history($t->server_id,999)),'Edit default rule'),'Shared default appears in sibling history');
$same=['min_years'=>1,'max_years'=>3,'enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true];
$writer->run($review('tld_settings',$t->id),$same,$now); // Settle the synthetic updated_at first.
$stamp=Review::stamp($t->server_id,$t->id);$key=Review::ticket($u->id,$t->server_id,$t->id,'tld_settings',$stamp);
$writer->run($review('tld_settings',$t->id,key:$key),$same,$now);
same($stamp,Review::stamp($t->server_id,$t->id),'No-op settings action leaves its reviewed stamp unchanged');
tldRefusal(fn()=>$writer->run($review('tld_settings',$t->id,key:$key),$same,$now),'Review key used.');
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('tld_settings')
    ->setActionData([...$same,'review_key'=>$key,'reason'=>'Replay unchanged native confirmation'])->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('Change refused')->body('This confirmation was already used. Reload this page.'))->assertSet('mountedActions',[]);
$r=$review('publish',$t->id);$before=Price::where('server_id',$t->server_id)->pluck('revision','id')->all();$writer->run($r,[],$now);
foreach(Price::where('server_id',$t->server_id)->get() as $p){same($before[$p->id]+1,$p->revision,'Revision incremented');same($p->action==='renew'?null:'below_cost',$p->hold_reason,'Margin rule');same('below_cost',$p->eligibility['staff_alert'],'Staff alert');}
expect(str_contains(TldFacts::salesState($t->fresh()),'below cost'),'Below-cost sales never reported ready');
same('1.00',$prices->renewal($t->server_id,$t->account_fingerprint,'com','USD',$now)->retail,'Below-cost renewal billed at configured retail');
commerceReject(fn()=>$prices->current($t->server_id,$t->account_fingerprint,'com','register',1,'USD',$now));
$nativePage=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('publish')->assertActionMounted('publish');
$nativeKey=$nativePage->get('mountedActions.0.data.review_key');
$nativePage->setActionData(['reason'=>'Confirm native publication'])->callMountedAction()->assertNotified('Change recorded')->assertSet('mountedActions',[]);
tldRefusal(fn()=>$writer->run($review('publish',$t->id,null,null,$nativeKey),[],$now),'Settings changed. Reload this page.');
$r=$review('override',$t->id);$writer->run($r,[...$fixed,'fixed_register'=>'20.00','fixed_renew'=>'21.00','fixed_transfer'=>'22.00'],$now);
expect(!$t->fresh()->use_default_rule,'Override enabled');$writer->run($review('publish',$t->id),[],$now);
$r=$review('remove_override',$t->id);$writer->run($r,[],$now);expect($t->fresh()->use_default_rule,'Override removed');
$stale=Review::stamp($t->server_id,$t->id);$writer->run($review('default_rule',$t->id),[...$fixed,'fixed_renew'=>'30.00'],$now);
tldRefusal(fn()=>$writer->run($review('publish',$t->id,null,$stale),[],$now),'Settings changed. Reload this page.');
$r=$review('default_rule',$t->id);$u->role->update(['permissions'=>[Staff::VIEW,Staff::POLICY]]);commerceReject(fn()=>$writer->run($r,$fixed,$now));$u->role->update(['permissions'=>$permissions]);
$writer->run($review('default_rule',$t->id),['mode'=>'markup_percent','markup'=>'30','round_up'=>'none'],$now);$writer->run($review('publish',$t->id),[],$now);
$holding=Domain::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder.com','sld'=>'holder','tld'=>'com','status'=>'active']);$generation=$holding->authority_generation;
$writer->run($review('seller',$t->id),['seller'=>$tlds[1]->server_id,'register'=>true,'transfer'=>true],$now);
same($tlds[1]->server_id,SalesPolicy::where('tld','com')->sole()->server_id,'New seller selected');same($t->server_id,$holding->fresh()->server_id,'Holder retains account');same($generation,$holding->fresh()->authority_generation,'Holder generation immutable');
$writer->run($review('acceptance',null),['ending'=>'com','accept'=>false],$now);$a=Account::where('server_id',$t->server_id)->sole();$accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding($a));
same([],$accepted->endings['com']['register'],'New sales revoked');same([1],$accepted->endings['com']['renew'],'Holder annual renewal retained');
commerceReject(fn()=>$writer->run($review('acceptance',null,$tlds[1]->server_id),['ending'=>'com','accept'=>true,'terms'=>[1,3],'verified'=>true],$now));
$writer->run($review('acceptance',null),['ending'=>'com','accept'=>true,'terms'=>[1,3],'verified'=>true,'registry'=>true],$now);
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
$writer->run($review('probe'),[]);$calls=$reads->calls;
commerceReject(fn()=>(new StaffSettingsWrites($native,$sellers,$bindings))->run($review('probe'),[]));same($calls,$reads->calls,'Default seam never calls provider');
foreach([['configure',['registrar'=>'enom','environment'=>'test','credentials'=>['token'=>'wrong-account']]],['probe',[]]] as [$action,$data]){
    $r=$review($action);if($action==='configure'){commerceReject(fn()=>$writer->run($r,$data,$now));}else{$writer->run($r,$data);}
}
$body=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->html().Livewire::actingAs($u)->test(RegistrarSetup::class)->html();
foreach(['account-enom','wrong-account','account-namesilo'] as $secret){expect(!str_contains($body,$secret),'No credentials rendered');}
$history=Review::historyRows(App\Models\Server::find($t->server_id));
foreach(['verification_requested','verification_refused','connection_requested','connection_finished'] as $phase){expect(count(array_filter($history,fn($r)=>$r['phase']===$phase))>=1,'Requested and failed/finished phases retained');}
$stamp=Review::stamp($t->server_id,$t->id);$unrelated=Price::where('server_id',$tlds[1]->server_id)->firstOrFail();$unrelated->update(['revision'=>$unrelated->revision+1]);
same($stamp,Review::stamp($t->server_id,$t->id),'Unrelated registrar price refresh leaves review current');
expect(str_contains(implode(' ',array_column(TldFacts::prices($t->fresh()),'warning')),'new sales held pending import'),'Acceptance changes disclose account import hold');
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
$writer->run($review('tld_settings',$tlds[1]->id,$other->id),$settings,$now);
same(1,$tlds[1]->fresh()->max_years,'Corrupt acceptance permits narrowing');
$writer->run($review('tld_settings',$tlds[1]->id,$other->id),[...$settings,'enabled'=>false],$now);
expect(!$tlds[1]->fresh()->enabled,'Corrupt acceptance permits pausing new sales');
foreach([[...$settings,'max_years'=>3,'enabled'=>false,'verified'=>true],[...$settings,'verified'=>true]] as $attempt){
    tldRefusal(fn()=>$writer->run($review('tld_settings',$tlds[1]->id,$other->id),$attempt,$now),'Catalogue acceptance is unavailable.');
}
$writer->run($review('acceptance',null,$other->id),['ending'=>'com','accept'=>true,'verified'=>true,'terms'=>[1],'registry'=>true],$now);
same(2,CatalogueAcceptances::load(CatalogueAcceptances::binding(Account::where('server_id',$other->id)->sole()))->revision,'Repair advances beyond retained import proof');
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\PublicationEligibility::require($tlds[1]->fresh(),'register',1),'Repair cannot reuse earlier import proof');
$audit->value='invalid';$audit->save();$before=Price::pluck('revision','id')->all();
commerceReject(fn()=>$writer->run($review('publish',$t->id),[],$now));same($before,Price::pluck('revision','id')->all(),'Corrupt audit rolls back writes');
$u->role->update(['permissions'=>[Staff::VIEW]]);Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('publish');
echo "Native settings confirmations, denial, stale/replay, audit, pricing, seller, acceptance and fake setup passed.\n";$nativeSuccessful=true;
