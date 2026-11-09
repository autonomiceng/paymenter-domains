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
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\AdmittedTransport;
use function Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{nsWriteTransport,nsUsage};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController,OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementProgress,OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds};
$originalManagementExchange=$managementExchange;
$managementExchange=static fn($origin,$cmd,$p,$ms)=>$originalManagementExchange(str_replace('https://www.namesilo.com','https://ote.namesilo.com',$origin),$cmd,$p,$ms);
$originalHolding=$holding;
$holding=static function($label)use($originalHolding,&$binding,$managementExchange){[$d,,$op]=$originalHolding($label);return [$d,new Management($binding,$managementExchange),$op];};

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
same('Contact details saved. Applying them to the domain.',ManagementProgress::for($d)['contacts']['text'],'Verified creation is visible before association is sent');
Cache::flush();
same('succeeded',$management->run($op->id,$driver)->state,'The same owner request automatically associates its verified profile');
same(['contactAdd','contactDomainAssociate'],array_column($writes(),0),'Each command is sent once');
same('Contact details verified.',ManagementProgress::for($d)['contacts']['text'],'Only both proven steps show verified');
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
// A prerequisite that used its five unsent preparation tries never reached the registrar, so an identical save replaces the pair.
[$d,,$original]=$holding('exhausted-prerequisite');$unreadable=false;
$driver=new Management($binding,static function($origin,$cmd,$p,$ms)use(&$unreadable,$managementExchange){if($unreadable){throw new RuntimeException('Synthetic unreadable registrar');}return $managementExchange($origin,$cmd,$p,$ms);});
$input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];$old=$submit($d,$driver,'contacts',$input);
$step=$d->operations()->where('action','contact_profile')->sole();$unreadable=true;$sent=count($writes());
for($round=0;$round<8&&$step->fresh()->state!=='rejected';$round++){$clock->instant=$clock->now()->modify('+16 minutes');Cache::flush();$management->run($old->id,$driver);}
same(['rejected','management_preparation_failed','absent'],[$step->fresh()->state,$step->fresh()->safe_code,$step->fresh()->attempt['seal']],'The prerequisite closes unsealed after five unsent preparation tries');
$unreadable=false;Cache::flush();
require __DIR__.'/Support/owner-pages.php';storefrontViews();$owners=new OwnedDomains($clock,new OwnerPorts(fn($b)=>$driver),$management);
$get=Request::create('/domains/'.$d->id);$get->setUserResolver(fn()=>$user);$get->setLaravelSession(app('request')->session());
$dom=storefrontDom((new OwnedDomainController($owners))->show($get,$d->id)->render());
same(1,$dom->query('//li[@id="contacts"]//a[@aria-label="Edit contacts"]')->length,'The owner can open the contacts editor after the unsent tries');
same(1,$dom->query('//li[@id="contacts"]//form[contains(@action,"/manage/contacts")]//button[@type="submit" and normalize-space()="Save"]')->length,'The contacts form has its Save button');
same(0,$dom->query('//li[@id="contacts"]//*[@data-management-pending]')->length,'The unsent pair does not hold the row');
$fresh=$submit($d,$driver,'contacts',$input);
expect($fresh->id!==$old->id,'An identical save replaces a pair held by an exhausted unsent prerequisite');
same(['rejected','rejected'],[$old->fresh()->state,$step->fresh()->state],'Replacement closes both old operations');
$management->run($old->id,$driver);$management->run($step->id,$driver);same($sent,count($writes()),'The old pair never sends');
// Reconstruction can recover a returned ID by reading only that ID. An unknown creation never replays.
foreach(['default','mismatch','contactAdd','lost'] as $fault){
    [$d,$driver,$original]=$holding('sequence-'.$fault);$input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
    $op=$submit($d,$driver,'contacts',$input);$ms->fault=$fault;$management->run($op->id,$driver);
    same(['contactAdd'],array_column($writes(),0),'Unproven creation never associates');
    $stage=$d->operations()->where('action','contact_profile')->sole();same('uncertain',$stage->state,'Unproven creation retains its sealed attempt');
    $progress=ManagementProgress::for($d)['contacts'];same((int)$op->id,$progress['id'],'Progress keeps the association request identity');
    if(in_array($fault,['default','mismatch'],true)){
        same('Sent. Checking the result…',$progress['text'],'A returned ID with unverified details shows creation readback pending');
    }else{
        same("We couldn't confirm the new contact details. Nothing else was changed. Contact support and we'll sort it out.",$progress['text'],'Missing original creation receipt needs support');
        same('warning',$progress['tone'],'Missing receipt is amber');
        $op->update(['created_at'=>$op->created_at->copy()->subSeconds(31)]);$stage->update(['created_at'=>$stage->created_at->copy()->subSeconds(31)]);
        same($progress['text'],ManagementProgress::for($d)['contacts']['text'],'A slow missing receipt never promises another creation');
    }
    $changed=$input;$changed['contact']['first_name']='Bea';
    same($op->id,$submit($d,$driver,'contacts',$changed)->id,'Creation that was or may have been sent still owns its setting');
    $ms->fault='';$ms->route='apibatch';Cache::flush();$restarted=new Management($binding,$managementExchange);
    $management->run($op->id,$restarted,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);
    if(in_array($fault,['default','mismatch'],true)){
        same(['contactAdd'],array_column($writes(),0),'Recovery finishes only its prerequisite in this execution');
        same('Contact details saved. Applying them to the domain.',ManagementProgress::for($d)['contacts']['text'],'Known-ID recovery exposes its verified creation before association');
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
same('Sent. Checking the result…',ManagementProgress::for($d)['contacts']['text'],'Association awaiting readback keeps its existing pending text');
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
[$d,,$original]=$holding('contact-sequence-wire');$ms->route='apibatch';$transport=nsWriteTransport($binding,$clock);
$driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
$GLOBALS['nsReply']=static function($cmd,$p)use($managementExchange):string {
    $body=$managementExchange('https://ote.namesilo.com/apibatch',$cmd,$p,25000);
    if($cmd==='contactList'){$data=json_decode($body,true,24,JSON_THROW_ON_ERROR);$phone=$data['reply']['contact']['phone'];
        $data['reply']['contact']['phone']=substr($phone,0,3).'-'.substr($phone,3,3).'-'.substr($phone,6);return json_encode($data,JSON_THROW_ON_ERROR);}
    return $body;
};
$money=static fn()=>array_map(static fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),['invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs']);
$before=$money();$usage=nsUsage($binding);$purchase=$original->attempt;
$op=$submit($d,$driver,'contacts',['role'=>'registrant','contact'=>$original->payload['contacts']['registrant']]);
$stage=$d->operations()->where('action','contact_profile')->sole();$GLOBALS['nsOperation']=$stage->id;
same('queued',$management->run($op->id,$driver,RequestOrigin::Background)->state,'Profile creation owns its own admitted call and write slot');
same('succeeded',$stage->fresh()->state,'The profile is proven before association');
Cache::flush();$GLOBALS['nsOperation']=$op->id;
// A new worker/transport reconstructs the durable link without recreating the profile.
$driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
same('succeeded',$management->run($op->id,$driver,RequestOrigin::Background)->state,'The original request completes through its separately sealed association');
same(['contactAdd','contactDomainAssociate'],array_values(array_filter($GLOBALS['nsPhysical'],static fn($c)=>AdmittedTransport::feature($c)!=='catalogue')),'Only two admitted mutations');
same($usage['used']+9,nsUsage($binding)['used'],'Every physical read and each separate mutation counts once');
same($usage['spend_micros'],nsUsage($binding)['spend_micros'],'Contact changes authorize no monetary spend');
same($before,$money(),'No invoice, credit, payment or queue mutation');same($purchase,$original->fresh()->attempt,'The acquisition seal is never borrowed');
$physical=$GLOBALS['nsPhysical'];$management->run($op->id,$driver,RequestOrigin::Background);
same($physical,$GLOBALS['nsPhysical'],'Completed sequence has no physical replay');
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
expect(!ManagementProgress::for($d)['contacts']['pending'],'Proven-unsent creation restores the ordinary save control');
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
[$d,$driver,$original]=$holding('single-form');
storefrontViews();
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);
$dto=$owners->present($d);same(['contacts'],array_keys($dto->management['contacts']['actions']),'The owner gets one contact-save action');
$get=Request::create('/domains/'.$d->id);$get->setUserResolver(fn()=>$user);$get->setLaravelSession(app('request')->session());
$html=(new OwnedDomainController($owners))->show($get,$d->id)->render();
expect(!str_contains($html,'Send for approval')&&!str_contains($html,'Use the approved details')&&!str_contains($html,'Step 1 of 2'),'The editor has no manual profile stage');
same(1,storefrontDom($html)->query('//form[contains(@action,"/manage/contacts")]')->length,'One ordinary contacts form');
$input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
$session=app('request')->session();$session->put('_token','synthetic-csrf');
$r=Request::create('/domains/'.$d->id.'/manage/contacts','POST',$input);
$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
$r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':contacts'));
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$controller->manage($r,$d->id,'contacts');
$op=$d->operations()->where('action','contacts')->sole();
same('queued',$op->state,'One save queues the contact change');same([],$ms->calls,'The POST performs no registrar calls');
$management->run($op->id,$driver);Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Queued creation and association finish without another owner action');
same(['contacts'],array_keys($owners->present($d->fresh())->management['contacts']['actions']),'Completed editor still offers the ordinary save');
$nativeSuccessful=true;echo "NameSilo management normalization and automatic contact sequence passed.\n";
