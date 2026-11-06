<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/renewal-initialized.php';
$fixtureInitialization=initializedRenewalFixture(...);
$renewalFixtureNames=['refund-renew-sent','reversed-credit'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
// Actual sends and unknown replies cannot be refunded, even for cancelled or stale paid obligations.
[$sent,$d,$b]=$makeRenewal(placeholderComFixtureName('refund-renew-sent-placeholder.com')); [$engine,$writer]=$compose($b); $state->lost=true;
same('uncertain',$engine->run($sent->id,$writer)->state,'Lost renewal reply remains uncertain');
$sent->refresh()->update(['state'=>'held','safe_code'=>'renewal_definitely_unsent']);
commerceReject(fn()=>$refunds->refundHeld($staff,$sent->id,'service_cancelled'));
// A reversed receipt retains its Domains credit attribution even after the pooled balance was spent.
// The gateway boundary replays local observations only; no Stripe or registrar calls are installed.
class DomainsReversalGateway extends \App\Classes\Extension\Gateway {
    public static array $observations=[];
    public static bool $fail=false;
    public function pay(\App\Models\Invoice $invoice,$total){return '';}
    public function receiptReversals(\App\Models\Invoice $invoice,string $receipt):array {if(self::$fail){throw new RuntimeException('Synthetic reversal read failure');}return self::$observations[$receipt]??[];}
}
class_alias(DomainsReversalGateway::class,'Paymenter\\Extensions\\Gateways\\DomainsReversal\\DomainsReversal');
$legacyGateway=\App\Models\Gateway::create(['extension'=>'DomainsReversal','name'=>'Legacy reversal fixture','type'=>'gateway','enabled'=>true]);
[$reversed,$reversedDomain,,$reversedInvoice]=$makeRenewal(placeholderComFixtureName('reversed-credit-placeholder.com'),false);$cycles->retireUnpaid($reversed->id);
$receipt=InvoiceTransaction::create(['invoice_id'=>$reversedInvoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$legacyGateway->id,'transaction_id'=>'pi_domains_reversed']);
InvoiceTransaction::create(['invoice_id'=>$reversedInvoice->id,'amount'=>'0.25','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$legacyGateway->id,'transaction_id'=>'pi_domains_other']);
$refunds->creditRejected($reversed->id);
// Reproduce a previously spent account balance; attribution must come from retained credits, not this zero.
Credit::where('user_id',$user->id)->where('currency_code','USD')->sole()->update(['amount'=>'0.00']);
DomainsReversalGateway::$observations['pi_domains_reversed']=[['kind'=>'dispute','id'=>'dp_domains','status'=>'lost','needs_review'=>true,
    'invoice_id'=>(int)$reversedInvoice->id,'user_id'=>(int)$user->id,'payment_intent_id'=>'pi_domains_reversed','currency'=>'usd',
    'amount_minor'=>100,'credited_minor'=>0,'credit_balance_minor'=>0,'next_step'=>'Review the invoice and credit history.']];
DomainsReversalGateway::$observations['pi_domains_reversed'][]=DomainsReversalGateway::$observations['pi_domains_reversed'][0];
$before=$nativeMoney();$services=\App\Models\Service::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all();$operations=Operation::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all();
$reversals=\Paymenter\Extensions\Servers\Domains\Services\ReceiptReversals::forInvoice($reversedInvoice);
same(1,count($reversals),'Repeated receipt observations appear once in staff context');
same([100,0,0],[$reversals[0]['domains_credited_minor'],$reversals[0]['domains_unattributed_minor'],$reversals[0]['credit_balance_minor']],'Only the reversed receipt gets its exact Domains credit, including a spent zero balance');
$role->update(['permissions'=>['admin.domains.view','admin.domains.costs']]);\Illuminate\Support\Facades\Auth::setUser($staff);
$facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($reversed->fresh());
expect(str_contains($facts['refund_sub'],'$1.00')&&str_contains($facts['refund_sub'],'$0.00'),'Staff credit row shows the attributed amount and current balance');
same($before,$nativeMoney(),'Reading a reversal never debits or returns money');same($services,\App\Models\Service::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),'Reading a reversal never changes native service state');same($operations,Operation::orderBy('id')->get()->map(fn($r)=>$r->getRawOriginal())->all(),'Reading a reversal never rewrites Domains obligations');
// Native gateway dispatch and the rendered permission boundary need the existing SQLite/Filament fixture.
$reversed->refresh()->forceFill(['funding'=>[...$reversed->funding,'refund'=>['amount'=>'1.00','to'=>'original_payment','bridge_minor'=>0,
    'payments'=>[['id'=>$receipt->id,'amount_minor'=>100]],'allocations'=>[['key'=>'review']]],'receipt_return_results'=>['review'=>['state'=>'review']]]])->saveQuietly();
require_once __DIR__.'/Support/staff-panel.php';staffPanel();DomainsReversalGateway::$fail=true;
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$reversed->id])
    ->assertSee('Do not return it again.')->assertSee('Missing.');
$role->update(['permissions'=>['admin.domains.view']]);
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$reversed->id])->assertDontSee('Credit');
DomainsReversalGateway::$fail=false;
// The staff form creates the row, then saves its gateway in a second write. Rows that already exist stay fixed.
$gateway=\App\Models\Gateway::firstOrCreate(['extension'=>'Manual'],['name'=>'Manual','type'=>'gateway']);
foreach([null,$gateway] as $choice){
    $manual=InvoiceTransaction::create(['invoice_id'=>$sent->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
    $choice?$manual->gateway()->associate($choice):$manual->gateway()->dissociate();$manual->save();
    same($choice?->id,$manual->fresh()->gateway_id,'A new staff payment keeps the gateway picked in the form');
}
commerceReject(fn()=>InvoiceTransaction::findOrFail($manual->id)->update(['gateway_id'=>null]));
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Renewal sent refusal receipt reversal and staff payment passed\n"; $nativeSuccessful=true;
