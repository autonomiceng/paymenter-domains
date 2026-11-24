<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic receipt evidence only.
declare(strict_types=1);
require __DIR__.'/departure-late-receipt.php';
use App\Models\{Gateway,Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,PricingRule};

$nativeSuccessful=false;
class DepartureRepairGateway extends \App\Classes\Extension\Gateway {
    public static bool $unreadable=false;
    public static array $ledger=[];
    public function pay(Invoice $invoice,$total){return '';}
    public function receiptLedger(Invoice $invoice):array {
        if(self::$unreadable){throw new DomainException('Synthetic unreadable receipt evidence.');}
        return self::$ledger;
    }
}
class_alias(DepartureRepairGateway::class,'Paymenter\\Extensions\\Gateways\\DepartureRepair\\DepartureRepair');
Gateway::create(['extension'=>'DepartureRepair','name'=>'Synthetic credit receipt','type'=>'gateway','enabled'=>true]);
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),'DepartureRepair','1.20','0.00','departed-repair-receipt');
$before=$cash($owner);
$open=static fn(string $code)=>DB::table('domains_obligation_alerts')->where('operation_id',$renewal->id)->where('code',$code)->whereNull('resolved_at')->count();
foreach(['unreadable','conflicting'] as $case){
    DepartureRepairGateway::$unreadable=$case==='unreadable';
    $entry=['invoice_id'=>(int)$invoice->id,'user_id'=>$owner,'currency'=>'USD','payment_intent_id'=>'departed-repair-receipt',
        'allocation_key'=>'excess','state'=>'reserved','amount_minor'=>120];
    DepartureRepairGateway::$ledger=$case==='conflicting'?[$entry,[...$entry,'amount_minor'=>119]]:[];
    $failed=false;try{$refunds->creditRejected((int)$renewal->id);}catch(DomainException){$failed=true;}
    same(true,$failed,'Invalid departed receipt evidence refuses credit: '.$case);
    same(1,$open('refund_repair'),'Failed departed receipt keeps its request repair: '.$case);
    $status=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($d->fresh());
    same(['transferred_away',true,'refund_repair',(int)$renewal->id],[$status['key'],$status['act'],$status['alert'],$status['request']],
        'The departed holding keeps its live repair actionable: '.$case);
    same($before,$cash($owner),'Failed departed receipt moves no money: '.$case);
}
DepartureRepairGateway::$unreadable=false;DepartureRepairGateway::$ledger=[];
DB::transaction(fn()=>ObligationAlerts::record($d->fresh(),'funds_wait_review',$clock->now()->getTimestamp(),(int)$renewal->id));
same(1,$open('funds_wait_review'),'The receipt also has a retained funds review');
$refunds->creditRejected((int)$renewal->id);
same($before+120,$cash($owner),'Recovered departed receipt credits exactly its available share');
same([0,0],[$open('refund_repair'),$open('funds_wait_review')],'Successful departed recovery clears both repair codes');
$status=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($d->fresh());
same([false,null],[$status['act'],$status['alert']],'Settled departed money leaves no staff step');
$refunds->creditRejected((int)$renewal->id);
same($before+120,$cash($owner),'Recovered departed receipt cannot be credited twice');
echo "Departed receipt failures keep repair alerts and successful recovery resolves them.\n";
$nativeSuccessful=true;
