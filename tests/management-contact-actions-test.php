<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
[$d,$driver,$original]=$holding(placeholderComFixtureName('manage-contacts-placeholder.com'));$contact=$original->payload['contacts']['registrant'];$contact['first_name']='Changed';$contact['job_title']='Director';$op=$submit($d,$driver,'contacts',['role'=>'all','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Four roles plus optional fields read back');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='Contacts'))[0][1];same(false,isset($write['ContactType']),'All roles one command without single ContactType');same('Changed',$write['TechFirstName'],'All-role fields');
Cache::flush();$contact['first_name']='Single';$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Single contact role readback');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='Contacts'))[1][1];same('TECH',$write['ContactType'],'Single role command');same(false,isset($write['AdminFirstName']),'No other role fields');
Cache::flush();$contact['email_address']='Mixed.Case@Example.com';$state->afterWrite=static function()use($state):void{foreach($state->contacts as $role=>$fields){$state->contacts[$role]['EmailAddress']=strtolower($fields['EmailAddress']);}};$op=$submit($d,$driver,'contacts',['role'=>'all','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Email case is not a change');
// Enom takes a role it does not keep and reads it back NotCollected: a definite end, never a false confirmation.
$notKept=static function()use($state):void{$state->contacts['tech']=['techcontactmatch'=>'NotCollected'];};
Cache::flush();$state->afterWrite=$notKept;$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);$done=$management->run($op->id,$driver);same(['rejected','management_provider_refused'],[$done->state,$done->safe_code],'A role the registrar does not keep ends the request');expect(ManagementEvidence::resolved($done,$d->fresh()),'That request does not block the domain');
Cache::flush();$state->fault='GetContacts';$state->afterWrite=$notKept;$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);same('uncertain',$management->run($op->id,$driver)->state,'A lost readback stays uncertain');$state->fault='';$done=$management->reconcile($op->id,$driver);same(['rejected','management_provider_refused'],[$done->state,$done->safe_code],'Reconciling a stuck request ends it from the read');
Cache::flush();$op=$submit($d,$driver,'lock',['locked'=>false]);same('succeeded',$management->run($op->id,$driver)->state,'Tools work again after a role the registrar does not keep');
$ignore=ini_set('zend.exception_ignore_args','0');$sensitive=$contact;$sensitive['email_address']='CONTACT-TRACE-SENTINEL';
try{\Paymenter\Extensions\Servers\Domains\Registrars\Enom\ManagementInput::validate('contacts',['contact'=>$sensitive],$d->domain);throw new \RuntimeException('Expected validation refusal.');}catch(\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $e){expect(!str_contains(print_r($e->getTrace(),true),'CONTACT-TRACE-SENTINEL'),'Validation trace redacts contact values');}finally{ini_set('zend.exception_ignore_args',$ignore);}
$nativeSuccessful=true;echo "Native management scenario passed\n";
