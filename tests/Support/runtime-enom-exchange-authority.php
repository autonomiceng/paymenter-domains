<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/runtime-enom-exchange-fixture.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, OperationClaims, RegistrarRegistry, RenewalAcceptance, RuntimePorts, RuntimeWork, TransferInputs, Refunds, WriteAcceptance, WriteFeatures, NativeCatalogue, PriceCatalogue, SellerPolicies, CartAdmission, CheckoutEligibility, InvoiceBinding, TransferPrecheck, Rdap};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
// Before-send capacity refusal must not consume credentials or create a financial seal.
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('capacity-held-placeholder.com'));resetWire(['reply'=>$reply]);
$token=$limiter->acquire($binding,RequestOrigin::Interactive);$worker->operation($op->id);same(0,count($GLOBALS['enomWire']['forms']),'Occupied read slot refuses write');
same('absent',$op->fresh()->attempt['seal'],'Physical refusal precedes seal');$limiter->release($binding,RequestOrigin::Interactive,$token);
// Processing paused before worker entry refuses before dispatch.
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('rotation-before-wire-placeholder.com'));resetWire(['reply'=>$reply]);
$state->query=OperationClaims::query($op,$op->domain);$auth->setProcessing($binding->serverId,false);$worker->operation($op->id);same(0,count($GLOBALS['enomWire']['forms']),'Processing off sends nothing');
// Drift after the durable seal reaches the actual private credential seam. The clock shim
// runs once at exchange entry, with real grants and the physical slot already consumed.
echo "Installed exchange capacity and pre-entry pause passed.\n";
foreach(['processing','server','binding'] as $drift){
    $auth->setProcessing($binding->serverId,true,static fn()=>null);activeFixtureRepublish($sellers,$tlds[0]);$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('sealed-drift-'.$drift.'-placeholder.com'));
    resetWire(['reply'=>$reply,'before'=>static function()use($op,$drift,$binding,$account,$otherIdentityBytes){
        same('sealed',$op->fresh()->attempt['seal'],'Drift occurs after real seal');same(0,DB::transactionLevel(),'Drift outside financial locks');
        if($drift==='processing'){DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);}
        if($drift==='server'){DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>false]);}
        if($drift==='binding'){$other=new AccountBinding('enom',$binding->serverId,Environment::Test,'other-account');DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$otherIdentityBytes,'account_fingerprint'=>$other->fingerprint]);}
    }]);
    $worker->operation($op->id);same(0,$GLOBALS['enomWire']['inits'],'Post-seal drift refused before cURL: '.$drift);same('uncertain',$op->fresh()->state,'Sealed local refusal remains uncertain');
    DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>true]);
    DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$identityBytes,'account_fingerprint'=>$binding->fingerprint,'processing_enabled'=>false]);
}
echo "Installed exchange post-seal drift passed.\n";
// Test-only reflection verifies the new lazy credential seam independently of earlier engine guards.
$runtimeWire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$secretBox=(new ReflectionProperty($runtimeWire,'credentials'))->getValue($runtimeWire);$load=$secretBox->getValue();
$seamDenied=static function(callable $call,string $why):void{try{$call();}catch(Throwable){return;}throw new LogicException('Expected lazy credential refusal: '.$why);};
$seamDenied(fn()=>$load(true),'processing');same('account-enom',$load(false)['uid'],'Paused reads load only current bound credentials');
$seamDenied(fn()=>DB::transaction(fn()=>$load(false)),'transaction');
DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>false]);$seamDenied(fn()=>$load(false),'server');
DB::table((new App\Models\Server)->getTable())->where('id',$binding->serverId)->update(['enabled'=>true]);
$otherBinding=new AccountBinding('enom',$binding->serverId,Environment::Test,'other-account');
// Keep the changed binding's verification digest valid, isolating the expected-fingerprint guard.
$proof=App\Models\Server::find($binding->serverId)->settings()->where('key','domains_verified_credentials')->sole();$proofBytes=$proof->getRawOriginal('value');
$otherDigest=hash_hmac('sha256',$otherBinding->fingerprint.json_encode(['uid'=>'account-enom','token'=>'SYNTHETIC-WIRE-TOKEN'],JSON_THROW_ON_ERROR),(string)config('app.key'));
DB::table('settings')->where('id',$proof->id)->update(['value'=>Illuminate\Support\Facades\Crypt::encryptString($otherDigest)]);
DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$otherIdentityBytes,'account_fingerprint'=>$otherBinding->fingerprint]);
$seamDenied(fn()=>$load(false),'binding');DB::table('settings')->where('id',$proof->id)->update(['value'=>$proofBytes]);DB::table('domains_accounts')->where('id',$account->id)->update(['stable_identity'=>$identityBytes,'account_fingerprint'=>$binding->fingerprint]);
DB::table('settings')->where('id',$setting->id)->update(['value'=>'invalid-ciphertext']);$seamDenied(fn()=>$load(false),'encrypted configuration');
DB::table('settings')->where('id',$setting->id)->update(['value'=>$raw]);
echo "Installed exchange credential seam passed.\n";
