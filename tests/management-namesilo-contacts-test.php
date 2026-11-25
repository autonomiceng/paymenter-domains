<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\ContactProfile;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
// Each case starts from the one prepared NameSilo fixture with its own completed holding: profile staging and
// association, delayed association, lost write and lost email acknowledgements, and a write-slot race retry.
$cases['contacts']=static function()use($holding,$submit,$management,$ms,$writes,$clock,$user,&$binding):void {
    [$d,$driver,$original]=$holding('contacts'); $contact=$original->payload['contacts']['registrant']; $input=['role'=>'all','contact'=>$contact];
    try { $submit($d,$driver,'contacts',$input+['profile_id'=>'100']); throw new LogicException('Caller profile admitted'); } catch (DomainException) {}
    same(null,ManagementProfiles::approved($d),'No approved details before a stage');
    $stage=$submit($d,$driver,'contact_profile',$input); same('succeeded',$management->run($stage->id,$driver)->state,'Separate durable profile creation');
    same($input,ManagementProfiles::approved($d),'Step 2 offers exactly what association requires');
    same(['contactAdd'],array_column($writes(),0),'Stage gets only contactAdd');
    $profile=ContactProfile::findOrFail($stage->fresh()->attempt['management_reads'][0]['facts']['local_profile_id']);
    same((int)$d->user_id,(int)$profile->user_id,'Profile belongs to holding owner');
    same($binding->fingerprint,$profile->account_fingerprint,'Profile belongs to registrar account');
    $id=$profile->details['provider_id']; $stageNonce=hash('sha256','stage-association'); Cache::flush(); $ms->calls=[]; $ms->apply=false;
    $op=$management->request($user,$d->id,'contacts',$input,$stageNonce,$driver);
    same('uncertain',$management->run($op->id,$driver)->state,'Association ack without readback remains uncertain');
    same(['contactDomainAssociate'],array_column($writes(),0),'Association gets its own one-write grant');
    expect(!isset($op->fresh()->attempt['management_reads'][0]['facts']['contacts']),'Unconfirmed association retains no desired-as-observed contacts');
    same(null,ManagementProfiles::approved($d),'A used stage is no longer offered');
    same($op->id,$management->request($user,$d->id,'contacts',$input,$stageNonce,$driver)->id,'Same request remains idempotent');
    $management->run($op->id,$driver); same(1,count($writes()),'Async association never retries');
    $clock->instant=$clock->now()->modify('+1 hour'); $ms->route='apibatch'; $ms->ids=array_fill_keys(['registrant','administrative','technical','billing'],$id);
    same('succeeded',$management->reconcile($op->id,$driver)->state,'Delayed readback confirms association'); same(1,count($writes()),'Readback sends no association');
    Cache::flush(); $ms->route='api'; $ms->apply=true; $newStage=$submit($d,$driver,'contact_profile',$input);
    same('succeeded',$management->run($newStage->id,$driver)->state,'Later matching profile stage');
    same($op->id,$management->request($user,$d->id,'contacts',$input,$stageNonce,$driver)->id,'Retry preserves original stage despite newer matching stage');
};
$cases['delayed-profile']=static function()use($holding,$submit,$management,$ms,$clock):void {
    [$d,$driver,$original]=$holding('delayed-profile'); $input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];
    $stage=$submit($d,$driver,'contact_profile',$input); same('succeeded',$management->run($stage->id,$driver)->state,'New isolated stage');
    $ms->calls=[]; $op=$submit($d,$driver,'contacts',$input);
    same('queued',$management->run($op->id,$driver)->state,'Creation write slot defers association'); same([],$ms->calls,'Busy slot performs no reads or writes');
    $clock->instant=$clock->now()->modify('+181 seconds'); Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
    same('succeeded',$management->run($op->id,$driver)->state,'Delayed association uses fresh named profile proof after slot expires');
    same(['getDomainInfo','contactList','contactDomainAssociate','getDomainInfo','contactList'],array_column($ms->calls,0),'Fresh exact profile read precedes delayed association');
};
$cases['unknown']=static function()use($holding,$submit,$management,$ms,$writes):void {
    [$d,$driver]=$holding('unknown'); $ms->fault='lost'; $op=$submit($d,$driver,'lock',['locked'=>false]);
    same('uncertain',$management->run($op->id,$driver)->state,'Lost response stays uncertain'); same(1,count($writes()),'One uncertain write');
    $nativeBeforeReplay = nativeFinancialSnapshot($op);
    $management->run($op->id,$driver); same(1,count($writes()),'Unknown write never replayed');
    same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
    $ms->fault=''; $ms->route='apibatch'; same('succeeded',$management->reconcile($op->id,$driver)->state,'Read-only recovery'); same(1,count($writes()),'Recovery emits no write');
};
$cases['email-unknown']=static function()use($holding,$submit,$management,$ms,$writes):void {
    [$d,$driver]=$holding('email-unknown'); $ms->fault='lost'; $op=$submit($d,$driver,'auth_code_email');
    same('uncertain',$management->run($op->id,$driver)->state,'Lost email acknowledgement stays unknown');
    $ms->fault=''; $ms->route='apibatch'; same('uncertain',$management->reconcile($op->id,$driver)->state,'Readback cannot invent original email acknowledgement');
    same(['retrieveAuthCode'],array_column($writes(),0),'Email never resent by observation');
};
$cases['slot-race']=static function()use($holding,$submit,$management,$ms,$writes):void {
    [$d,$driver]=$holding('slot-race'); $op=$submit($d,$driver,'auto_renew_off');
    $ms->during=fn()=>Cache::put('domains-write-slot:'.$op->account_fingerprint,true,25);
    same('queued',$management->run($op->id,$driver)->state,'Consume slot race defers before first send');
    same('management_deferred',$op->fresh()->safe_code,'Bounded retryable deferral'); same('absent',$op->fresh()->attempt['seal'],'Deferred operation never sealed'); same([],$writes(),'Consume race emits no write');
    Cache::flush(); same('succeeded',$management->run($op->id,$driver)->state,'Unsent deferral retries safely');
    same(['removeAutoRenewal'],array_column($writes(),0),'Retry spends only one actual write grant');
};
// The RDAP reader caches the registry bootstrap; restoring it makes every case read it as a fresh process does.
nativeFixtureCases($cases,compact('clock','ms','observe','state','rdap','management','user','engine','claims'));
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
