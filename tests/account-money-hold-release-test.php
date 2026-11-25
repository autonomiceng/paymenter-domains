<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\{Audit,Gateway,Invoice};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{AccountMoneyHolds,InvoiceMoney,ReminderSchema,StaffOperationReview};
use Paymenter\Extensions\Servers\Domains\Money\Returns\DisputeRecoveries;
class UnreadableHoldGateway extends MoneyReceiptGateway {
    public function receiptLedger(Invoice $invoice):array { throw new RuntimeException('Synthetic unreadable evidence'); }
}
class_alias(UnreadableHoldGateway::class,'Paymenter\\Extensions\\Gateways\\UnreadableHold\\UnreadableHold');
class LostHoldGateway extends MoneyReceiptGateway {
    public static array $ledger=[];
    public function receiptLedger(Invoice $invoice):array { return self::$ledger; }
    /** The same lost dispute as the request page's Payment row reads it. */
    public function receiptReversals(Invoice $invoice,string $receipt):array {
        return array_map(fn($r)=>['kind'=>'dispute','id'=>substr($r['allocation_key'],15),'status'=>$r['state'],'invoice_id'=>$r['invoice_id'],'user_id'=>$r['user_id'],
            'payment_intent_id'=>$r['payment_intent_id'],'currency'=>$r['currency'],'amount_minor'=>$r['amount_minor'],'needs_review'=>true],
            array_filter(self::$ledger,fn($r)=>$r['payment_intent_id']===$receipt));
    }
}
class_alias(LostHoldGateway::class,'Paymenter\\Extensions\\Gateways\\LostHold\\LostHold');
ReminderSchema::install();
$op=$new('unreadable-hold-placeholder.com');
$gateway=Gateway::create(['extension'=>'UnreadableHold','name'=>'Synthetic unreadable receipt','type'=>'gateway','enabled'=>true]);
DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic_unreadable_hold']);
$alert=DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>DisputeRecoveries::key('account_money_hold','synthetic_unreadable'),
    'domain_id'=>$op->domain_id,'operation_id'=>$op->id,'generation'=>$op->authority_generation,'code'=>'account_money_hold','first_seen'=>1,'last_seen'=>1]);
$staff=staffUser(['admin.domains.view']);
commerceReject(fn()=>AccountMoneyHolds::release($staff,(int)$alert));
expect(InvoiceMoney::ownerHeld((int)$user->id),'Unauthorized release retains the account hold');
same(0,Audit::where('event','account_money_hold_released')->count(),'Unauthorized release writes no invoice Audit');
$staff->role->update(['permissions'=>['admin.domains.view','admin.domains.manage',StaffOperationReview::permission('release'),'admin.invoices.update']]);
$before=$nativeMoney();
AccountMoneyHolds::release($staff,(int)$alert);
expect(!InvoiceMoney::ownerHeld((int)$user->id),'Staff release succeeds even while receipt evidence is unreadable');
$audit=Audit::where('event','account_money_hold_released')->sole();
same([(new Invoice)->getMorphClass(),(int)$op->invoice_id,(int)$staff->id],[$audit->auditable_type,(int)$audit->auditable_id,(int)$audit->user_id],'Native Audit identifies the invoice and releasing actor');
same(true,$audit->new_values['evidence_unreadable']??false,'Audit records that dispute evidence was unreadable');
same((int)$alert,$audit->new_values['alert_id']??null,'Audit retains the released hold identity without dispute evidence');
same($before,$nativeMoney(),'Staff release changes no native financial rows');
commerceReject(fn()=>AccountMoneyHolds::release($staff,(int)$alert));
same(1,Audit::where('event','account_money_hold_released')->count(),'Repeated release retains one Audit');
// G2: while recovery is still open, a readable ledger does not make the hold amounts final.
$pendingOp=$new('pending-hold-placeholder.com');$partOp=$new('partial-hold-placeholder.com');
$lostGateway=Gateway::create(['extension'=>'LostHold','name'=>'Synthetic lost receipt','type'=>'gateway','enabled'=>true]);
DB::table('invoice_transactions')->where('invoice_id',$pendingOp->invoice_id)->update(['gateway_id'=>$lostGateway->id,'transaction_id'=>'synthetic_pending_hold']);
$pendingInvoice=Invoice::findOrFail($pendingOp->invoice_id);
LostHoldGateway::$ledger=[['invoice_id'=>(int)$pendingInvoice->id,'user_id'=>(int)$pendingInvoice->user_id,'currency'=>$pendingInvoice->currency_code,
    'payment_intent_id'=>'synthetic_pending_hold','allocation_key'=>'stripe:dispute:synthetic_pending','state'=>'lost','amount_minor'=>100]];
foreach(['account_money_hold','dispute_recovery'] as $code){DB::table('domains_obligation_alerts')->insert(['alert_key'=>DisputeRecoveries::key($code,'synthetic_pending'),
    'domain_id'=>$pendingOp->domain_id,'operation_id'=>$pendingOp->id,'generation'=>$pendingOp->authority_generation,'code'=>$code,'first_seen'=>1,'last_seen'=>1]);}
$holdKey=DisputeRecoveries::key('account_money_hold','synthetic_pending');$holdEvidence=new ReflectionMethod(AccountMoneyHolds::class,'evidence');
$evidence=$holdEvidence->invoke(null,$pendingOp,$holdKey);
same([true,100,null,null,null],[$evidence['pending']??false,$evidence['lost_minor'],$evidence['owed_minor'],$evidence['removed_minor'],$evidence['remainder_minor']],'Open recovery leaves owed, removed and remainder unknown');
DB::table('domains_obligation_alerts')->where('alert_key',DisputeRecoveries::key('dispute_recovery','synthetic_pending'))->update(['resolved_at'=>2]);
$evidence=$holdEvidence->invoke(null,$pendingOp,$holdKey);
same([false,0,0,0],[$evidence['pending']??false,$evidence['owed_minor'],$evidence['removed_minor'],$evidence['remainder_minor']],'Finished owed-zero recovery shows final amounts');
// One open account hold is one entry in the domain's Billing: its notice with Release hold. No row repeats it, and staff see no dispute id.
staffPanel();
$holdPage=Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$pendingOp->domain_id])->assertSuccessful();
$holdFacts=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for(Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail($pendingOp->domain_id));
$holdRows=array_keys(array_filter($holdFacts,fn(array $f)=>($f['alert']??false)||$f===($holdFacts['Open alerts']??null)));
same([],$holdRows,'No Billing row repeats the account hold');
$holdDom=new DOMDocument;@$holdDom->loadHTML('<?xml encoding="utf-8"?>'.$holdPage->html());
same(1,(new DOMXPath($holdDom))->query('//*[@id="'.Paymenter\Extensions\Servers\Domains\Admin\Support\AccountMoneyHoldActions::ANCHOR.'"]')->length,'The domain page has one account hold entry');
expect(!str_contains($holdPage->html(),'synthetic_pending'),'Staff page text carries no dispute id');
// A lost dispute whose returned credit was partly spent: the hold says what is still to repay before Release hold.
$partOp->update(['funding'=>[...($partOp->funding??[]),'dispute_holds'=>[['dispute_id'=>'synthetic_partial','increment'=>0,'payment_id'=>'synthetic_partial_receipt',
    'lost_minor'=>1000,'owed_minor'=>600,'removed_minor'=>200,'remainder_minor'=>400,'credit_id'=>null,'at'=>gmdate(DATE_ATOM,1)]]]]);
DB::table('domains_obligation_alerts')->insert(['alert_key'=>DisputeRecoveries::key('account_money_hold','synthetic_partial'),
    'domain_id'=>$partOp->domain_id,'operation_id'=>$partOp->id,'generation'=>$partOp->authority_generation,'code'=>'account_money_hold','first_seen'=>1,'last_seen'=>1]);
$partDom=new DOMDocument;@$partDom->loadHTML('<?xml encoding="utf-8"?>'.Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$partOp->domain_id])->assertSuccessful()->html());
$partHold=(new DOMXPath($partDom))->query('//*[@id="'.Paymenter\Extensions\Servers\Domains\Admin\Support\AccountMoneyHoldActions::ANCHOR.'"]');
expect($partHold->length===1&&str_contains($partHold->item(0)->textContent,'$4.00'),'The one account hold shows the amount still to repay');
expect(!str_contains($partDom->saveHTML(),'synthetic_partial'),'The recovery amounts carry no dispute id');
// The request's Payment row asks staff to look while the dispute's account hold is open, and settles once staff release it.
$lostAct=static function()use($pendingOp,$staff):bool {
    Illuminate\Support\Facades\Auth::setUser($staff);
    $row=Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($pendingOp->fresh())['payment_reversed']??null;
    expect(is_array($row)&&array_key_exists('act',$row),'The request keeps its Payment row with an act field');
    return (bool)$row['act'];
};
same(true,$lostAct(),'A lost dispute needs review while its account hold is open');
AccountMoneyHolds::release($staff,(int)DB::table('domains_obligation_alerts')->where('alert_key',$holdKey)->value('id'));
same(false,$lostAct(),'A lost dispute needs no review once its account hold is released');
$nativeSuccessful=true;
