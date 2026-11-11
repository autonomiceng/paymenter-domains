<?php
// Installed TEST contact preparation uses separate contact and purchase send grants. All wire responses are synthetic.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,CredentialAuthenticator,RegistrationProtocol};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,Money,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AcquisitionPort,AcquisitionPorts,DomainOperations,Installation,LocalAccountEnrollment,OperationClaims,ProfileContactOperations,RegistrarRegistry,RegistrationBridge,RegistrationProfiles,WriteHeld};
(new Installation)->install();$installedFixture=true;
require __DIR__.'/Support/namesilo-registration-fixture.php';
app()->instance(Clock::class,$clock);
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options):bool {$GLOBALS['profileOptions']=array_replace($GLOBALS['profileOptions']??[],$options);return true;}
function curl_exec($h):bool {
    $url=$GLOBALS['profileOptions'][CURLOPT_URL];parse_str(parse_url($url,PHP_URL_QUERY),$p);
    $command=basename(parse_url($url,PHP_URL_PATH));unset($p['key'],$p['version'],$p['type']);
    same('https://ote.namesilo.com/apibatch/'.$command,strtok($url,'?'),'Installed TEST factory keeps the fixed background route');
    $GLOBALS['profilePhysical'][]=$command;$body=($GLOBALS['profileReply'])($command,$p);
    return ($GLOBALS['profileOptions'][CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
function curl_getinfo($h,int $option):int {return 200;}
function curl_close($h):void {}
// Prepare funded native orders using the existing commerce fixture.
$orders=[];\Carbon\Carbon::setTestNow($clock->now());\Carbon\CarbonImmutable::setTestNow($clock->now());
foreach(['registration'=>'register','transfer'=>'transfer'] as $route=>$action){
    foreach(['ok','unsent','lost','badread','readfail','differentroles'] as $mode){
        $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[1],$action),placeholderComFixtureName('test-profile-'.$action.'-'.$mode.'-placeholder.com'));
        commerceCheckout($user,$cart)->assertHasNoErrors();
        $op=Domain::latest('id')->firstOrFail()->operations()->sole();
        DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
        \App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
        if($action==='transfer'){
            $request=\Illuminate\Http\Request::create('/internal-transfer-input','POST',['transfer_code'=>'SyntheticOnly']);
            $request->setUserResolver(fn()=>$user);$transfers->submitCode($request,$op->id);$op=$op->fresh();
        }
        if($mode==='differentroles'){$payload=$op->payload;$payload['contacts']['admin']['email_address']='other@example.com';$op->payload=$payload;DB::table('domains_operations')->where('id',$op->id)->update(['payload'=>$op->getAttributes()['payload']]);}
        $orders[]=[$route,$mode,$op->id];
    }
}
$registry=new RegistrarRegistry;
$registry->register('namesilo',['api_key'],new AccountVerifier,static fn()=>throw new \LogicException('No reader needed for contact preparation'),
    authenticator:new class implements CredentialAuthenticator {public function authenticate(Environment $environment,array $credentials):void {}});
$testBindings=new AccountBindings($registry);$account=Account::where('server_id',$binding->serverId)->sole();
// Bind the same synthetic local lineage to the API-key contract consumed by the installed factory.
$credentials=['api_key'=>'placeholder-test-api-key'];
$settings=\App\Models\Server::findOrFail($binding->serverId)->settings();
$digest=(new \ReflectionMethod(AccountBindings::class,'digest'))->invoke($testBindings,$binding,$credentials);
foreach(['domains_credentials'=>json_encode($credentials,JSON_THROW_ON_ERROR),'domains_verified_credentials'=>$digest,
    LocalAccountEnrollment::KEY=>json_encode(LocalAccountEnrollment::facts($binding,$account->stable_identity,$credentials),JSON_THROW_ON_ERROR)] as $key=>$value){
    (clone $settings)->where('key',$key)->sole()->update(['value'=>$value]);
}
app()->instance(AccountBindings::class,$testBindings);
config(['domains.namesilo_account.environment'=>'test']);
$claims=new OperationClaims($testBindings,$clock,$caps);$field=static fn(object $object,string $class,string $name)=>(new \ReflectionProperty($class,$name))->getValue($object);
$ports=TestRuntime::factory($binding)($binding);
foreach($orders as [$route,$mode,$id]){
    $port=$field($ports['acquisition'],AcquisitionPorts::class,$route);
    $writer=$field($field($port,AcquisitionPort::class,'engine'),DomainOperations::class,'writer');
    $profiles=$field($port,AcquisitionPort::class,'profiles');
    $operations=$field($profiles,RegistrationProfiles::class,'operations');$profileGrant=$field($operations,ProfileContactOperations::class,'reservation');
    $protocol=$field($writer,RegistrationBridge::class,'transport')->getValue();$purchaseGrant=$field($protocol,RegistrationProtocol::class,'reservation');
    $claim=$claims->claim($id);expect($claim!==null,'A funded '.$route.' order receives its real claim: '.$mode);$op=$claim[0];
    $GLOBALS['profilePhysical']=[];$registration->calls=[];$registration->mode=$mode==='badread'?'wrongcontact':'300';
    $GLOBALS['profileReply']=static function($command,$p)use($op,$mode,$exchange,$profileGrant,$writer):string {
        same(0,DB::transactionLevel(),'Installed TEST physical send stays outside locks');
        if($command==='contactAdd'){
            $row=ProfileContactOperation::where('operation_id',$op->id)->sole();same('sealed',$row->state,'The contact has its own durable seal');
            expect(!$profileGrant->unconsumed((int)$row->id),'Contact handoff consumes only the contact grant');
            expect($writer->unconsumed((int)$op->id),'Contact handoff preserves the independently armed purchase grant');
            if($mode==='lost'){throw new \RuntimeException('Synthetic lost contact response');}
        }
        if($command==='contactList'&&$mode==='readfail'){throw new \RuntimeException('Synthetic readback failure');}
        return $exchange('https://ote.namesilo.com/apibatch',$command,$p,25000);
    };
    config(['domains.provider_send_guard'=>$mode==='unsent'?static function($provider,$environment,$command):void {
        if($command==='contactAdd'){throw new \RuntimeException('Synthetic contact send refusal');}
    }:null]);
    $held=null;
    $writer->withinReservation($op,static function()use($op,$mode,$binding,$clock,$profiles,$purchaseGrant,$writer,&$held):void {
        $mapping=$op->action==='transfer'?Api\PurchaseMapping::transfer($op->domain->domain,'SyntheticOnly',false,'123',['ns1.example.net','ns2.example.net'])
            :Api\PurchaseMapping::register($op->domain->domain,1,false,'123',['ns1.example.net','ns2.example.net']);
        $purchaseGrant->reserve($mapping->command,$mapping->parameters(),new Money('6.00','USD'));
        $ctx=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+120 seconds'),new RequestBudget(25),$clock);
        try{$profiles->prepare($op,$ctx);}catch(WriteHeld $error){$held=$error->reason;}
        same($mode==='ok'?null:'contact_profile_proof_required',$held,'Only verified '.$op->action.' contacts leave preparation without a hold: '.$mode);
        expect($writer->unconsumed((int)$op->id),'Contact preparation preserves the purchase grant: '.$mode);
        same($mode==='ok',$profiles->hasProof($op),'Only successful creation and readback retain profile proof');
    });
    $row=ProfileContactOperation::where('operation_id',$id)->first();
    same(match($mode){'ok'=>'verified','unsent'=>'unsent','lost'=>'uncertain','differentroles'=>null,default=>'created'},$row?->state,'Distinct contact outcomes stay distinct');
    same(match($mode){'unsent','differentroles'=>[],'lost'=>['contactAdd'],default=>['contactAdd','contactList']},$GLOBALS['profilePhysical'],'Only the required contact calls reach the TEST wire');
    same('absent',$op->fresh()->attempt['seal'],'Contact work never seals or sends the purchase');
}
config(['domains.provider_send_guard'=>null]);
same("Not sent: couldn't confirm the order's contact at NameSilo. The customer's details passed our checks.",
    \Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('contact_profile_proof_required'),'The hold covers refusal before creation and unresolved creation or readback');
echo "Installed NameSilo TEST orders prepare contacts with independent physical send grants.\n";
$nativeSuccessful=true;
