<?php
// Native action submissions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\SalesPolicy;
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities,Disposition,DriverResult,Evidence,ResultReason,SendState};
extract(commerceFixture(true));\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
// Staff actions read the engine clock; pin it so fixture price freshness never depends on the wall clock.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($now) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock{public function __construct(private DateTimeImmutable $at){} public function now():DateTimeImmutable{return $this->at;}});
$registry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom',['uid','token'],new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier{
    public function verify(\Paymenter\Extensions\Servers\Domains\Data\Environment $environment,#[SensitiveParameter] array $credentials): string{return $credentials['token'];}
},static function($binding,$credentials){
    expect(Illuminate\Support\Facades\DB::transactionLevel()===0,'Injected native probe outside locks');
    return new \Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,new Capabilities($binding,[]),
        new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(balance:new \Paymenter\Extensions\Servers\Domains\Data\Money('9.99','USD'))));
});
$fixtureBindings=$bindings;$bindings=new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
$permissions=[Staff::VIEW,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $action){$permissions[]=Review::permission($action);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);app()->instance(StaffSettingsWrites::class,$writer);
$fixed=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'20','fixed_renew'=>'21','fixed_transfer'=>'22'];
// "Use the account default" is a choice in Edit prices; it saves as remove_override.
$inputs=['card_fee'=>['fee_percent'=>'3.10','fee_fixed'=>'0.40'],'default_rule'=>$fixed,'override'=>['pricing'=>'custom',...$fixed],'remove_override'=>['pricing'=>'default'],
    'tld_settings'=>['min_years'=>1,'max_years'=>3,'enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true],
    'seller'=>['seller'=>$t->server_id,'register'=>true,'transfer'=>true],
    'acceptance'=>['ending'=>'com','accept'=>true,'verified'=>true,'terms'=>[1,3],'registry'=>true,'transfer'=>true],
    'history'=>['history_action'=>'register','accept'=>true,'verified'=>true,'timezone'=>'America/Los_Angeles','lag'=>60,'retention'=>86400],
    'configure'=>['registrar'=>'enom','environment'=>'test','uid'=>'synthetic-user','token'=>'account-enom'],'probe'=>[]];
$accountSide=['card_fee','default_rule','acceptance','history','configure','probe'];
$mount=static function(string $action)use($u,$t,$accountSide){
    $setup=in_array($action,$accountSide,true);
    $name=$setup?setupAction($action,$t->server_id):($action==='remove_override'?'override':$action);
    return Livewire::actingAs($u)->test($setup?RegistrarAccount::class:ViewTld::class,$setup?['account'=>$t->server_id]:['record'=>$t->id])->mountAction($name)->assertActionMounted($name);
};
foreach($inputs as $action=>$input){
    echo 'Native action: '.$action."\n";
    $setup=in_array($action,$accountSide,true);$ending=$setup?null:$t->id;
    $page=$mount($action);$key=$page->get('mountedActions.0.data.'.($action==='remove_override'?'default_key':'review_key'));
    $defaults=$page->get('mountedActions.0.data');
    if($action==='default_rule'){same('markup_percent',$defaults['mode'],'Current default mode prefilled');}
    if($action==='tld_settings'){same(1,(int)$defaults['min_years'],'Current minimum prefilled');same(3,(int)$defaults['max_years'],'Current maximum prefilled');}
    if($action==='seller'){same($t->server_id,(int)$defaults['seller'],'Current seller prefilled');expect($defaults['register']&&$defaults['transfer'],'Current seller actions prefilled');}
    if($action==='configure'){same('enom',$defaults['registrar'],'Bound registrar prefilled');same('test',$defaults['environment'],'Bound environment prefilled');expect(empty($defaults['token'])&&empty($defaults['uid']),'Credentials never prefilled');}
    $page->setActionData([...$input,'reason'=>"Reviewed\u{2028}settings"])->callMountedAction()->assertHasActionErrors(['reason'=>'regex']);
    same($key,$page->get('mountedActions.0.data.'.($action==='remove_override'?'default_key':'review_key')),'Settings reason error keeps the current review open');
    $page->setActionData([...$input,'reason'=>'Confirm native action with saved facts'])->callMountedAction()->assertHasNoActionErrors()
        ->assertNotified($action==='card_fee'?\Filament\Notifications\Notification::make()->success()->title('Saved')->body('Card fee saved. No TLDs count the card fee in their prices, so no prices changed.'):($setup?($action==='probe'?'Balance updated':'Saved'):(in_array($action,['override','remove_override'],true)?'Prices published':'Change recorded')))->assertSet('mountedActions',[]);
    if($action==='remove_override'){expect($t->fresh()->use_default_rule,'Account default chosen in Edit prices');}
    $review=new Review($u->id,$t->server_id,$ending,$action,$key,'Replay must refuse this native action');
    tldRefusal(fn()=>$writer->run($review,$input,$now),in_array($action,['tld_settings','probe'],true)?'Review key used.':'Settings changed. Reload this page.');
    $page=$mount($action);SalesPolicy::where('tld','com')->increment('revision');
    $page->setActionData([...$input,'reason'=>'Refuse stale native action after change'])->callMountedAction()->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->danger()->title($setup?"Couldn't save":'Change refused')->body('Settings changed. Reload this page.'))->assertSet('mountedActions',[]);
    $key=Review::ticket($u->id,$t->server_id,$ending,$action,Review::stamp($t->server_id,$ending));
    $u->role->update(['permissions'=>array_values(array_diff($permissions,[Review::permission($action)]))]);
    commerceReject(fn()=>$writer->run(new Review($u->id,$t->server_id,$ending,$action,$key,'Permission revoked before submission'),$input,$now));
    if(in_array($action,['override','remove_override'],true)){
        // Edit prices offers only the choices this staff member may save.
        $choices=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('override')->assertActionMounted('override')->get('mountedActions.0.data.pricing');
        same($action==='override'?'default':'custom',$choices,'Only the permitted pricing choice is offered');
    }
    $u->role->update(['permissions'=>$permissions]);
}
// Saving prices publishes them, so price dialogs need the publish permission too.
$u->role->update(['permissions'=>array_values(array_diff($permissions,[Review::permission('publish')]))]);
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('override')->assertActionVisible('tld_settings');
Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSuccessful()->assertSee('Default pricing')->assertDontSee('Edit default pricing');
$u->role->update(['permissions'=>$permissions]);
$rows=Review::historyRows(App\Models\Server::find($t->server_id));
foreach(array_keys($inputs) as $action){$label=Review::ACTIONS[$action];expect(count(array_filter($rows,fn($r)=>$r['action']===$label))>=1,'Every allowed native action audited');}
$archives=Illuminate\Support\Facades\DB::table(AuditArchives::TABLE)->where('server_id',$t->server_id)->where('family','staff_settings')->count();
$rotation=null;
for($i=0;$i<30&&Illuminate\Support\Facades\DB::table(AuditArchives::TABLE)->where('server_id',$t->server_id)->where('family','staff_settings')->count()===$archives;$i++){
    $key=Review::ticket($u->id,$t->server_id,$t->id,'default_rule',Review::stamp($t->server_id,$t->id));
    $entry=new Review($u->id,$t->server_id,$t->id,'default_rule',$key,trim(str_repeat('Retain reviewed pricing evidence. ',12)));$rotation??=$entry;
    $entry->local(static fn()=>null);
}
expect(Illuminate\Support\Facades\DB::table(AuditArchives::TABLE)->where('server_id',$t->server_id)->where('family','staff_settings')->count()>$archives,'A new encrypted audit archive is created');
foreach(array_column($rows,'key') as $used){expect(Illuminate\Support\Facades\DB::table(Review::KEYS)->where('server_id',$t->server_id)->where('review_key',$used)->exists(),'Earlier keys retained independently of audit archives');}
expect($rotation!==null,'Rollover exercised a successful unchanged-stamp action');
tldRefusal(fn()=>$rotation->local(static fn()=>null),'Review key used.');
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
// Setup buttons that can't run are hidden (layout.md section 6); the nested Test connection button leaves the schema.
Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertActionHidden(setupAction('configure',$t->server_id))->assertActionDoesNotExist(setupAction('probe',$t->server_id));
same('9.99',Illuminate\Support\Facades\DB::table('domains_registrar_balances')->where('server_id',$t->server_id)->value('amount'),'Existing account probe records its balance');
// Submit the real seller action with each expired required cost; no provider read or policy write.
$providerReads=$reads->calls;
// Restore the original synthetic reader binding after the configure-action credential rotation.
$fixtureBindings->configure($t->server_id,'enom',\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,['token'=>'account-enom']);
$fixtureBindings->setProcessing($t->server_id, true, syntheticActivation());
// Earlier acceptance actions deliberately invalidate publication proof; import fresh synthetic evidence.
$enomAccount=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole();
$enomBinding=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($enomAccount);
$acceptance=\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::load($enomBinding);
$rates=[];foreach([['register',1,'6.00'],['register',3,'6.00'],['renew',1,'8.00'],['transfer',1,'7.00']] as [$action,$term,$perYear]){$rates[]=new \Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action,$term,$perYear,'synthetic',$now,$now->modify('+1 day'));}
$prices->import(new \Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($enomBinding,'com',$rates,[1,3],true,true),$acceptance,static fn()=>null,$now);
$catalogueConfig=config('domains.enom_catalogue.enabled');$identityConfig=config('domains.enom_identity.environment');
config(['domains.enom_identity.environment'=>'test']);
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
foreach([[$tlds[1],true,false],[$tlds[0],true,false],[$tlds[0],false,false],[$tlds[1],true,true]] as [$target,$enabled,$held]){
 config(['domains.enom_catalogue.enabled'=>$enabled]);
 $targetAccount=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$target->server_id)->sole();
 Illuminate\Support\Facades\DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$target->server_id],['account_fingerprint'=>$targetAccount->account_fingerprint,'catalogue_hold'=>$held?'read_unavailable':null]);
 foreach(['register'=>'registration','renew'=>'renewal'] as $action=>$label){
    $costs=\Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$target->server_id,'tld'=>'com','action'=>$action])->orderBy('term')->get();
    foreach($costs as $cost){Illuminate\Support\Facades\DB::table('domains_prices')->where('id',$cost->id)->update(['fresh_until'=>$now]);}
    $prior=SalesPolicy::where('tld','com')->sole()->getAttributes();
    $remedy=$target->registrar_key==='enom'&&$enabled?"On the account page for account #{$target->server_id}, use Sync TLD prices to refresh com costs, then try again."
        :($target->registrar_key==='enom'?'Sync TLD prices is unavailable for this account; ask the installation administrator to restore TEST catalogue import and refresh costs before trying again.':"Check Cost refresh on .com for account #{$target->server_id}; wait for fresh costs from its scheduled catalogue read, or ask the installation administrator to restore reads if refresh failed or was interrupted.");
    $registrar=$target->registrar_key==='enom'?'Enom':'NameSilo';
    $body="Cannot select {$registrar} account #{$target->server_id} for .com: its {$label} cost for 1 year expired at 2026-10-18 12:00 UTC. ".$remedy;
    $mount('seller')->setActionData(['seller'=>$target->server_id,'register'=>true,'transfer'=>false,'reason'=>'Review expired seller cost evidence'])->callMountedAction()->assertHasNoActionErrors()->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('Change refused')->body($body));
    same($prior,SalesPolicy::where('tld','com')->sole()->getAttributes(),'Expired seller toast leaves prior seller and revision unchanged');
    same($held?'read_unavailable':null,Illuminate\Support\Facades\DB::table('domains_observation_queues')->where('server_id',$target->server_id)->value('catalogue_hold'),'Refusal preserves selected account refresh hold');
    foreach($costs as $cost){Illuminate\Support\Facades\DB::table('domains_prices')->where('id',$cost->id)->update(['fresh_until'=>$cost->getRawOriginal('fresh_until')]);}
 }
}
config(['domains.enom_catalogue.enabled'=>$catalogueConfig,'domains.enom_identity.environment'=>$identityConfig]);
same($providerReads,$reads->calls,'Seller refusal makes no provider read');
echo "Ten native action submissions, stale refusals, replay, fresh denial and retained audit passed.\n";$nativeSuccessful=true;
