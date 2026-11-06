<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\ContactProfile;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
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
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
