<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\Credit;
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers,HoldingDeparture,HoldingLocks,MoneyOutcome,PricingRule,RenewalRetries,StaffHoldingReview};

$nativeSuccessful=false;
staffPanel();
$first=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$cash=static fn(int $owner)=>PricingRule::minor((string)(Credit::where('user_id',$owner)->where('currency_code','USD')->value('amount')??'0.00'));
// The renewal was paid, its first attempt was refused before sending and a retry on the same paid invoice completed.
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$first->amount,'0.00','departure-retry-paid');
$childKey=$first->operation_key.':replacement:1';
$row=$first->fresh()->getRawOriginal();unset($row['id']);
$row=[...$row,'operation_key'=>$childKey,'retry_number'=>1,'state'=>'succeeded',
    'policy'=>Crypt::encryptString(json_encode([...($first->policy??[]),'unsent_retry'=>['number'=>1,'previous_key'=>$first->operation_key,'at'=>$clock->now()->format(DATE_ATOM)]],JSON_THROW_ON_ERROR))];
DB::table('domains_operations')->insert($row);
$child=Operation::where('operation_key',$childKey)->sole();
$first->fresh()->update(['state'=>'rejected','safe_code'=>'renewal_definitely_unsent','policy'=>[...($first->policy??[]),
    'unsent_retry'=>['number'=>0,'next_key'=>$childKey,'proof_hash'=>EnomAnswers::identity($first),'next_at'=>$clock->now()->format(DATE_ATOM)]]]);
same(true,RenewalRetries::resolved($first->fresh(),$d->fresh()),'The first attempt is resolved retry history');
$owner=(int)$d->user_id;$before=$cash($owner);

$preview=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp());
same(null,$preview['block'],'Resolved retry history with a completed child does not block confirmation');
same([[],[],[(int)$child->id]],[$preview['close'],$preview['credit'],$preview['kept']],'The completed child keeps the paid invoice');

$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation completes with resolved retry history');
$held=$d->fresh();
same(['departed',0],[$held->status,$held->renewal_policy['departure']['credit_minor']],'No credit is returned for the earned renewal');
same([['operation_id'=>(int)$child->id,'invoice_id'=>(int)$invoice->id,'outcome'=>'kept']],$held->renewal_policy['departure']['invoices'],'The record keeps the completed child invoice');
same([$before,'paid','rejected','succeeded'],[$cash($owner),$invoice->fresh()->status,$first->fresh()->state,$child->fresh()->state],'Money, invoice and both attempts stay as they were');
echo "Resolved retry history follows its completed child at departure.\n";
$nativeSuccessful=true;
