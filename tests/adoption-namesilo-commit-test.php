<?php
// Synthetic installed import, no sockets. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
require __DIR__.'/Support/adoption-confirm.php';
use App\Models\{Order,Server,Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry,CatalogueRate,Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\Target;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Adoption,Domain,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AdoptionCommit, AdoptionMapping, AdoptionReader, DomainBilling, HolderPricing, LiveReservations, LocalAccountEnrollment, NativeDomainGuard, RegistrarRegistry};
function hrtime(bool $number=false): int {return 0;}
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['importOptions']=array_replace($GLOBALS['importOptions']??[],$options);return true;}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
function curl_exec($h): bool {
    same(0,DB::transactionLevel(),'Import read outside native locks');
    $o=$GLOBALS['importOptions'];$url=parse_url($o[CURLOPT_URL]);parse_str($url['query'],$params);
    same(['https','www.namesilo.com','/api/getDomainInfo'],[$url['scheme'],$url['host'],$url['path']],'Import can only use the exact interactive read');
    same(['version'=>'1','type'=>'json','key'=>'placeholder-import-key','domain'=>'example-import.com'],$params,'Exact bound domain and current key');
    $GLOBALS['importCalls']++;
    if(isset($GLOBALS['importDuring'])){($GLOBALS['importDuring'])();}
    $body=json_encode(['request'=>['operation'=>'getDomainInfo'],'reply'=>$GLOBALS['importReply']]);
    return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
extract(adoptionCommitFixture());
$clock->time=new \DateTimeImmutable('2026-11-02T12:00:00Z');
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
\Paymenter\Extensions\Servers\Domains\Services\LiveReservationSchema::install();
$server=Server::create(['name'=>'Synthetic import','type'=>'server','extension'=>'Domains','enabled'=>true]);
$identity=LocalAccountEnrollment::PREFIX.'import-account';
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','environment'=>'live','stable_identity'=>$identity]));
Account::withVerifiedBindingWrite(fn()=>$account->update(['live_site'=>rtrim(config('app.url'),'/'),'processing_enabled'=>true]));
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);$credentials=['api_key'=>'placeholder-import-key'];
$facts=LocalAccountEnrollment::facts($binding,$identity,$credentials);
$store=function(array $credentials,array $facts)use($server,$binding):void {
    foreach(['domains_credentials'=>json_encode($credentials),LocalAccountEnrollment::KEY=>json_encode($facts),
        'domains_verified_credentials'=>hash_hmac('sha256',$binding->fingerprint.json_encode($credentials),(string)config('app.key'))] as $key=>$value){
        $s=$server->settings()->firstOrNew(['key'=>$key]);$s->encrypted=true;$s->type='string';$s->value=$value;$s->save();
    }
};$store($credentials,$facts);
config(['domains.namesilo_live.site'=>rtrim(config('app.url'),'/'),'domains.namesilo_live.catalogue'=>true,
    'domains.namesilo_live.accounts'=>[$identity=>['fingerprint'=>$binding->fingerprint,'currency'=>'USD','balance_funding'=>true,'daily_call_cap'=>100,'daily_spend_limit_usd'=>'20.00']]]);
$registry=new RegistrarRegistry;$bindings=new AccountBindings($registry);$allow=true;$revision=$facts['credential_revision'];
// Mutable acceptance must revoke already-created handles as well as future factories.
$accept=static function($b,$r)use(&$allow,&$revision,$binding):bool{return $allow&&$b->matches($binding)&&$r===$revision;};
$factory=NameSiloAdoptionReader::factory($bindings->nameSiloRuntimeReaderFactory($binding,$clock,$accept));
// Retained local enrollment requires its original authenticator composition; import never invokes it.
$registry->register('namesilo',['api_key'],new AccountVerifier,static fn()=>throw new \LogicException('No general reader fallback'),
    adoptionReaderFactory:$factory,authenticator:new CredentialAuthenticator(Environment::Live));
// Normal active routing for the admitted read handle; pricing evidence is a separate module fixture.
app()->instance(AccountBindings::class,$bindings);app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class);\Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
$baseNative=new \Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue(new \Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules));
$offerPorts=new \Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($baseNative),$clock,$bindings,static fn()=>null,static fn()=>true);
$offerCaps=new \Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities($offerPorts,$clock,static fn()=>null);
$prices=new \Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules,$offerCaps,$offerPorts);
$native=new \Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue($prices);
$query=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'register');$offerRevision=fn()=>$offerPorts->readOffers($query,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
$costs=[];foreach(['register','renew','transfer'] as $action){$costs[]=new \Paymenter\Extensions\Servers\Domains\Catalogue\Cost($action,1,true,'8.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+1 day'));}
$offerPorts->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,'com',$costs),$offerRevision());
$offerPorts->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($binding,'com',new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$clock->now(),$clock->now()->modify('+1 day'))),$offerRevision());
$offerPorts->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,'com',new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true,transfers:true)),$offerRevision());
$offerPorts->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($binding,'com',['register','transfer']),$offerRevision());
$tld=Tld::where('server_id',$server->id)->sole();$product=$native->product($tld,'register');$plan=$native->annualPlan($product);
$order=Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
// Retained service predates installation; seed it without checkout events.
$service=Service::withoutEvents(function()use($owner,$order,$product,$plan){
$service=Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'pending']);
$service->properties()->create(['key'=>'domain','value'=>'example-import.com']);return $service;});
$mapping=new AdoptionMapping($native,$prices,$clock,$bindings);$reader=new AdoptionReader($bindings,$mapping,$clock);$commit=new AdoptionCommit($mapping,$bindings,$clock);
$target=new Target('example-import.com',$service->id,$owner->id,$server->id,Environment::Live);
$GLOBALS['importCalls']=0;$GLOBALS['importReply']=['code'=>300,'detail'=>'success','created'=>'2020-01-01','expires'=>'2027-11-02','status'=>'Active','locked'=>'Yes','private'=>'No','auto_renew'=>'No',
    'nameservers'=>[['nameserver'=>'ns1.example.net','position'=>1],['nameserver'=>'ns2.example.net','position'=>2]]];
$before=scenarioMoney();$ops=Operation::count();$domainCount=Domain::count();
$allow=false;$denied=$reader->preview($target,$context);same('account_unconfirmed',$denied->code,'Missing runtime acceptance refuses before send: '.$denied->code);same(0,$GLOBALS['importCalls'],'No unadmitted provider request');$allow=true;
$review=$reader->preview($target,$context);same('ready',$review->code,'Real NameSilo adapter previews through shared flow: '.$review->code);
$a=Adoption::findOrFail($review->previewId);
same(['privacy'=>false,'locked'=>true,'nameservers'=>['ns1.example.net','ns2.example.net']],$a->evidence['settings']??null,'Observed settings retained in encrypted preview');
expect(!str_contains($a->getRawOriginal('evidence'),'ns1.example.net'),'Normalized settings are encrypted at rest');
same(null,$a->evidence['provider_domain_id'],'Reference stays null');same('date',$a->evidence['expiry']['precision'],'Date precision retained');
same($domainCount,Domain::count(),'Preview creates no holding');
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
same(['namesilo','adopted','active',null,'2027-11-02'],[$d->registrar_key,$d->provenance,$d->status,$d->provider_domain_id,$d->provider_expires_at->format('Y-m-d')],'Shared commit adopts NameSilo without a fabricated reference');
same(1,$GLOBALS['importCalls'],'Commit performs no provider work');same($ops,Operation::count(),'Import fabricates no purchase operation');same($before,scenarioMoney(),'No invoice, payment, charge or credit on import');
same(null,$service->fresh()->expires_at,'Native expiry remains null');same('active',$service->fresh()->status,'Native service active');
same(['used'=>1,'spend_micros'=>0],(new LiveReservations(new NameSiloLiveReservationPolicy))->usage($binding),'Physical import read accounted once with no spend');
same($a->evidence['settings'],$a->fresh()->evidence['settings'],'Commit retains observed flags/NS evidence');
same($d->id,$commit->confirm($a->request_key,$a->request_digest,$staff)->id,'Replay returns same adopted holding');same(1,$GLOBALS['importCalls'],'Replay is local');
// Even an enabled annual catalogue price is insufficient non-premium/domain renewal evidence.
$server->settings()->create(['key'=>'domains_manual_renewals_enabled','type'=>'boolean','value'=>true]);
$server->settings()->create(['key'=>'domains_billing_enabled','type'=>'boolean','value'=>true]);
$billing=new DomainBilling($bindings,new NativeDomainGuard(new HolderPricing($prices),$clock),$clock,catalogue: $GLOBALS['caps']??null);
foreach([$owner,null] as $actor){
    $clock->time=new \DateTimeImmutable('2027-10-15T12:00:00Z');
    $refused=false;try{$billing->invoice($d->id,$actor);}catch(\DomainException $e){$refused=true;same('Imported renewal eligibility is not proven.',$e->getMessage(),'Explicit imported renewal gate');}
    expect($refused,'Imported renewal cannot create manual or scheduled debt');
}
same(['eligible'=>false,'reason'=>'imported_renewal_unproven'],$review->details['renewal_eligibility']??null,'Ready import does not promise a renewal');
same($before,scenarioMoney(),'Ineligible renewals create no debt');same($ops,Operation::count(),'No renewal obligations');same(1,$GLOBALS['importCalls'],'Billing eligibility needs no provider request');
$nativeSuccessful=true;echo "Admitted NameSilo import previews/commits locally, retains evidence and refuses unproven renewal billing.\n";
