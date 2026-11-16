<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\Credit;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers,FundsWaits,HoldingDeparture,HoldingLocks,MoneyOutcome,PricingRule,Refunds,RenewalCycles,RenewalRetries,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$first=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
// The renewal was paid, its first attempt was refused before sending and it was retried on the same invoice.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$first->amount,'0.00','departure-retry-paid');
$childKey=$first->operation_key.':replacement:1';
$row=$first->fresh()->getRawOriginal();unset($row['id']);
$row=[...$row,'operation_key'=>$childKey,'retry_number'=>1,'state'=>'queued',
    'policy'=>Crypt::encryptString(json_encode([...($first->policy??[]),'unsent_retry'=>['number'=>1,'previous_key'=>$first->operation_key,'at'=>$clock->now()->format(DATE_ATOM)]],JSON_THROW_ON_ERROR))];
DB::table('domains_operations')->insert($row);
$child=Operation::where('operation_key',$childKey)->sole();
$first->fresh()->update(['state'=>'rejected','safe_code'=>'renewal_definitely_unsent','policy'=>[...($first->policy??[]),
    'unsent_retry'=>['number'=>0,'next_key'=>$childKey,'proof_hash'=>EnomAnswers::identity($first),'next_at'=>$clock->now()->format(DATE_ATOM)]]]);
same(true,RenewalRetries::resolved($first->fresh(),$d->fresh()),'The first attempt is resolved retry history');
// The retry child was also closed unsent and returned before departure.
DB::transaction(function()use($d,$child,$clock):void{
    $l=HoldingLocks::take($d->fresh());$op=$l['operations']->firstWhere('id',$child->id);
    FundsWaits::closeRenewal($op,$l,$clock->now(),$l['transactions']->where('invoice_id',$op->invoice_id)->pluck('id')->all());
});
(new Refunds($clock))->creditRejected((int)$child->id);
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Settled retry history allows departure');
$owner=(int)$d->user_id;$before=$cash($owner);
$error=null;try{App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,'1.20','0.00','departed-retry-late');}catch(Throwable $e){$error=$e;}
same(null,$error?->getMessage(),'A late receipt accepts the resolved predecessor and closed retry child');
(new Refunds($clock))->creditRejected((int)$child->id);
same($before+120,$cash($owner),'The late receipt returns once to the owner');
// Neither an open child nor a missing retry link grants admission.
foreach(['open','missing'] as $case){
    DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);
    $saved=$child->fresh()->getRawOriginal();
    DB::table('domains_operations')->where('id',$child->id)->update($case==='open'?['state'=>'queued']:['operation_key'=>$childKey.':missing']);
    $refused=false;try{RenewalCycles::beforePayment((int)$invoice->id,$clock);}catch(DomainException){$refused=true;}
    same(true,$refused,'An unresolved retry chain refuses departed admission: '.$case);
    DB::table('domains_operations')->where('id',$child->id)->update($saved);
}
echo "A departed receipt follows resolved retry history to its closed child.\n";
$nativeSuccessful=true;
