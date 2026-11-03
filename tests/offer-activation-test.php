<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Role,User,Setting};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,UpgradeGate,UpgradeComparison};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,Installation,NativeCatalogue,Runtime,StaffSettingsReview};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
// Installed native/source tables are essential: activation must compare the entire retained graph.
(new Installation)->install(); extract(commerceFixture(true));
app()->instance(AccountBindings::class,$bindings);app()->instance(NativeCatalogue::class,$native);
app()->instance(Clock::class,new FakeClock($now));Runtime::register();
$permissions=[Staff::VIEW,Staff::POLICY,Staff::COSTS,StaffSettingsReview::permission('activate')];
$role=Role::create(['name'=>'Synthetic cutover staff','permissions'=>$permissions]);
$staff=User::create(['first_name'=>'Synthetic','last_name'=>'Cutover','email'=>'cutover@example.test','password'=>'synthetic-password','role_id'=>$role->id]);
auth()->login($staff);$gate=new UpgradeGate;$generation=$gate->pause();$evidence=$gate->capture();
same(true,is_callable([$gate,'activate']),'Checked activation must be an installed maintenance method');
$reject=static function(callable $call,string $reason)use($reads):void {
    $before=UpgradeComparison::capture();$generation=UpgradeGate::generation();$calls=$reads->calls;$refused=false;
    try{$call();}catch(DomainException){$refused=true;}
    same(true,$refused,$reason);same($generation,UpgradeGate::generation(),'Refusal preserves generation');
    same($before,UpgradeComparison::capture(),'Refusal preserves native and financial/source evidence');
    same($calls,$reads->calls,'Activation refuses without provider reads');
};
$reject(fn()=>$gate->activate($evidence),'Incomplete conversion refuses');
foreach(Account::orderBy('server_id')->get() as $a){$b=CatalogueAcceptances::binding($a);do{$r=$gate->batch($b,$evidence);}while(!$r['complete']);}
same(true,$gate->check($evidence)['compatible'],'Complete unknown-facts staging is compatible');
$changed=static function(string $table,int $id,string $column,mixed $value,string $reason)use($reject,$gate,&$evidence):void {
    $q=DB::table($table)->where('id',$id);$old=$q->value($column);$q->update([$column=>$value]);
    $reject(fn()=>$gate->activate($evidence),$reason);$q->update([$column=>$old]);
};
$t=$tlds[0];$account=Account::where('server_id',$t->server_id)->sole();
foreach(['catalogue_facts'=>'{}','catalogue_intent'=>'null','catalogue_published_revision'=>'{}','catalogue_retained_annual_retail'=>'999.00'] as $column=>$value){$changed('domains_tlds',$t->id,$column,$value,'Changed staging refuses: '.$column);}
$price=DB::table('domains_prices')->where('server_id',$t->server_id)->first();
$changed('domains_prices',$price->id,'wholesale','999.00','Changed retained cost refuses');
$product=$native->product($t,'register');$changed('products',$product->id,'name','Changed native identity','Changed native graph refuses');
$changed('domains_accounts',$account->id,'processing_enabled',!$account->processing_enabled,'Changed account processing refuses');
$changed((new App\Models\Server)->getTable(),$account->server_id,'enabled',false,'Disabled account refuses');
$secret=DB::table('settings')->where('settingable_id',$account->server_id)->where('key','domains_credentials')->first();
$changed('settings',$secret->id,'value','synthetic altered ciphertext','Same-identity credential change refuses');
$queue=DB::table('domains_observation_queues')->where('server_id',$t->server_id)->first();
foreach(['catalogue_claim','offer_claim'] as $claim){foreach([0,$now->getTimestamp()+300] as $lease){
    DB::table('domains_observation_queues')->where('id',$queue->id)->update([$claim=>str_repeat('a',32),$claim==='offer_claim'?'offer_lease':'catalogue_lease'=>$lease]);
    $reject(fn()=>$gate->activate($evidence),'Claims must drain even when expired');
    DB::table('domains_observation_queues')->where('id',$queue->id)->update([$claim=>null,$claim==='offer_claim'?'offer_lease':'catalogue_lease'=>0]);
}}
$admission=app(Paymenter\Extensions\Servers\Domains\Services\CartAdmission::class);
app()->instance($admission::class,new class($native,$sellers,app(Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility::class),app(Clock::class)) extends Paymenter\Extensions\Servers\Domains\Services\CartAdmission {
    protected function catalogue():?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers{return null;}
});
$reject(fn()=>$gate->activate($evidence),'Partial installed adapter refuses');app()->instance($admission::class,$admission);
$role->update(['permissions'=>[Staff::VIEW]]);$reject(fn()=>$gate->activate($evidence),'Revoked actor permission refuses');$role->update(['permissions'=>$permissions]);
$gate->resume();$gate->pause();$reject(fn()=>$gate->activate($evidence),'Old generation capture refuses after rollback');
$evidence=$gate->capture();same(true,$gate->check($evidence)['compatible'],'Fresh capture remains compatible');
$result=$gate->activate($evidence);
same(true,$result['activated'],'The checked method commits the sole switch');same($evidence['generation']+1,$result['generation'],'Exactly one active generation increment');
expect((bool)preg_match('/^[a-f0-9]{64}$/D',$result['digest']),'Safe digest-only receipt');
commerceReject(fn()=>$gate->activate($evidence));commerceReject(fn()=>$gate->resume());
same(0,$reads->calls,'Activation and bounded wake never resolve a provider reader');
$nativeSuccessful=true;echo "Checked installed activation and refusal boundaries passed.\n";
