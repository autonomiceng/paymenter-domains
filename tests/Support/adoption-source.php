<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Order,Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,AdoptionSource};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,DateEvidence,Environment,ExpiryPrecision};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{AdoptionReadback,Target};
use Paymenter\Extensions\Servers\Domains\Models\{Adoption,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AdoptionCommit,AdoptionReader,RegistrarRegistry};

/** Reuse the native fixture to prove mapping, holding locks and a local-only confirmation together. */
function exerciseSharedImport($native,$mapping,$clock,$staff,$owner,$context,$converted,$enomBinding,$wire): void
{
    $state=(object)['binding'=>null,'resultBinding'=>null,'calls'=>0,'facts'=>[
        'name'=>'example-import.com','provider_id'=>null,
        'expiry'=>new DateEvidence('2027-10-20',ExpiryPrecision::Date,'synthetic_import'),
        'auto_renew'=>false,'ordinary'=>true,'registrar_hold'=>false,'redemption'=>false,'command_set_version'=>7]];
    $registry=new RegistrarRegistry;
    $verifier=new class implements AccountVerifier {
        public function verify(Environment $environment,array $credentials): string { return $credentials['token']; }
    };
    $registry->register('namesilo',['token'],$verifier,static fn()=>throw new LogicException('General reader must not serve import'),adoptionReaderFactory:
        static function($binding,$credentials,$clock)use($state){
            return new class($binding,$state) implements AdoptionSource {
                public function __construct(private readonly AccountBinding $account,private readonly object $state) {}
                public function binding(): AccountBinding { return $this->state->binding??$this->account; }
                public function read(string $name,string $suffix): AdoptionReadback
                {
                    same(0,DB::transactionLevel(),'Alternate-provider read remains outside native locks');
                    same(['example-import.com','com'],[$name,$suffix],'Exact import target reaches source');
                    $this->state->calls++;
                    return AdoptionReadback::verified($this->state->resultBinding??$this->account,$this->state->facts);
                }
            };
        });
    $bindings=new AccountBindings($registry);$tld=Tld::where('registrar_key','namesilo')->sole();
    $binding=$bindings->configure($tld->server_id,'namesilo',Environment::Live,['token'=>'account-namesilo']);
    $unwired=new RegistrarRegistry;
    $unwired->register('namesilo',['token'],$verifier,static fn()=>throw new LogicException('No general-reader fallback'));
    try { $unwired->adoptionReader($binding,['token'=>'account-namesilo'],$clock);expect(false,'An absent import adapter must refuse'); }
    catch (DomainException $error) { same('Import reader is unavailable.',$error->getMessage(),'Import requires its own registered adapter'); }
    $product=$native->product($tld,'register');$plan=$native->annualPlan($product);
    $order=Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
    $service=Service::create(['user_id'=>$owner->id,'order_id'=>$order->id,'product_id'=>$product->id,
        'plan_id'=>$plan->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'pending']);
    $service->properties()->create(['key'=>'domain','value'=>'example-import.com']);
    $target=new Target('example-import.com',$service->id,$owner->id,$tld->server_id,Environment::Live);
    $reader=new AdoptionReader($bindings,$mapping,$clock);
    // Another holding without an opaque reference must not collide on SQL NULL.
    DB::table('domains_domains')->where('id',$converted->id)->update(['server_id'=>$tld->server_id,'provider_domain_id'=>null]);
    $money=scenarioMoney();$operations=Operation::count();$enomCalls=count($wire->calls);
    $review=$reader->preview($target,$context);
    same('ready',$review->code,'Shared preview accepts normalized evidence without an opaque ID');
    $preview=Adoption::findOrFail($review->previewId);
    same('namesilo',$preview->registrar_key,'Preview retains selected registrar');
    same(null,$preview->evidence['provider_domain_id'],'Missing opaque reference stays null');
    same('date',$preview->evidence['expiry']['precision'],'Date-only precision retained');
    same(7,$preview->evidence['command_set_version'],'Retain the selected adapter evidence version');
    unset($state->facts['provider_id']);
    same('read_failed',$reader->preview($target,$context)->code,'Missing reference evidence differs from explicit null');
    $state->facts['provider_id']=null;
    foreach ([['ordinary',null,'state_unconfirmed'],['auto_renew',null,'auto_renew_unknown'],['name','other-placeholder.com','identity_unconfirmed'],
        ['expiry',new DateEvidence('2027-10-20T00:00:00',ExpiryPrecision::Local,'synthetic_import'),'expiry_unconfirmed']] as [$key,$value,$code]) {
        $old=$state->facts[$key];$state->facts[$key]=$value;
        same($code,$reader->preview($target,$context)->code,'Shared evidence refuses '.$key);
        $state->facts[$key]=$old;
    }
    $state->resultBinding=$enomBinding;
    same('identity_unconfirmed',$reader->preview($target,$context)->code,'Cross-account readback refuses');
    $state->resultBinding=null;$state->binding=$enomBinding;$calls=$state->calls;
    same('account_unconfirmed',$reader->preview($target,$context)->code,'Cross-account source refuses before read');
    same($calls,$state->calls,'Mismatched source makes no read');$state->binding=null;
    $commit=new AdoptionCommit($mapping,$bindings,$clock);
    $domain=$commit->confirm($preview->request_key,$preview->request_digest,$staff);
    same('namesilo',$domain->registrar_key,'Committed holding retains selected registrar');
    same(null,$domain->provider_domain_id,'Commit retains nullable provider reference');
    same($binding->fingerprint,$domain->account_fingerprint,'Commit retains exact local account');
    same([$order->id,$service->id,$owner->id],[$service->fresh()->order_id,$domain->service_id,$domain->user_id],'Native identity retained');
    same($domain->id,$commit->confirm($preview->request_key,$preview->request_digest,$staff)->id,'Neutral confirmation replay is idempotent');
    same($money,scenarioMoney(),'Import creates no invoices, payments or credits');
    same($operations,Operation::count(),'Import creates no purchase operation');
    same($enomCalls,count($wire->calls),'Import never falls back to Enom transport');
}
