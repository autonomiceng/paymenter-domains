<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Retained legacy NameSilo read authority only.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/observation-work.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,DomainObservation,LocalAccountEnrollment,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport,TransportResponse};
extract(commerceFixture(true,nameSiloLocal:false));
$binding=AccountBindings::binding(Account::where('server_id',$tlds[1]->server_id)->sole());$ns=$binding->serverId;
// This native service/checkout identity predates today's closed legacy acquisition gate.
// No acquisition, completion receipt or spending authority is manufactured by this storage fixture.
$seed=$native->product($tlds[0],'register');$seedPlan=$native->annualPlan($seed);
$product=$seed->replicate();$product->server_id=$ns;$product->slug=\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::slug($tlds[1],'register');$product->stock=0;App\Models\Product::withoutEvents(static fn()=>$product->save());
foreach($seed->settings()->get() as $setting){
    $copy=$setting->replicate();$copy->settingable_id=$product->id;
    if($copy->key==='domains_tld_id'){$copy->value=(string)$tlds[1]->id;}if($copy->key==='domains_registrar'){$copy->value='namesilo';}$copy->save();
}
$plan=$seedPlan->replicate();$plan->priceable_id=$product->id;$plan->save();
foreach($seedPlan->prices()->get() as $price){$copy=$price->replicate();$copy->plan_id=$plan->id;$copy->save();}
$owner=commerceUser();
$order=App\Models\Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$service=App\Models\Service::withoutEvents(static fn()=>App\Models\Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'active']));
App\Models\Property::withoutEvents(static fn()=>$service->properties()->create(['key'=>'domain','value'=>'legacy-observed-placeholder.com']));
$d=Domain::create(['domain'=>'legacy-observed-placeholder.com','sld'=>'legacy-observed-placeholder','tld'=>'com','user_id'=>$owner->id,'service_id'=>$service->id,
    'server_id'=>$ns,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,'status'=>'active','provider_expires_at'=>'2028-01-01',
    'snapshot'=>['checkout'=>['user_id'=>$owner->id,'service_id'=>$service->id,'order_id'=>$order->id,'product_id'=>$product->id,'annual_plan_id'=>$plan->id,'name'=>'legacy-observed-placeholder.com','action'=>'register']]]);
$snapshot=static function()use($service):array{
    $rows=['service'=>$service->fresh()->getRawOriginal()];
    foreach(['orders','invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs','domains_operations'] as $table){$rows[$table]=DB::table($table)->orderBy('id')->get()->toJson();}return $rows;
};$finance=$snapshot();$d=$d->fresh();$expiry=$d->provider_expires_at;$anchor=$d->renewal_policy;$revision=$d->confirmed_revision;
$state=(object)['calls'=>[]];
$wire=new class($state) implements DeadlineTransport {
    public function __construct(private $state){}
    public function send($e,$c,$r,#[SensitiveParameter]string $key):TransportResponse{throw new LogicException('Only bounded reads');}
    public function sendWithin($e,$c,$r,#[SensitiveParameter]string $key,int $timeout):TransportResponse{
        same(0,DB::transactionLevel(),'Legacy holding reads outside locks');same('getDomainInfo',$r->command,'Scheduled legacy read has no offer or write command');
        expect($timeout>0&&$timeout<=25000,'Legacy read deadline bounded');$this->state->calls[]=$r->command;
        return new TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>['code'=>300,'detail'=>'synthetic','created'=>'2020-01-01','expires'=>'2028-01-01','status'=>'Active','auto_renew'=>'No','private'=>'No','locked'=>'Yes','nameservers'=>[['nameserver'=>'ns1.provider.example.net'],['nameserver'=>'ns2.provider.example.net']]]]));
    }
};
$registry=new RegistrarRegistry;$registry->register('namesilo',['token'],new class implements Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier{
    public function verify($environment,array $credentials):string{return $credentials['token'];}
},static fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),[],$clock));
$readBindings=new AccountBindings($registry);$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
same(false,App\Models\Server::findOrFail($ns)->settings()->where('key',LocalAccountEnrollment::KEY)->exists(),'Legacy NameSilo fixture has no local offer marker');
$offerRefused=false;try{$readBindings->offerCredentialRevision($binding);}catch(DomainException){$offerRefused=true;}
same(true,$offerRefused,'Offer feeds still require local NameSilo enrollment');
$bindings->setProcessing($ns,false);$work=observationFixtureScheduler($observer,$clock,$ports);
same('refreshed',$work->tick(2)[$ns]['holding'],'Scheduled legacy NameSilo holding reads accept explicit verified enrollment');
expect(count($state->calls)>0,'Scheduled NameSilo holding reaches its established synthetic reader');
same($finance,$snapshot(),'Legacy observation changes no native finance, service or operation');
same([$expiry->format('Y-m-d'),$anchor,$revision],[$d->fresh()->provider_expires_at->format('Y-m-d'),$d->fresh()->renewal_policy,$d->fresh()->confirmed_revision],'Legacy refresh cannot invent a financial anchor or confirmed expiry');
same(true,$d->fresh()->observation['facts']['owned'],'Verified legacy read stores ownership facts');
$nativeSuccessful=true;echo "Legacy NameSilo holding reads remain independent of closed offer enrollment\n";
