<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\ContactProfile;
[$d,$driver,$original]=$holding('contacts'); $contact=$original->payload['contacts']['registrant']; $input=['role'=>'all','contact'=>$contact];
try { $submit($d,$driver,'contacts',$input+['profile_id'=>'100']); throw new LogicException('Caller profile admitted'); } catch (DomainException) {}
try { $submit($d,$driver,'contacts',$input); throw new LogicException('Unstaged association admitted'); } catch (DomainException) {}
$stage=$submit($d,$driver,'contact_profile',$input); same('succeeded',$management->run($stage->id,$driver)->state,'Separate durable profile creation');
same(['contactAdd'],array_column($writes(),0),'Stage gets only contactAdd');
$profile=ContactProfile::findOrFail($stage->fresh()->attempt['management_reads'][0]['facts']['local_profile_id']);
same((int)$d->user_id,(int)$profile->user_id,'Profile belongs to holding owner');
same($binding->fingerprint,$profile->account_fingerprint,'Profile belongs to registrar account');
$id=$profile->details['provider_id']; $stageNonce=hash('sha256','stage-association'); Cache::flush(); $ms->calls=[]; $ms->apply=false;
$op=$management->request($user,$d->id,'contacts',$input,$stageNonce,$driver);
same('uncertain',$management->run($op->id,$driver)->state,'Association ack without readback remains uncertain');
same(['contactDomainAssociate'],array_column($writes(),0),'Association gets its own one-write grant');
expect(!isset($op->fresh()->attempt['management_reads'][0]['facts']['contacts']),'Unconfirmed association retains no desired-as-observed contacts');
same($op->id,$management->request($user,$d->id,'contacts',$input,$stageNonce,$driver)->id,'Same request remains idempotent');
$management->run($op->id,$driver); same(1,count($writes()),'Async association never retries');
$clock->instant=$clock->now()->modify('+1 hour'); $ms->route='apibatch'; $ms->ids=array_fill_keys(['registrant','administrative','technical','billing'],$id);
same('succeeded',$management->reconcile($op->id,$driver)->state,'Delayed readback confirms association'); same(1,count($writes()),'Readback sends no association');
try { $submit($d,$driver,'contacts',$input); throw new LogicException('Stage reused'); } catch (DomainException) {}
Cache::flush(); $ms->route='api'; $ms->apply=true; $newStage=$submit($d,$driver,'contact_profile',$input);
same('succeeded',$management->run($newStage->id,$driver)->state,'Later matching profile stage');
same($op->id,$management->request($user,$d->id,'contacts',$input,$stageNonce,$driver)->id,'Retry preserves original stage despite newer matching stage');
foreach (['default','mismatch','contactAdd'] as $fault) {
    [$d,$driver,$original]=$holding('profile-'.$fault); $input=['role'=>'registrant','contact'=>$original->payload['contacts']['registrant']];
    $ms->fault=$fault; $op=$submit($d,$driver,'contact_profile',$input);
    same('uncertain',$management->run($op->id,$driver)->state,'Missing nondefault exact profile proof stays unresolved');
    same(['contactAdd'],array_column($writes(),0),'No association or profile update after invalid creation proof');
    try { $submit($d,$driver,'contacts',$input); throw new LogicException('Unproven profile associated'); } catch (DomainException) {}
    $management->run($op->id,$driver); same(1,count($writes()),'Unknown stage never recreates profile');
}
[$d,$driver,$original]=$holding('delayed-profile'); $input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];
$stage=$submit($d,$driver,'contact_profile',$input); same('succeeded',$management->run($stage->id,$driver)->state,'New isolated stage');
$ms->calls=[]; $op=$submit($d,$driver,'contacts',$input);
same('queued',$management->run($op->id,$driver)->state,'Creation write slot defers association'); same([],$ms->calls,'Busy slot performs no reads or writes');
$clock->instant=$clock->now()->modify('+181 seconds'); Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
same('succeeded',$management->run($op->id,$driver)->state,'Delayed association uses fresh named profile proof after slot expires');
same(['getDomainInfo','contactList','contactDomainAssociate','getDomainInfo','contactList'],array_column($ms->calls,0),'Fresh exact profile read precedes delayed association');
[$d,$driver,$original]=$holding('registry-revoked'); $input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
$op=$submit($d,$driver,'contact_profile',$input); $tlds[1]->fresh()->update(['registry_fields'=>[['name'=>'unaccepted']]]);
same('held',$management->run($op->id,$driver)->state,'Unaccepted registry schema refuses profile creation'); same([],$writes(),'Registry gate emits no write');
$nativeSuccessful=true; echo "NameSilo isolated contact management passed\n";
