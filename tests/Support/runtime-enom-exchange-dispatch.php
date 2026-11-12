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
foreach(['register','transfer','register'] as $i=>$action){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();
    $op=$new(placeholderComFixtureName('authenticated-'.$i.'-placeholder.com'),$action);
    config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true]);resetWire(['reply'=>$reply]);
    $seen=false;$GLOBALS['enomWire']['during']=static function()use(&$seen,$op,$worker,$auth,$binding,$clock,$limiter){
        if($seen){return;}$seen=true;
        // Reentrant workers model an overlapping delivery after sealing, before the reply.
        $worker->operation($op->id);same('running',$op->fresh()->state,'Duplicate worker cannot steal live seal');
        $context=new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+20 seconds'),new RequestBudget(1),$clock,$limiter);
        $entered=false;
        try{$context->withinRead($binding,static function()use(&$entered){$entered=true;});throw new LogicException('Expected shared physical limit');}
        catch(Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
        expect(!$entered,'Interactive reader shares physical slot with background write');
    };
    $worker->operation($op->id);
    $monetaryForms = array_filter($GLOBALS['enomWire']['forms'], static fn($form) => in_array($form['Command'] ?? null, ['Purchase', 'TP_CreateOrder', 'Extend'], true));
    same(1,count($monetaryForms),'One authenticated monetary send: '.json_encode([
        'state'=>$op->fresh()->state,'safe_code'=>$op->fresh()->safe_code,'attempt'=>$op->fresh()->attempt,
        'used'=>DB::table(Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimitSchema::SENDS)->count(),
        'commands'=>array_column($GLOBALS['enomWire']['forms'],'Command'),
        'config'=>[config('domains.enom_identity.environment'),config('domains.enom_catalogue.enabled'),config('domains.enom_writes.registration')],
    ]));
    $formsAfterDispatch = count($GLOBALS['enomWire']['forms']);
    same($action==='register'?'pending':'uncertain',$op->fresh()->state,'Acknowledgement never completes acquisition');same('sealed',$op->fresh()->attempt['seal'],'Actual paid operation sealed');if($action==='register'){$registered=$op->fresh();}
    $freshPorts=installedRuntimePorts();$freshPorts->register($binding->fingerprint,$auth->enomRuntimeFactory($binding,$clock,$features,$accepted,$renewalAccepted,$precheck));
    Cache::flush();(new RuntimeWork($clock,$freshPorts))->operation($op->id);same($formsAfterDispatch,count($GLOBALS['enomWire']['forms']),'Reconstructed worker never replays');
    expect(DB::table('domains_read_budgets')->where('server_id',$binding->serverId)->value('used')>=1,'Physical send counted');
}
$clock->at=$clock->at->modify('+61 seconds');Cache::flush();$op=$new(placeholderComFixtureName('lost-after-send-placeholder.com'));resetWire(['timeout'=>true]);$worker->operation($op->id);
same('uncertain',$op->fresh()->state,'Timeout after send remains uncertain');same(1,count($GLOBALS['enomWire']['forms']),'Timeout sends once');
Cache::flush();$worker->operation($op->id);same(1,count($GLOBALS['enomWire']['forms']),'Timeout never replayed');
expect(!str_contains(json_encode($op->fresh()->attempt),'SYNTHETIC-WIRE-TOKEN'),'No credentials retained in outcome');
echo "Installed exchange acquisition routing passed.\n";
require __DIR__.'/runtime-enom-held.php';
