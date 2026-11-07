<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding='example.com';
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/WireLimit.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\Labels;
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,RegistrarCheck,Runtime};
[$op]=$prepare('send-guard.example.com');
$attempt=$op->attempt;$money=$nativeMoney();$writes=$writer->calls;
$observation->fault='send_guard';
$result=$reconcile->run($op->id);
same('enom_test_reconciliation_send_guard',$result->safe_code,'Sends OFF is not a rate cap');
same(['pending','enom_test_reconciliation_send_guard',0,'send_guard'],[$result->state,$result->policy['reconciliation']['reason'],
    $result->policy['reconciliation']['polls']??0,$result->policy['status_check']['reason']],'Paused reads retain their real cause without consuming evidence polls');
same('Status check paused: Enom sends are off',Labels::operationReason($result),'Staff sees the sending switch, not an exhausted cap or an unsent purchase');
expect(str_contains(RegistrarCheck::for($result)['body'],'Our sending settings paused this check.'),'Status check notice explains the same cause');
$legacy=clone $result;$legacy->safe_code='enom_test_reconciliation_call_cap';
same('Status check paused: Enom sends are off',Labels::operationReason($legacy),'Already retained cap codes display the saved sending-switch cause');
same([[],$attempt,$money,$writes],[$observation->calls,$result->attempt,$nativeMoney(),$writer->calls],'The switch refusal retains the sealed purchase and money without a physical read or resend');
// Once reads are allowed, even an inconclusive read must remove the obsolete switch reason.
$observation->fault='info_missing';
$result=$reconcile->run($op->id);
same('reconciliation_required',$result->safe_code,'An inconclusive admitted read clears the paused-switch code');
expect(Labels::operationReason($result)!=='Status check paused: Enom sends are off','Staff is no longer told sends are off after a real read');
// A real cap remains a distinct refusal at admission and still never repeats Purchase.
app()->instance(Runtime::class,new stdClass);
$limiter=new WireLimit(1);$limiter->onAcquire=static function():void{throw new EnomTestCallLimit('over_limit');};
app()->instance(ReadLimiter::class,$limiter);$observation->fault='';$observation->calls=[];
$result=$reconcile->run($op->id);
same(['enom_test_reconciliation_call_cap','send_limit'],[$result->safe_code,$result->policy['status_check']['reason']],'An actual rate cap retains its distinct cause');
expect(str_contains(RegistrarCheck::for($result)['body'],'Our check limit was reached.'),'A real cap retains the limit notice');
app()->offsetUnset(Runtime::class);app()->offsetUnset(ReadLimiter::class);
$result=$reconcile->run($op->id);
same('succeeded',$result->state,'The ordinary next check completes the retained order after the refusal ends');
expect(!str_contains((string)Labels::operationReason($result),'Enom sends are off'),'Completed order has no stale switch reason');
same([$money,$writes],[$nativeMoney(),$writer->calls],'Resuming checks moves no native money and never repeats Purchase');
$nativeSuccessful=true;echo "Registration status reasons distinguish sends OFF from a real cap.\n";
