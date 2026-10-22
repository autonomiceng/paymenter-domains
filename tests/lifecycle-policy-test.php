<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/lifecycle-facts.php';
use Illuminate\Support\Facades\{Auth,DB,Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,LifecycleFacts as Facts,LifecyclePolicies as Policies,PricingRule,PriceCatalogue,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];$server=App\Models\Server::findOrFail($t->server_id);
$b=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->firstOrFail());
$other=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[1]->server_id)->firstOrFail());
$valid=lifecycleFixture($now);$key=Policies::key($t->tld);
$saved=static fn()=>Policies::read($b,$t->tld);
$cap=static fn(bool $accept=false)=>Policies::capabilities($b,$t->tld,$now,$accept);
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY,Review::permission('lifecycle')];
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings);app()->instance(StaffSettingsWrites::class,$writer);
$review=static fn()=>new Review($u->id,$t->server_id,$t->id,'lifecycle',Review::ticket($u->id,$t->server_id,$t->id,'lifecycle',Review::stamp($t->server_id,$t->id)),'Verify registrar lifecycle facts');
$action='lifecycle';

// An unchanged retained installation has no lifecycle settings and no implied support.
same(null,$saved(),'Old native settings have no inferred lifecycle facts');same(['grace'=>false,'restore'=>false],$cap(true),'Missing evidence closes both capabilities even with future acceptance');
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Not verified')->assertActionVisible($action);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action)->assertActionMounted($action);
$ticket=$page->get('mountedActions.0.data.review_key');
same(null,$page->get('mountedActions.0.data.grace_extra'),'No default zero charge without verification');
$page->setActionData([...$valid,'verified'=>true,'reason'=>'Verify account policy and charges'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded');
same($valid,$saved(),'Native staff form records complete exact facts');same(['grace'=>true,'restore'=>false],$cap(),'Restore defaults off despite complete evidence');same(['grace'=>true,'restore'=>true],$cap(true),'Independent future acceptance must still have evidence');
same(['grace'=>false,'restore'=>false],Policies::capabilities($other,'com',$now,true),'Facts never cross accounts or providers');
$row=$server->settings()->where('key',$key)->sole();$raw=$row->getRawOriginal('value');
expect($row->encrypted&&!str_contains($raw,$valid['restore_source']),'Registrar evidence encrypted in native settings');
$audit=array_values(array_filter(Review::historyRows($server->fresh()),fn($r)=>$r['action']==='Review lifecycle facts'));
same(1,count($audit),'One audit record');same(null,$audit[0]['before']['lifecycle'],'Audit before absent');same($valid,$audit[0]['after']['lifecycle'],'Audit retains verified facts');same($valid['grace_source'],$audit[0]['input']['grace_source'],'Audit keeps evidence source');
commerceReject(fn()=>$writer->run(new Review($u->id,$t->server_id,$t->id,'lifecycle',$ticket,'Refuse repeat submission'),[...$valid,'verified'=>true],$now));
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('80.0000 USD')->assertSee('execution disabled');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action);
same($valid['grace_verified_at'],$page->get('mountedActions.0.data.grace_verified_at'),'Reopening never refreshes verification time');

// Every malformed field is tested against a complete, positive baseline.
foreach(Facts::FIELDS as $missing){$v=$valid;unset($v[$missing]);commerceReject(fn()=>Facts::validate($v));same(null,Facts::until($v,'grace',$now),'Missing '.$missing.' never offers grace');}
foreach([
    ['grace_days'=>-1],['grace_days'=>366],['restore_days'=>-1],['restore_days'=>366],['grace_days'=>'30'],['restore_days'=>1.5],
    ['grace_supported'=>1],['restore_supported'=>null],['auto_renew_only'=>'0'],['conflicting'=>0],['extra_field'=>'unverified'],
    ['grace_extra'=>'-1.00'],['restore_fee'=>'-1.00'],['grace_extra'=>'0.00001'],['restore_fee'=>'1.00001'],['grace_extra'=>0],['restore_fee'=>null],
    ['grace_currency'=>'usd'],['restore_currency'=>'US'],['grace_currency'=>null],['restore_currency'=>1],
] as $change){commerceReject(fn()=>Facts::validate([...$valid,...$change]));}
foreach(['','grace_','restore_'] as $prefix){
    foreach(['',' tiny','tiny ','bad'."\n".'source',str_repeat('x',501),null] as $source){commerceReject(fn()=>Facts::validate([...$valid,$prefix.'source'=>$source]));}
    foreach(['2026-10-18','2026-02-30T12:00:00Z','2026-10-18T25:00:00Z','2026-10-18T12:00:00',null] as $at){commerceReject(fn()=>Facts::validate([...$valid,$prefix.'verified_at'=>$at]));}
}
foreach(['grace','restore'] as $which){
    foreach([[$which.'_supported'=>false],[$which.'_days'=>0],['conflicting'=>true],[$which.'_currency'=>'EUR'],
        ['verified_at'=>$now->modify('-30 days')->format(DATE_ATOM)],[$which.'_verified_at'=>$now->modify('-30 days')->format(DATE_ATOM)],
        ['verified_at'=>$now->modify('+1 second')->format(DATE_ATOM)],[$which.'_verified_at'=>$now->modify('+1 second')->format(DATE_ATOM)]] as $change){
        same(null,Facts::until([...$valid,...$change],$which,$now),'Unsafe '.$which.' evidence refuses: '.json_encode($change));
    }
    $at=$now->modify('-30 days +1 second')->format(DATE_ATOM);
    same($now->modify('+1 second')->getTimestamp(),Facts::until([...$valid,'verified_at'=>$at],$which,$now)?->getTimestamp(),'Freshness boundary one second before expiry');
    same(null,Facts::until($valid,$which,$now->modify('+30 days')),'Exact freshness expiry closes capability');
}
same(null,Facts::until([...$valid,'auto_renew_only'=>true],'grace',$now),'Auto-renew-only ending cannot use grace renewal');
expect(Facts::until([...$valid,'auto_renew_only'=>true],'restore',$now)!==null,'Auto-renew-only flag does not invent a restore prohibition');
same(null,Facts::until($valid,'register',$now),'Unknown action refuses');
$offset=[...$valid,'verified_at'=>'2026-10-18T05:00:00-07:00','grace_verified_at'=>'2026-10-18T14:00:00+02:00'];
same(Facts::until($valid,'grace',$now)?->getTimestamp(),Facts::until($offset,'grace',$now)?->getTimestamp(),'Verification uses instants across UTC offsets');
foreach([0,365] as $days){same($days,Facts::validate([...$valid,'grace_days'=>$days,'restore_days'=>$days])['grace_days'],'Day boundary preserved');}

// Actual published price/cost relation, independent of the configured rule mode.
$rate=Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$b->serverId,'action'=>'renew','term'=>1])->sole();
same('10.40',Facts::price(PriceCatalogue::retail($rate->retail),new Money(PriceCatalogue::cost($rate->wholesale),'USD'),new Money('0.0000','USD'))->amount,'Verified zero extra equals published regular renewal');
same('13.00',Facts::price(PriceCatalogue::retail($rate->retail),new Money(PriceCatalogue::cost($rate->wholesale),'USD'),new Money('2.0000','USD'))->amount,'Extra grace charge keeps regular effective margin');
same('114.40',Facts::price(PriceCatalogue::retail($rate->retail),new Money(PriceCatalogue::cost($rate->wholesale),'USD'),new Money('80.0000','USD'))->amount,'Restore includes renewal plus fee with same margin');
foreach(['markup_percent','markup_amount','fixed'] as $mode){
    $rule=[...PricingRule::DEFAULT,'mode'=>$mode,'markup'=>$mode==='markup_amount'?'2.00':'30.00','fixed'=>['register'=>'12.00','renew'=>'12.00','transfer'=>'12.00']];
    $retail=PricingRule::price('8.0000',$rule,'renew');
    same(PricingRule::decimal(PricingRule::minor($retail)*2),Facts::price($retail,new Money('8.0000','USD'),new Money('8.0000','USD'))->amount,'Published '.$mode.' effective markup reused');
}
same('0.02',Facts::price('0.01',new Money('0.0001','USD'),new Money('0.0001','USD'))->amount,'Smallest provider cost remains exact');
same('10.01',Facts::price('10.00',new Money('10.0000','USD'),new Money('0.0001','USD'))->amount,'Subcent registrar cost rounds up, never subsidized');
same('3.34',Facts::price('1.00',new Money('0.3000','USD'),new Money('0.7000','USD'))->amount,'Nonterminating markup rounds up once');
same('200000000.00',Facts::price('100000000.00',new Money('99999999.9999','USD'),new Money('99999999.9999','USD'))->amount,'Large exact arithmetic');
foreach([['7.99','8.0000','0.0000','USD','USD'],['0.00','8.0000','0.0000','USD','USD'],['10.00','0.0000','0.0000','USD','USD'],
    ['10.00','8.0000','1.0000','USD','EUR'],['10.00','8.0000','1.0000','EUR','EUR'],['9999999999.99','0.0001','99999999.9999','USD','USD']] as [$retail,$base,$fee,$c1,$c2]){
    commerceReject(fn()=>Facts::price($retail,new Money($base,$c1),new Money($fee,$c2)));
}

// Corruption, duplicate settings and copied bindings never become offer authority.
foreach([
    ['binding'=>$other->fingerprint,'tld'=>'com','facts'=>$valid],['binding'=>$b->fingerprint,'tld'=>'net','facts'=>$valid],
    ['binding'=>$b->fingerprint,'tld'=>'com','facts'=>[]],['binding'=>$b->fingerprint,'tld'=>'com','facts'=>'bad'],
] as $value){$row->value=json_encode($value);$row->save();same(null,$saved(),'Wrong owner/ending/shape refuses');}
$row->value='{';$row->save();same(null,$saved(),'Invalid JSON refuses');
DB::table('settings')->where('id',$row->id)->update(['value'=>'invalid ciphertext']);same(null,$saved(),'Invalid encryption refuses');
storeLifecycleFixture($b,$valid);$row=$row->fresh();$row->encrypted=false;$row->save();same(null,$saved(),'Unencrypted facts refused');
// Simulate a damaged retained database that lost the native unique index.
Schema::table('settings',fn($table)=>$table->dropUnique(['key','settingable_id','settingable_type']));
storeLifecycleFixture($b,$valid);$duplicate=$server->settings()->create(['key'=>$key,'value'=>'duplicate','encrypted'=>true,'type'=>'string']);
same(null,$saved(),'Duplicate evidence refuses');commerceReject(fn()=>$writer->run($review(),[...$valid,'verified'=>true],$now));$duplicate->delete();
Schema::table('settings',fn($table)=>$table->unique(['key','settingable_id','settingable_type']));
DB::table($t->getTable())->where('id',$t->id)->update(['account_fingerprint'=>$other->fingerprint]);same(null,$saved(),'Corrupt TLD account refuses');
DB::table($t->getTable())->where('id',$t->id)->update(['account_fingerprint'=>$b->fingerprint]);$t->refresh();
expect($cap()['grace'],'Restored binding revalidates; no terminal marker');

// Staff refusal, stale review and repeat verification are local, atomic changes.
$before=serialize(DB::table('settings')->get());
commerceReject(fn()=>$writer->run($review(),[...$valid,'verified'=>true,'restore_accepted'=>true],$now));
foreach([['grace_days'=>1.5],['restore_days'=>true],['grace_days'=>'30.0']] as $change){commerceReject(fn()=>$writer->run($review(),[...$valid,...$change,'verified'=>true],$now));}
foreach([false,'1',null] as $verified){commerceReject(fn()=>$writer->run($review(),[...$valid,'verified'=>$verified],$now));}
foreach(['verified_at','grace_verified_at','restore_verified_at'] as $field){commerceReject(fn()=>$writer->run($review(),[...$valid,$field=>$now->modify('+1 second')->format(DATE_ATOM),'verified'=>true],$now));}
same($before,serialize(DB::table('settings')->get()),'Refused writes leave settings and audit unchanged');
$stale=$review();$writer->run($review(),[...$valid,'conflicting'=>true,'verified'=>true],$now);same(['grace'=>false,'restore'=>false],$cap(true),'Conflict disables both');
commerceReject(fn()=>$writer->run($stale,[...$valid,'verified'=>true],$now));
$writer->run($review(),[...$valid,'verified'=>true],$now);same(['grace'=>true,'restore'=>false],$cap(),'Resolving conflict can enable policy again without enabling restore');
foreach([false,true,false,true] as $support){$writer->run($review(),[...$valid,'grace_supported'=>$support,'verified'=>true],$now);same($support,$cap()['grace'],'Disable and reverify across cycles');}
$old=[...$valid,'verified_at'=>$now->modify('-31 days')->format(DATE_ATOM)];$writer->run($review(),[...$old,'verified'=>true],$now);same(false,$cap()['grace'],'Saving stale facts never refreshes their age');
$writer->run($review(),[...$valid,'verified'=>true],$now);same(true,$cap()['grace'],'New verification renews policy');
$before=serialize(DB::table('settings')->get());
foreach([Staff::VIEW,Staff::COSTS,Staff::POLICY,Review::permission('lifecycle')] as $removed){
    $u->role->update(['permissions'=>array_values(array_diff($permissions,[$removed]))]);
    commerceReject(fn()=>$writer->run($review(),[...$valid,'verified'=>true],$now));
    if($removed!==Staff::VIEW){Livewire::actingAs($u->fresh())->test(ViewTld::class,['record'=>$t->id])->assertActionHidden($action);}
}
$u->role->update(['permissions'=>[Staff::VIEW]]);
Livewire::actingAs($u->fresh())->test(ViewTld::class,['record'=>$t->id])->assertSee('Policy verified')->assertDontSee('80.0000 USD')->assertActionHidden($action);
$u->role->update(['permissions'=>$permissions]);
foreach([
    new Review($u->id,$other->serverId,$t->id,'lifecycle',Review::ticket($u->id,$other->serverId,$t->id,'lifecycle',Review::stamp($other->serverId,$t->id)),'Foreign account review'),
    new Review($u->id,$t->server_id,$t->id,'lifecycle',Review::ticket($u->id,$t->server_id,$t->id,'override',Review::stamp($t->server_id,$t->id)),'Wrong action review'),
    new Review($u->id,$t->server_id,null,'lifecycle',Review::ticket($u->id,$t->server_id,null,'lifecycle',Review::stamp($t->server_id,null)),'Missing TLD review'),
] as $r){commerceReject(fn()=>$writer->run($r,[...$valid,'verified'=>true],$now));}
same($before,serialize(DB::table('settings')->get()),'Denied and foreign writes leave all settings unchanged');
expect(!str_contains(serialize(Policies::facts($t,$now,false)),'80.0000'),'Cost-free staff projection hides registrar charges');
echo "Lifecycle facts, native settings, pricing, capabilities, audit and refusal branches passed.\n";$nativeSuccessful=true;
