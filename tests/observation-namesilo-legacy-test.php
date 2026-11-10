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
// A confirmed readback from the explicitly supplied DNS adapter upgrades a legacy row; unproven families stay unknown.
$glue=['ns1.legacy-observed-placeholder.com'=>['192.0.2.53']];$fresh=$d->fresh();$p=$fresh->observation??[];
$p['records']=['identity'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($fresh),'at'=>$clock->now()->format(DATE_ATOM),'sequence'=>0,
    'families'=>['dns'=>['state'=>'complete','records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.9','mx_pref'=>10]]],
        'srv'=>['state'=>'complete','records'=>[]],'ds'=>['state'=>'complete','records'=>[]],'glue'=>['state'=>'complete','records'=>$glue]]];
$fresh->update(['observation'=>$p]);
same('unknown',\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::current($d->fresh(),$clock->now())['families']['dns']['state'],'Legacy NameSilo rows prove glue only');
$noWire=static fn()=>throw new LogicException('No wire');
$installed=(new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management($binding,$noWire))->observationCapabilities();
$adapter=(new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsManagement($binding,$noWire))->observationCapabilities();
$readback=['state'=>'complete','records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.1','mx_pref'=>10,'record_id'=>'made1','ttl'=>300]]];
$before=$d->fresh()->observation['records'];
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::saveDnsReadback($d->fresh(),['sequence_preimage'=>$readback],$clock->now(),$installed);
same($before,$d->fresh()->observation['records'],'The installed adapter saves no DNS readback');
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::saveDnsReadback($d->fresh(),['sequence_preimage'=>$readback],$clock->now(),$adapter);
$saved=\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::current($d->fresh(),$clock->now());
same(['complete',$readback['records']],[$saved['families']['dns']['state']??null,$saved['families']['dns']['records']??null],'Confirmed DNS readback survives a fresh read of the upgraded row');
same($adapter['families'],$saved['expected_families']??null,'The upgraded row records the adapter capabilities');
same(['unknown','unknown'],[$saved['families']['srv']['state'],$saved['families']['ds']['state']],'Unproven SRV and DS stay unknown');
same(['complete',$glue],[$saved['families']['glue']['state'],$saved['families']['glue']['records']],'Legacy glue evidence is kept');
// A versioned row saved before DNS was supported takes a confirmed DNS readback and keeps it on the next read.
$records=\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::class;
$p=$d->fresh()->observation;unset($p['records']);$p['management_sequence']=2;$d->fresh()->update(['observation'=>$p]);
$t0=$clock->now();$records::save($d->fresh(),['glue'=>['state'=>'complete','records'=>['ns1.legacy-observed-placeholder.com'=>'8.8.8.8']]],$t0,$installed);
$versioned=$records::current($d->fresh(),$t0);
same(['unsupported',2,$t0->format(DATE_ATOM)],[$versioned['expected_families']['dns'],$versioned['sequence'],$versioned['at']],'Fixture: a versioned row from before DNS support');
$later=$t0->modify('+5 minutes');
$confirmed=['state'=>'complete','records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.2','mx_pref'=>10,'record_id'=>'made2','ttl'=>300]]];
$records::saveDnsReadback($d->fresh(),['sequence_preimage'=>$confirmed],$later,$adapter);
$saved=$records::current($d->fresh(),$later);
same(['complete',$confirmed['records'],$later->format(DATE_ATOM)],[$saved['families']['dns']['state']??null,$saved['families']['dns']['records']??null,$saved['families']['dns']['at']??null],'Confirmed DNS and its checked time survive a fresh read of the versioned row');
same([...$installed['families'],'dns'=>'supported'],$saved['expected_families'],'Only the DNS expectation changes');
same([$t0->format(DATE_ATOM),2],[$saved['at'],$saved['sequence']],'The whole-observation time and sequence stay');
same(['unknown','unknown'],[$saved['families']['srv']['state'],$saved['families']['ds']['state']],'Unsupported siblings stay unknown');
same([$versioned['families']['glue']['state'],$versioned['families']['glue']['records']],[$saved['families']['glue']['state'],$saved['families']['glue']['records']],'Saved glue evidence stays');
// Refresh through the explicitly supplied DNS adapter reads complete DNS and settings and sends no change.
$clock->instant=$later->modify('+1 minute');$adapterCalls=[];
$contact=['first_name'=>'Synthetic','last_name'=>'Person','company'=>'','address'=>'1 Example Street','address2'=>'','city'=>'Portland','state'=>'OR','zip'=>'97201','country'=>'US','email'=>'synthetic@example.com','phone'=>'+1.5035550123'];
$adapterExchange=static function($origin,$cmd,$p,$timeout)use(&$adapterCalls,$contact):string {
    $adapterCalls[]=$cmd;
    if($cmd==='listRegisteredNameServers'){return '{"request":{"operation":"listRegisteredNameServers"},"reply":{"code":300,"detail":"success","hosts":[{"host":"ns1","ip":"8.8.8.8"}]}}';}
    $reply=['code'=>300,'detail'=>'synthetic']+match($cmd){
        'getDomainInfo'=>['created'=>'2020-01-01','expires'=>'2028-01-01','status'=>'Active','auto_renew'=>'No','private'=>'No','locked'=>'Yes',
            'nameservers'=>[['nameserver'=>'ns1.provider.example.net'],['nameserver'=>'ns2.provider.example.net']],'contact_ids'=>array_fill_keys(['registrant','administrative','technical','billing'],'900001')],
        'dnsListRecords'=>['resource_record'=>[['record_id'=>'fresh1','type'=>'A','host'=>'legacy-observed-placeholder.com','value'=>'192.0.2.3','ttl'=>'300','distance'=>'0']]],
        'dnsSecListRecords'=>['ds_record'=>[]],
        'contactList'=>['contact'=>[...$contact,'contact_id'=>$p['contact_id']]],
        default=>throw new LogicException('Refresh must not send '.$cmd),
    };
    return json_encode(['request'=>['operation'=>$cmd],'reply'=>$reply],JSON_THROW_ON_ERROR);
};
$port=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsManagement($binding,$adapterExchange);
$refresh=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),observationReader:static fn($b)=>$port);
$refreshCode=$refresh->run($d->id);
same([],array_values(array_diff($adapterCalls,['getDomainInfo','dnsListRecords','dnsSecListRecords','listRegisteredNameServers','contactList'])),'Refresh through the DNS adapter only reads');
expect(in_array('dnsListRecords',$adapterCalls,true),'Refresh reads the DNS list');
$refreshed=$records::current($d->fresh(),$clock->now());
same(['complete',[['name'=>'@','type'=>'A','address'=>'192.0.2.3','mx_pref'=>10,'record_id'=>'fresh1','ttl'=>300]]],[$refreshed['families']['dns']['state']??null,$refreshed['families']['dns']['records']??null],'Refresh replaces confirmed DNS with the complete registrar list, never unknown');
same(['complete','complete','complete'],[$refreshed['settings']['nameservers']['state']??null,$refreshed['settings']['contacts']['state']??null,$refreshed['settings']['flags']['state']??null],'Refresh reads complete settings');
same($port->observationCapabilities()['families'],$refreshed['expected_families'],'The refreshed row records what the adapter reads');
same([false,'refreshed'],[$records::held($d->fresh()),$refreshCode],'A complete refresh holds nothing');
$nativeSuccessful=true;echo "Legacy NameSilo holding reads remain independent of closed offer enrollment\n";
