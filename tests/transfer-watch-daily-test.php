<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real parser, account ledger and atomic send guard are needed to test checkout headroom.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$transferEnvironment=\Paymenter\Extensions\Servers\Domains\Data\Environment::Test;
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,EnomTestSendLimit as Limit,EnomTestSendLimitSchema as Tables,HistoryAcceptances,Runtime};
app()->instance(Clock::class,$clock);Tables::install();$cohort=[];
for($i=0;$i<5;$i++){
    $op=$prepare(placeholderComFixtureName('daily-watch-'.$i.'-placeholder.com'));$observe->fault='pending';$observe->unowned=true;$observe->orders=20;$observe->countSends=true;
    $before=Limit::usage('account-enom')['day'];$r=$reconcile->run($op->id);
    same(1,Limit::usage('account-enom')['day']-$before,'Normally pending transfers spend one status read, not a history scan');
    same(['TP_GetOrder'],$observe->calls,'Pending polling reads neither charge history nor ownership');
    same(false,$r->policy['reconciliation']['staff_alert'],'A normal wait stays automatic');$cohort[]=$r;
}
// First-poll contention must retain the one-read path; a full twenty-order scan cannot fit six reads.
$op=$prepare(placeholderComFixtureName('first-deferred-watch-placeholder.com'));$observe->fault='pending';$observe->unowned=true;$observe->orders=20;$observe->countSends=true;
$budget=new AccountReadBudget($clock);$before=Limit::usage('account-enom')['day'];
for($i=0;$i<25;$i++){$token=$budget->acquire($binding,RequestOrigin::Background);$budget->release($binding,RequestOrigin::Background,$token);}
$r=$reconcile->run($op->id);same([],$observe->calls,'First capacity refusal makes no provider read');
same(0,$r->policy['reconciliation']['polls'],'First refusal has no completed pending check');
$clock->instant=$clock->now()->modify('+61 seconds');
app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new AccountReadBudget($clock,6));
$r=$reconcile->run($op->id);
same(['TP_GetOrder'],$observe->calls,'A refused first pending check retries with one status read under the six-read guard');
same(1,Limit::usage('account-enom')['day']-$before,'First refusal and recovery spend only one send');
same(1,$r->policy['reconciliation']['polls'],'The first pending check completes without staff recovery');
same(false,$r->policy['reconciliation']['staff_alert'],'Recovered ordinary pending needs no staff action');
app()->instance(ReadLimiter::class,new AccountReadBudget($clock,48));
$op=$cohort[0];$observe->name=$op->domain->domain;$clock->instant=new DateTimeImmutable('@'.$op->policy['reconciliation']['next_at']);
// Existing account traffic approaches our transfer headroom floor, independently of minute usage.
$used=Limit::usage('account-enom')['day'];$rows=[];
for($i=$used;$i<499;$i++){$rows[]=['account'=>Limit::account('account-enom'),'command'=>'Check','sent_at'=>$clock->now()->getTimestamp()-120];}
DB::table(Tables::SENDS)->insert($rows);$observe->calls=[];
// Another send wins after the read budget's precheck. The physical admission must still enforce headroom atomically.
$observe->beforeSend=fn()=>Limit::admit(Environment::Test,'account-enom','Check');
$r=$reconcile->run($op->id);same([],$observe->calls,'Headroom refusal occurs before a physical transfer read');
same(500,Limit::usage('account-enom')['day'],'Transfer scope cannot cross half our rolling-day allowance');
$clock->instant=$clock->now()->modify('+61 seconds');$reconcile->run($op->id);same([],$observe->calls,'A later minute cannot spend checkout daily headroom');
Limit::admit(Environment::Test,'account-enom','Check');same(501,Limit::usage('account-enom')['day'],'Checkout retains capacity and the narrowed transfer scope does not leak');
echo "Light pending polling and atomic rolling-day checkout headroom passed.\n";$nativeSuccessful=true;
