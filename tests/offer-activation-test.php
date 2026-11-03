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
// Pending/paid/uncertain evidence must survive conversion byte-for-byte; it grants no new send.
$owner = commerceUser(); $product = $native->product($tlds[0], 'register');
$order = App\Models\Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$service = App\Models\Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id,
    'plan_id' => $product->plans()->where('billing_period', 1)->sole()->id, 'quantity' => 1,
    'price' => '10.40', 'currency_code' => 'USD', 'status' => 'active']);
$domain = DB::table('domains_domains')->insertGetId(['server_id' => $tlds[0]->server_id,
    'account_fingerprint' => $tlds[0]->account_fingerprint, 'registrar_key' => 'enom', 'service_id' => $service->id,
    'user_id' => $owner->id, 'domain' => 'retained-placeholder.com', 'reservation_name' => 'retained-placeholder.com', 'sld' => 'retained-placeholder',
    'tld' => 'com', 'status' => 'active', 'snapshot' => 'synthetic frozen snapshot']);
foreach (['awaiting_payment', 'queued', 'uncertain'] as $i => $state) {
    $invoice = App\Models\Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => $i === 0 ? 'pending' : 'paid']);
    $item = App\Models\InvoiceItem::create(['invoice_id' => $invoice->id, 'reference_type' => $service->getMorphClass(),
        'reference_id' => $service->id, 'description' => 'Synthetic retained obligation', 'quantity' => 1, 'price' => '10.40']);
    if ($i > 0) { App\Models\InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => '10.40', 'fee' => '0.00', 'status' => 'succeeded']); }
    DB::table('domains_operations')->insert(['server_id' => $tlds[0]->server_id, 'registrar_key' => 'enom',
        'account_fingerprint' => $tlds[0]->account_fingerprint, 'domain_id' => $domain, 'service_id' => $service->id,
        'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'operation_key' => 'synthetic-upgrade-'.$i,
        'action' => 'renew', 'state' => $state, 'amount' => '10.40', 'currency' => 'USD',
        'cost' => 'synthetic frozen ceiling', 'attempt' => 'synthetic frozen attempt', 'funding' => 'synthetic frozen funding']);
}

App\Models\Credit::create(['user_id'=>$owner->id,'currency_code'=>'USD','amount'=>'4.00']);

app()->instance(AccountBindings::class,$bindings);app()->instance(NativeCatalogue::class,$native);
app()->instance(Clock::class,new FakeClock($now));Runtime::register();
$permissions=array_keys(Staff::permissions());
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
$seller=DB::table('domains_sales_policies')->first();$changed('domains_sales_policies',$seller->id,'revision',$seller->revision+1,'Changed seller revision refuses');
foreach(['invoices'=>['status','cancelled'],'invoice_items'=>['price','11.00'],'credits'=>['amount','9.00'],'domains_operations'=>['attempt','changed frozen attempt']] as $table=>[$column,$value]) {
    $row=DB::table($table)->orderBy('id')->first();$changed($table,$row->id,$column,$value,'Changed financial obligation refuses: '.$table);
}
$accountRows=DB::table('domains_accounts')->where('id',$account->id)->first();
$changed('domains_accounts',$account->id,'registrar_key','namesilo','Changed account membership refuses');
$secret=DB::table('settings')->where('settingable_id',$account->server_id)->where('key','domains_credentials')->first();
$changed('settings',$secret->id,'value','synthetic altered ciphertext','Same-identity credential change refuses');
$queue=DB::table('domains_observation_queues')->where('server_id',$t->server_id)->first();
foreach(['catalogue_claim','offer_claim'] as $claim){foreach([0,$now->getTimestamp()+300] as $lease){
    DB::table('domains_observation_queues')->where('id',$queue->id)->update([$claim=>str_repeat('a',32),$claim==='offer_claim'?'offer_lease':'catalogue_lease'=>$lease]);
    $reject(fn()=>$gate->activate($evidence),'Claims must drain even when expired');
    DB::table('domains_observation_queues')->where('id',$queue->id)->update([$claim=>null,$claim==='offer_claim'?'offer_lease':'catalogue_lease'=>0]);
}}
foreach([0,$now->getTimestamp()+300] as $lease) {
    $id=DB::table('domains_read_budgets')->insertGetId(['server_id'=>$account->server_id,'account_fingerprint'=>$account->account_fingerprint,'window_at'=>0,'leases'=>json_encode(['synthetic'=>$lease])]);
    $reject(fn()=>$gate->activate($evidence),'Read reservation must release even when expired');DB::table('domains_read_budgets')->where('id',$id)->delete();
}
$state=Setting::where('key',UpgradeGate::KEY)->sole();$raw=$state->getRawOriginal('value');
$bad=$state->replicate();$bad->saveQuietly();commerceReject(fn()=>$gate->activate($evidence));$bad->deleteQuietly();
DB::table('settings')->where('id',$state->id)->update(['value'=>'invalid ciphertext']);commerceReject(fn()=>$gate->activate($evidence));DB::table('settings')->where('id',$state->id)->update(['value'=>$raw]);
$admission=app(Paymenter\Extensions\Servers\Domains\Services\CartAdmission::class);
app()->instance($admission::class,new class($native,$sellers,app(Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility::class),app(Clock::class)) extends Paymenter\Extensions\Servers\Domains\Services\CartAdmission {
    protected function catalogue():?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers{return null;}
});
$reject(fn()=>$gate->activate($evidence),'Partial installed adapter refuses');app()->instance($admission::class,$admission);
$role->update(['permissions'=>[Staff::VIEW]]);$reject(fn()=>$gate->activate($evidence),'Revoked actor permission refuses');$role->update(['permissions'=>$permissions]);
$gate->resume();$gate->pause();$reject(fn()=>$gate->activate($evidence),'Old generation capture refuses after rollback');
$evidence=$gate->capture();same(true,$gate->check($evidence)['compatible'],'Fresh capture remains compatible');
$lockOrder=[];$recordLocks=true;DB::listen(static function($q)use(&$lockOrder,&$recordLocks):void {if($recordLocks&&preg_match('/^select \* from [\"`]([a-z_]+)[\"`]/',$q->sql,$m)){$lockOrder[]=$m[1];}});
$result=$gate->activate($evidence);$recordLocks=false;
expect(array_search('invoices',$lockOrder,true)<array_search('extensions',$lockOrder,true),'Complete native financial lock prefix precedes the server/account/gate prefix');
same(true,$result['activated'],'The checked method commits the sole switch');same($evidence['generation']+1,$result['generation'],'Exactly one active generation increment');
expect((bool)preg_match('/^[a-f0-9]{64}$/D',$result['digest']),'Safe digest-only receipt');
commerceReject(fn()=>$gate->activate($evidence));commerceReject(fn()=>$gate->resume());
same(0,$reads->calls,'Activation and bounded wake never resolve a provider reader');
same(true,UpgradeComparison::compare($evidence['before'])['compatible'],'Activation preserves retained native identities and pending/paid/uncertain financial evidence');
foreach(['contract','digest'] as $field){$state=Setting::where('key',UpgradeGate::KEY)->sole();$raw=$state->getRawOriginal('value');$v=json_decode($state->value,true);$v[$field]=[];$state->value=json_encode($v);$state->saveQuietly();commerceReject(fn()=>CallerRouting::resolve(Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts::class));DB::table('settings')->where('id',$state->id)->update(['value'=>$raw]);}
$nativeSuccessful=true;echo "Checked installed activation and refusal boundaries passed.\n";
