<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/lifecycle-facts.php';
use Illuminate\Support\Facades\{Auth,DB,Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
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

// Field defaults share one lazy read per form, including an empty policy.
$fields=static function(string $name)use($t):array{
    $action=$name==='default_rule'?SettingsActions::setup((int)$t->server_id,'unused',$name):SettingsActions::make((int)$t->server_id,(int)$t->id,'unused',false,[$name])[0];$out=[];
    foreach($action->getSchema(\Filament\Schemas\Schema::make())->getComponents(withHidden:true) as $field){if($field instanceof \Filament\Forms\Components\Field){$out[$field->getName()]=$field;}}
    return $out;
};
$reading=false;$reads=0;$policyReads=0;
DB::listen(static function($query)use(&$reading,&$reads,&$policyReads,$key):void{if($reading&&str_starts_with(strtolower($query->sql),'select')){$reads++;if(in_array($key,$query->bindings,true)){$policyReads++;}}});
$reading=true;$empty=$fields('lifecycle');same(0,$reads,'Building lifecycle fields is lazy');
foreach(Facts::FIELDS as $field){$empty[$field]->getDefaultState();}
same(1,$policyReads,'All sixteen empty lifecycle defaults share one settings read');
foreach(Facts::FIELDS as $field){$empty[$field]->getDefaultState();}
same(1,$policyReads,'An empty policy is cached too');$reading=false;
storeLifecycleFixture($b,$valid);$reading=true;same(null,$empty['grace_days']->getDefaultState(),'An open form retains its empty snapshot');
$fresh=$fields('lifecycle');foreach(Facts::FIELDS as $field){same($valid[$field],$fresh[$field]->getDefaultState(),'A fresh form reads current '.$field);}
same(2,$policyReads,'A fresh form reads the newly saved policy once');$reading=false;
$server->settings()->where('key',$key)->delete();
foreach(['default_rule'=>['mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer'],'override'=>['mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer'],'seller'=>['seller','register','transfer']] as $name=>$names){
    $form=$fields($name);$reads=0;$reading=true;$form[$names[0]]->getDefaultState();$first=$reads;expect($first>0,$name.' uses real saved facts');
    foreach($names as $field){$form[$field]->getDefaultState();}same($first,$reads,$name.' defaults share the first read');$reading=false;
}

// An unchanged retained installation has no lifecycle settings and no implied support.
same(null,$saved(),'Old native settings have no inferred lifecycle facts');same(['grace'=>false,'restore'=>false],$cap(true),'Missing evidence closes both capabilities even with future acceptance');
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Not set')->assertSee('Late renewals are off for this TLD.')->assertDontSee('No lifecycle policy saved')->assertActionVisible($action);
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
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Restore fee')->assertSee('USD 80.00')->assertSee('Redemption period')->assertSee('30 days');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action);
same($valid['grace_verified_at'],$page->get('mountedActions.0.data.grace_verified_at'),'Reopening never refreshes verification time');

// Real invalid submissions show field guidance and preserve saved facts and audit.
$guidance=[
    'Complete lifecycle facts required.'=>'Complete all lifecycle fields. Nothing was saved.',
    'Independent lifecycle verification required.'=>'Verify the lifecycle facts and check the confirmation box. Nothing was saved.',
    'Lifecycle verification cannot be in the future.'=>'Enter verification times at or before now. Nothing was saved.',
    'Invalid lifecycle input.'=>'Reload the lifecycle form and check its fields. Nothing was saved.',
    'Invalid lifecycle support.'=>'Check the grace, restore, auto-renew and conflicting-source flags. Nothing was saved.',
    'Lifecycle days must be between 0 and 365.'=>'Enter whole numbers from 0 to 365 for grace and restore days. Nothing was saved.',
    'Invalid lifecycle charge.'=>'Enter valid non-negative grace and restore charges with at most four decimal places. Nothing was saved.',
    'Invalid lifecycle currency.'=>'Enter three uppercase letters for grace and restore currencies, for example USD. Nothing was saved.',
    'Lifecycle verification requires a date and time with UTC offset.'=>'Include a date, time and UTC offset in policy, grace and restore verification times. Nothing was saved.',
    'Invalid lifecycle verification time.'=>'Enter valid policy, grace and restore verification dates and times. Nothing was saved.',
    'Enter a one-line lifecycle source between 5 and 500 characters.'=>'Enter one-line policy, grace and restore sources from 5 to 500 characters. Nothing was saved.',
    'Conflicting lifecycle settings require repair.'=>'Ask an administrator to repair duplicate lifecycle settings before retrying. Nothing was saved.',
];
$unchanged=serialize(DB::table('settings')->get());
foreach([['grace_currency'=>'usd'],['verified_at'=>'2026-10-18T12:00:00']] as $change){
    $message=isset($change['grace_currency'])?'Invalid lifecycle currency.':'Lifecycle verification requires a date and time with UTC offset.';
    Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action)
        ->setActionData([...$valid,...$change,'verified'=>true,'reason'=>'Check invalid lifecycle fields'])->callMountedAction()->assertHasNoActionErrors()
        ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('Change refused')->body($guidance[$message]));
    same($unchanged,serialize(DB::table('settings')->get()),'Invalid submission saves no facts or audit');
}
foreach(['grace_days','restore_days'] as $field){
    $invalid=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action)
        ->setActionData([...$valid,$field=>366,'verified'=>true,'reason'=>'Check invalid lifecycle days'])->callMountedAction()
        ->assertHasActionErrors([$field=>'max']);
    same([$guidance['Lifecycle days must be between 0 and 365.']],$invalid->errors()->get('mountedActions.0.data.'.$field),'The field error sent to the form gives day-range guidance');
    same($unchanged,serialize(DB::table('settings')->get()),'Invalid day range saves no facts or audit');
}
$assertRefusal=static function(\Closure $attempt,string $message)use($guidance):void{
    try{$attempt();throw new LogicException('Invalid lifecycle change must refuse');}catch(DomainException $failure){
        same($message,$failure->getMessage(),'Real writer refuses the expected field');same($guidance[$message],SettingsActions::refusal($failure,'lifecycle'),'Safe field guidance');
    }
};
$changes=[['extra_field'=>true],['grace_supported'=>1]];
foreach(['grace_days','restore_days'] as $field){$changes[]=[$field=>-1];}
foreach(['grace_extra','restore_fee'] as $field){$changes[]=[$field=>'-1'];}
foreach(['grace_currency','restore_currency'] as $field){$changes[]=[$field=>'usd'];}
foreach(['','grace_','restore_'] as $prefix){$changes[]=[$prefix.'source'=>"bad\nsource"];$changes[]=[$prefix.'verified_at'=>'2026-10-18T12:00:00'];$changes[]=[$prefix.'verified_at'=>'2026-02-30T12:00:00Z'];}
foreach($changes as $change){
    try{$writer->run($review(),[...$valid,...$change,'verified'=>true],$now);throw new LogicException('Invalid lifecycle field must refuse');}
    catch(DomainException $failure){expect(isset($guidance[$failure->getMessage()]),'Every lifecycle refusal is allowlisted');same($guidance[$failure->getMessage()],SettingsActions::refusal($failure,'lifecycle'),'All sibling fields have safe guidance');}
}
$incomplete=$valid;unset($incomplete['grace_days']);$assertRefusal(fn()=>$writer->run($review(),[...$incomplete,'verified'=>true],$now),'Complete lifecycle facts required.');
same($unchanged,serialize(DB::table('settings')->get()),'Every refused field leaves facts and audit unchanged');
same('Reload and check current permissions, saved facts and acceptance.',SettingsActions::refusal(new RuntimeException('Unexpected synthetic detail'),'lifecycle'),'Unknown failure keeps the generic fallback');
// Binding drift must not break the modal or permit unseen policy defaults to be saved.
$account=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole();
DB::table('domains_accounts')->where('server_id',$t->server_id)->update(['stable_identity'=>encrypt('changed-placeholder-account',false)]);
$beforeDrift=serialize(DB::table('settings')->orderBy('id')->get());
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action)->assertActionMounted($action);
same(null,$page->get('mountedActions.0.data.grace_days'),'Unreadable lifecycle has no invented default');
$page->setActionData([...$valid,'verified'=>true,'reason'=>'Review changed account binding'])->callMountedAction()->assertNotified('Change refused');
same($beforeDrift,serialize(DB::table('settings')->orderBy('id')->get()),'Refused drifted form preserves lifecycle settings and audit');
// Even if the binding is repaired after opening, a fresh review of the saved facts is required.
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action)->assertActionMounted($action);
DB::table('domains_accounts')->where('server_id',$t->server_id)->update(['stable_identity'=>$account->getRawOriginal('stable_identity')]);
$page->setActionData([...$valid,'verified'=>true,'reason'=>'Refuse unseen restored policy'])->callMountedAction()->assertNotified('Change refused');
same($beforeDrift,serialize(DB::table('settings')->orderBy('id')->get()),'Repair after opening cannot save unseen lifecycle facts');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction($action)->assertActionMounted($action);
same($valid['grace_days'],(int)$page->get('mountedActions.0.data.grace_days'),'Fresh review after repair shows saved lifecycle facts');

// Every malformed field is tested against a complete, positive baseline.
foreach(Facts::FIELDS as $missing){$v=$valid;unset($v[$missing]);commerceReject(fn()=>Facts::validate($v));same(false,Facts::allows($v,'grace',$now),'Missing '.$missing.' never offers grace');}
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
        ['verified_at'=>$now->modify('+1 second')->format(DATE_ATOM)],[$which.'_verified_at'=>$now->modify('+1 second')->format(DATE_ATOM)]] as $change){
        same(false,Facts::allows([...$valid,...$change],$which,$now),'Unsafe '.$which.' evidence refuses: '.json_encode($change));
    }
    // Saved values stay until staff change them: old verification never closes the capability.
    $old=$now->modify('-400 days')->format(DATE_ATOM);
    same(true,Facts::allows([...$valid,'verified_at'=>$old,$which.'_verified_at'=>$old],$which,$now),'Old saved '.$which.' values still apply');
    same(true,Facts::allows($valid,$which,$now->modify('+10 years')),'Saved '.$which.' values never expire');
    same(true,Facts::allows([...$valid,'verified_at'=>$now->format(DATE_ATOM),$which.'_verified_at'=>$now->format(DATE_ATOM)],$which,$now),'Verification at the current instant applies');
}
same(false,Facts::allows([...$valid,'auto_renew_only'=>true],'grace',$now),'Auto-renew-only ending cannot use grace renewal');
same(true,Facts::allows([...$valid,'auto_renew_only'=>true],'restore',$now),'Auto-renew-only flag does not invent a restore prohibition');
same(false,Facts::allows($valid,'register',$now),'Unknown action refuses');
$offset=[...$valid,'verified_at'=>'2026-10-18T05:00:00-07:00','grace_verified_at'=>'2026-10-18T14:00:00+02:00'];
same(true,Facts::allows($offset,'grace',$now),'Verification uses instants across UTC offsets');
same(false,Facts::allows([...$valid,'grace_verified_at'=>'2026-10-18T14:00:01+02:00'],'grace',$now->modify('-2 hours')),'Offset instants after now refuse');
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
same(null,$saved(),'Duplicate evidence refuses');$assertRefusal(fn()=>$writer->run($review(),[...$valid,'verified'=>true],$now),'Conflicting lifecycle settings require repair.');$duplicate->delete();
Schema::table('settings',fn($table)=>$table->unique(['key','settingable_id','settingable_type']));
DB::table($t->getTable())->where('id',$t->id)->update(['account_fingerprint'=>$other->fingerprint]);same(null,$saved(),'Corrupt TLD account refuses');
DB::table($t->getTable())->where('id',$t->id)->update(['account_fingerprint'=>$b->fingerprint]);$t->refresh();
expect($cap()['grace'],'Restored binding revalidates; no terminal marker');

// Staff refusal, stale review and repeat verification are local, atomic changes.
$before=serialize(DB::table('settings')->get());
commerceReject(fn()=>$writer->run($review(),[...$valid,'verified'=>true,'restore_accepted'=>true],$now));
foreach([['grace_days'=>1.5],['restore_days'=>true],['grace_days'=>'30.0']] as $change){commerceReject(fn()=>$writer->run($review(),[...$valid,...$change,'verified'=>true],$now));}
foreach([false,'1',null] as $verified){$assertRefusal(fn()=>$writer->run($review(),[...$valid,'verified'=>$verified],$now),'Independent lifecycle verification required.');}
foreach(['verified_at','grace_verified_at','restore_verified_at'] as $field){$assertRefusal(fn()=>$writer->run($review(),[...$valid,$field=>$now->modify('+1 second')->format(DATE_ATOM),'verified'=>true],$now),'Lifecycle verification cannot be in the future.');}
same($before,serialize(DB::table('settings')->get()),'Refused writes leave settings and audit unchanged');
$stale=$review();$writer->run($review(),[...$valid,'conflicting'=>true,'verified'=>true],$now);same(['grace'=>false,'restore'=>false],$cap(true),'Conflict disables both');
commerceReject(fn()=>$writer->run($stale,[...$valid,'verified'=>true],$now));
$writer->run($review(),[...$valid,'verified'=>true],$now);same(['grace'=>true,'restore'=>false],$cap(),'Resolving conflict can enable policy again without enabling restore');
foreach([false,true,false,true] as $support){$writer->run($review(),[...$valid,'grace_supported'=>$support,'verified'=>true],$now);same($support,$cap()['grace'],'Disable and reverify across cycles');}
$old=[...$valid,'verified_at'=>$now->modify('-400 days')->format(DATE_ATOM)];$writer->run($review(),[...$old,'verified'=>true],$now);same(true,$cap()['grace'],'Saved facts stay until staff change them, however old');
same($old['verified_at'],$saved()['verified_at'],'Saving keeps the entered verification time');
$writer->run($review(),[...$valid,'verified'=>true],$now);same(true,$cap()['grace'],'New verification keeps policy on');
$before=serialize(DB::table('settings')->get());
foreach([Staff::VIEW,Staff::COSTS,Staff::POLICY,Review::permission('lifecycle')] as $removed){
    $u->role->update(['permissions'=>array_values(array_diff($permissions,[$removed]))]);
    commerceReject(fn()=>$writer->run($review(),[...$valid,'verified'=>true],$now));
    if($removed!==Staff::VIEW){Livewire::actingAs($u->fresh())->test(ViewTld::class,['record'=>$t->id])->assertActionHidden($action);}
}
$u->role->update(['permissions'=>[Staff::VIEW]]);
Livewire::actingAs($u->fresh())->test(ViewTld::class,['record'=>$t->id])->assertSee('Last saved '.$now->setTimezone(new DateTimeZone('UTC'))->format('M j, Y'))->assertDontSee('USD 80.00')->assertDontSee('Restore fee')->assertActionHidden($action);
$u->role->update(['permissions'=>$permissions]);
foreach([
    new Review($u->id,$other->serverId,$t->id,'lifecycle',Review::ticket($u->id,$other->serverId,$t->id,'lifecycle',Review::stamp($other->serverId,$t->id)),'Foreign account review'),
    new Review($u->id,$t->server_id,$t->id,'lifecycle',Review::ticket($u->id,$t->server_id,$t->id,'override',Review::stamp($t->server_id,$t->id)),'Wrong action review'),
    new Review($u->id,$t->server_id,null,'lifecycle',Review::ticket($u->id,$t->server_id,null,'lifecycle',Review::stamp($t->server_id,null)),'Missing TLD review'),
] as $r){commerceReject(fn()=>$writer->run($r,[...$valid,'verified'=>true],$now));}
same($before,serialize(DB::table('settings')->get()),'Denied and foreign writes leave all settings unchanged');
expect(!str_contains(serialize(Policies::facts($t,$now,false)),'80.0000'),'Cost-free staff projection hides registrar charges');
echo "Lifecycle facts, native settings, pricing, capabilities, audit and refusal branches passed.\n";$nativeSuccessful=true;
