<?php
// Native action submissions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\SalesPolicy;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities,Disposition,DriverResult,Evidence,ResultReason,SendState};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];
// Staff actions read the engine clock; pin it so fixture price freshness never depends on the wall clock.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($now) implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock{public function __construct(private DateTimeImmutable $at){} public function now():DateTimeImmutable{return $this->at;}});
$registry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom',['uid','token'],new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier{
    public function verify(\Paymenter\Extensions\Servers\Domains\Data\Environment $environment,#[SensitiveParameter] array $credentials): string{return $credentials['token'];}
},static function($binding,$credentials){
    expect(Illuminate\Support\Facades\DB::transactionLevel()===0,'Injected native probe outside locks');
    return new \Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,new Capabilities($binding,[]),
        new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence));
});
$bindings=new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry);
$permissions=[Staff::VIEW,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $action){$permissions[]=Review::permission($action);}
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings,true);app()->instance(StaffSettingsWrites::class,$writer);
$fixed=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'20','fixed_renew'=>'21','fixed_transfer'=>'22'];
$inputs=['default_rule'=>$fixed,'override'=>$fixed,'remove_override'=>[],
    'tld_settings'=>['min_years'=>1,'max_years'=>3,'enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true],
    'publish'=>[],'seller'=>['seller'=>$t->server_id,'register'=>true,'transfer'=>true],
    'acceptance'=>['ending'=>'com','accept'=>true,'verified'=>true,'terms'=>[1,3],'registry'=>true,'transfer'=>true],
    'history'=>['history_action'=>'register','accept'=>true,'verified'=>true,'timezone'=>'America/Los_Angeles','lag'=>60,'retention'=>86400],
    'configure'=>['registrar'=>'enom','environment'=>'test','uid'=>'synthetic-user','token'=>'account-enom'],'probe'=>[]];
$mount=static function(string $action)use($u,$t){
    $setup=in_array($action,['acceptance','history','configure','probe'],true);
    $name=$setup?\Filament\Actions\Testing\TestAction::make($action.'_'.$t->server_id)->schemaComponent('account_'.$t->server_id,'content'):$action;
    return Livewire::actingAs($u)->test($setup?RegistrarSetup::class:ViewTld::class,$setup?[]:['record'=>$t->id])->mountAction($name)->assertActionMounted($name);
};
foreach($inputs as $action=>$input){
    echo 'Native action: '.$action."\n";
    $setup=in_array($action,['acceptance','history','configure','probe'],true);$ending=$setup?null:$t->id;
    $page=$mount($action);$key=$page->get('mountedActions.0.data.review_key');
    $defaults=$page->get('mountedActions.0.data');
    if($action==='default_rule'){same('markup_percent',$defaults['mode'],'Current default mode prefilled');}
    if($action==='tld_settings'){same(1,(int)$defaults['min_years'],'Current minimum prefilled');same(3,(int)$defaults['max_years'],'Current maximum prefilled');}
    if($action==='seller'){same($t->server_id,(int)$defaults['seller'],'Current seller prefilled');expect($defaults['register']&&$defaults['transfer'],'Current seller actions prefilled');}
    if($action==='configure'){same('enom',$defaults['registrar'],'Bound registrar prefilled');same('test',$defaults['environment'],'Bound environment prefilled');expect(empty($defaults['token'])&&empty($defaults['uid']),'Credentials never prefilled');}
    $page->setActionData([...$input,'reason'=>'Confirm native action with saved facts'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded')->assertSet('mountedActions',[]);
    $review=new Review($u->id,$t->server_id,$ending,$action,$key,'Replay must refuse this native action');
    commerceReject(fn()=>$writer->run($review,$input,$now));
    $page=$mount($action);SalesPolicy::where('tld','com')->increment('revision');
    $page->setActionData([...$input,'reason'=>'Refuse stale native action after change'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change refused')->assertSet('mountedActions',[]);
    $key=Review::ticket($u->id,$t->server_id,$ending,$action,Review::stamp($t->server_id,$ending));
    $u->role->update(['permissions'=>array_values(array_diff($permissions,[Review::permission($action)]))]);
    commerceReject(fn()=>$writer->run(new Review($u->id,$t->server_id,$ending,$action,$key,'Permission revoked before submission'),$input,$now));
    $u->role->update(['permissions'=>$permissions]);
}
$rows=Review::historyRows(App\Models\Server::find($t->server_id));
foreach(array_keys($inputs) as $action){$label=Review::ACTIONS[$action];expect(count(array_filter($rows,fn($r)=>$r['action']===$label))>=1,'Every allowed native action audited');}
$rotation=null;
for($i=0;$i<30&&!App\Models\Server::find($t->server_id)->settings()->where('key','like',Review::KEY.'.%')->exists();$i++){
    $key=Review::ticket($u->id,$t->server_id,$t->id,'default_rule',Review::stamp($t->server_id,$t->id));
    $entry=new Review($u->id,$t->server_id,$t->id,'default_rule',$key,trim(str_repeat('Retain reviewed pricing evidence. ',12)));$rotation??=$entry;
    $writer->run($entry,$fixed,$now);
}
expect(App\Models\Server::find($t->server_id)->settings()->where('key','like',Review::KEY.'.%')->exists(),'Encrypted audit rotates without losing replay history');
expect(!array_diff(array_column($rows,'key'),array_column(Review::historyRows(App\Models\Server::find($t->server_id)),'key')),'All earlier keys retained across archive');
if($rotation){commerceReject(fn()=>$writer->run($rotation,$fixed,$now));}
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
foreach(['configure','probe'] as $action){$name=\Filament\Actions\Testing\TestAction::make($action.'_'.$t->server_id)->schemaComponent('account_'.$t->server_id,'content');Livewire::actingAs($u)->test(RegistrarSetup::class)->assertActionDisabled($name);}
echo "Ten native action submissions, stale refusals, replay, fresh denial and retained audit passed.\n";$nativeSuccessful=true;
