<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory;
use Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainHistory;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
$op=$new(placeholderComFixtureName('history-lifecycle-placeholder.com'));
$sent=$clock->now()->modify('+1 day');$ended=$sent->modify('+5 minutes');
$op->forceFill(['state'=>'succeeded','started_at'=>$clock->now(),'completed_at'=>$ended,'attempt'=>['dispatch_attempted_at'=>$sent->format(DATE_ATOM),'execution'=>['send_state'=>'sent']]])->saveQuietly();
$events=[StaffReviewHistory::SENT,State::Completed->label()];
$rows=collect(DomainHistory::entries($op->domain))->filter(fn($row)=>is_array($row))->keyBy('event');
same([$sent->getTimestamp(),$ended->getTimestamp()],array_map(fn($event)=>($rows[$event]['time']??null)?->getTimestamp(),$events),'The domain activity retains the actual send and completion instants after retries');
$op->forceFill(['state'=>'held','completed_at'=>null,'attempt'=>['send_state'=>'definitely_unsent']])->saveQuietly();
$rows=collect(DomainHistory::entries($op->domain))->filter(fn($row)=>is_array($row))->keyBy('event');
expect(!isset($rows[$events[0]])&&!isset($rows[$events[1]]),'An unsent claim cannot gain send or completion events');
// DNSSEC completion needs the saved independent confirmation, even for an operation marked succeeded.
foreach(['ds_add','ds_delete'] as $action){
    $op->forceFill(['action'=>$action,'state'=>'succeeded','completed_at'=>$ended,'policy'=>['management'=>['action'=>$action]],'attempt'=>['seal'=>'sealed']])->saveQuietly();
    $completion=static fn()=>collect(DomainHistory::entries($op->domain))->first(fn($row)=>is_array($row)&&($row['__key']??null)==='ended-'.$op->id);
    same(State::UnderReview->label(),$completion()['event'],'An unconfirmed succeeded DS change remains under review: '.$action);
    same($ended->getTimestamp(),$completion()['time']->getTimestamp(),'An unconfirmed DS change retains its completion instant');
    $op->forceFill(['attempt'=>['seal'=>'sealed','management_reads'=>[['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'action'=>$action,'confirmed'=>true,'facts'=>['records'=>[]]]]]])->saveQuietly();
    same(State::Completed->label(),$completion()['event'],'A confirmed DS completion stays completed: '.$action);
    foreach(['rejected','disposed','cancelled'] as $state){
        $op->forceFill(['state'=>$state,'attempt'=>[]])->saveQuietly();
        same(\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::requestStatus($op)['label'],$completion()['event'],'Other terminal DS outcomes keep their state: '.$state);
    }
}
$nativeSuccessful=true;echo "Domain request lifecycle passed.\n";
