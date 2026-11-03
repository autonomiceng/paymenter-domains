<?php
// Synthetic management contacts through the genuine durable engine. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\Environment;
$transferEnvironment=Environment::Live;
require __DIR__.'/Support/management-namesilo.php';
$legacyTransferExchange=$exchange;$exchange=static fn($origin,$cmd,$p,$ms)=>$legacyTransferExchange(str_replace('https://www.namesilo.com','https://ote.namesilo.com',$origin),$cmd,$p,$ms);
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management;

[$d,,$original]=$holding('phone-equivalence');
$input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];
$formatted=static function($origin,$command,$p,$timeout)use($managementExchange):string {
    $body=$managementExchange($origin,$command,$p,$timeout);
    if($command==='contactList'){
        $data=json_decode($body,true,24,JSON_THROW_ON_ERROR);$phone=$data['reply']['contact']['phone'];
        $data['reply']['contact']['phone']=substr($phone,0,3).'-'.substr($phone,3,3).'-'.substr($phone,6);
        return json_encode($data,JSON_THROW_ON_ERROR);
    }return $body;
};
$driver=new Management($binding,$formatted);
$stage=$submit($d,$driver,'contact_profile',$input);
same('succeeded',$management->run($stage->id,$driver)->state,'Documented US/CA phone spelling verifies the isolated management profile');
// A single owner intent durably owns both mutations, with no staging submission.
[$d,$driver,$original]=$holding('automatic');
$input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];$nonce=hash('sha256','automatic');
$op=$management->request($user,$d->id,'contacts',$input,$nonce,$driver);
same('contacts',$op->action,'The owner request remains the contact change');
same('queued',$management->run($op->id,$driver)->state,'Creation finishes before association can claim a new write slot');
same(['contactAdd'],array_column($writes(),0),'One automatic isolated profile creation');
Cache::flush();
same('succeeded',$management->run($op->id,$driver)->state,'The same owner request automatically associates its verified profile');
same(['contactAdd','contactDomainAssociate'],array_column($writes(),0),'Each command is sent once');
$sent=array_values(array_filter($ms->calls,fn($c)=>$c[0]==='contactDomainAssociate'))[0][1];
same(['domain','technical'],array_keys($sent),'Only the selected role is associated');
same($op->id,$management->request($user,$d->id,'contacts',$input,$nonce,$driver)->id,'Request replay keeps the original sequence');
$management->run($op->id,$driver);same(2,count($writes()),'Completed sequence cannot replay either mutation');
// Replacing an untouched request retires its unstarted prerequisite as well.
[$d,$driver,$original]=$holding('replace-unstarted');$input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
$first=$submit($d,$driver,'contacts',$input);$other=$input;$other['contact']['first_name']='Bea';
$second=$submit($d,$driver,'contacts',$other);
same('rejected',$first->fresh()->state,'A newer unsent choice retires the old request');
$stages=$d->operations()->where('action','contact_profile')->orderBy('id')->get();
same('rejected',$stages->first()->state,'The replaced prerequisite cannot remain queued');
// Reconstruction can recover a returned ID by reading only that ID. An unknown creation never replays.
foreach(['default','mismatch','contactAdd','lost'] as $fault){
    [$d,$driver,$original]=$holding('sequence-'.$fault);$input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
    $op=$submit($d,$driver,'contacts',$input);$ms->fault=$fault;$management->run($op->id,$driver);
    same(['contactAdd'],array_column($writes(),0),'Unproven creation never associates');
    $stage=$d->operations()->where('action','contact_profile')->sole();same('uncertain',$stage->state,'Unproven creation retains its sealed attempt');
    $ms->fault='';$ms->route='apibatch';Cache::flush();$restarted=new Management($binding,$managementExchange);
    $management->run($op->id,$restarted,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);
    if(in_array($fault,['default','mismatch'],true)){
        same(['contactAdd'],array_column($writes(),0),'Recovery finishes only its prerequisite in this execution');
        $management->run($op->id,$restarted,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);
        same('succeeded',$op->fresh()->state,'A returned profile ID resumes automatically after exact readback');
        same(['contactAdd','contactDomainAssociate'],array_column($writes(),0),'Known-ID recovery never creates another profile');
        $sent=array_values(array_filter($ms->calls,fn($c)=>$c[0]==='contactDomainAssociate'))[0][1];
        same(['domain','registrant','administrative','technical','billing'],array_keys($sent),'All selects exactly the four supported roles');
    }else{
        same('queued',$op->fresh()->state,'Missing original profile receipt cannot establish a prerequisite');
        same(['contactAdd'],array_column($writes(),0),'Unknown-ID recovery sends neither creation nor association');
    }
}
// A lost association acknowledgement is recoverable by role readback, never by another association.
[$d,$driver,$original]=$holding('lost-association');$input=['role'=>'admin','contact'=>$original->payload['contacts']['admin']];
$op=$submit($d,$driver,'contacts',$input);$management->run($op->id,$driver);Cache::flush();$ms->fault='lost';
same('uncertain',$management->run($op->id,$driver)->state,'A lost association retains uncertainty');
$management->run($op->id,$driver);same(2,count($writes()),'Unknown association cannot replay');
$ms->fault='';$ms->route='apibatch';same('succeeded',$management->reconcile($op->id,$driver)->state,'Independent role readback proves a lost association');
same(2,count($writes()),'Association recovery remains read-only');
// Current native ownership and owner-bound profile identity are required after creation, too.
foreach(['owner','profile'] as $drift){
    [$d,$driver,$original]=$holding('drift-'.$drift);$input=['role'=>'registrant','contact'=>$original->payload['contacts']['registrant']];
    $op=$submit($d,$driver,'contacts',$input);$management->run($op->id,$driver);Cache::flush();
    $stage=$d->operations()->where('action','contact_profile')->sole();
    if($drift==='owner'){\Illuminate\Support\Facades\DB::table('services')->where('id',$d->service_id)->update(['user_id'=>commerceUser()->id]);}
    else{$id=$stage->attempt['management_reads'][0]['facts']['local_profile_id'];\Illuminate\Support\Facades\DB::table('domains_contact_profiles')->where('id',$id)->update(['user_id'=>commerceUser()->id]);}
    try{$management->run($op->id,$driver);}catch(DomainException){}
    same(['contactAdd'],array_column($writes(),0),'Drift cannot associate a profile');
}
// A sealed but unconsumed prerequisite is replaced, never replayed, by an ordinary fresh save.
require __DIR__.'/Support/namesilo-write-wire.php';
\Paymenter\Extensions\Servers\Domains\Services\LiveReservationSchema::install();
[$d,,$original]=$holding('proven-unsent');$ms->route='apibatch';
$transport=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\nsWriteTransport($binding,$clock);
$driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
$GLOBALS['nsReply']=static fn($cmd,$p)=>$managementExchange('https://ote.namesilo.com/apibatch',$cmd,$p,25000);
$input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];$old=$submit($d,$driver,'contacts',$input);
$child=$d->operations()->where('action','contact_profile')->sole();$GLOBALS['nsOperation']=$child->id;
config(['domains.provider_send_guard'=>static function():void {
    if((\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($GLOBALS['nsOperation'])->attempt['seal']??null)==='sealed'){throw new RuntimeException('Synthetic prewire refusal');}
}]);
$management->run($old->id,$driver,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);$child->refresh();
same(['held','management_definitely_unsent'],[$child->state,$child->safe_code],'The prerequisite retains exact unconsumed grant proof');
$evidence=$child->attempt;same('sealed',$evidence['seal'],'The failed prerequisite keeps its seal');
config(['domains.provider_send_guard'=>null]);Cache::flush();
$fresh=$submit($d,$driver,'contacts',$input);
expect($fresh->id!==$old->id,'A fresh ordinary save replaces a proven-unsent pair even for identical details');
same(['rejected','rejected'],[$old->fresh()->state,$child->fresh()->state],'Replacement closes both old operations');
same($evidence,array_diff_key($child->fresh()->attempt,['management_terminal'=>true]),'Retirement keeps every original seal and proof field');
$newChild=$d->operations()->where('action','contact_profile')->orderByDesc('id')->first();$GLOBALS['nsOperation']=$newChild->id;
$management->run($fresh->id,$driver,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);Cache::flush();$GLOBALS['nsOperation']=$fresh->id;
same('succeeded',$management->run($fresh->id,$driver,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background)->state,'Fresh contact save completes automatically');
$physical=$GLOBALS['nsPhysical'];$management->run($old->id,$driver);$management->run($child->id,$driver);
same($physical,$GLOBALS['nsPhysical'],'The old child and parent never replay');
same(['contactAdd','contactDomainAssociate'],array_values(array_filter($physical,fn($c)=>\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\AdmittedTransport::feature($c)!=='catalogue')),'Exactly one creation and association are physically dispatched');
$nativeSuccessful=true;echo "NameSilo management normalization and automatic contact sequence passed.\n";
