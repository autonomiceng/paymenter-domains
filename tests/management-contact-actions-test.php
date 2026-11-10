<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\{ManagementCheck,OperationActions,StaffReviewHistory};
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,OwnerPorts,StaffOperationReview,StaffOperations,WriteFeatures};
[$d,$driver,$original]=$holding(placeholderComFixtureName('manage-contacts-placeholder.com'));$contact=$original->payload['contacts']['registrant'];$contact['first_name']='Changed';$contact['job_title']='Director';$op=$submit($d,$driver,'contacts',['role'=>'all','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Four roles plus optional fields read back');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='Contacts'))[0][1];same(false,isset($write['ContactType']),'All roles one command without single ContactType');same('Changed',$write['TechFirstName'],'All-role fields');
Cache::flush();$contact['first_name']='Single';$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Single contact role readback');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='Contacts'))[1][1];same('TECH',$write['ContactType'],'Single role command');same(false,isset($write['AdminFirstName']),'No other role fields');
Cache::flush();$contact['email_address']='Mixed.Case@Example.com';$state->afterWrite=static function()use($state):void{foreach($state->contacts as $role=>$fields){$state->contacts[$role]['EmailAddress']=strtolower($fields['EmailAddress']);}};$op=$submit($d,$driver,'contacts',['role'=>'all','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Email case is not a change');
// Enom takes a role it does not keep and reads it back NotCollected: a definite end, never a false confirmation.
$notKept=static function()use($state):void{$state->contacts['tech']=['techcontactmatch'=>'NotCollected'];};
Cache::flush();$state->afterWrite=$notKept;$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);$done=$management->run($op->id,$driver);same(['rejected','management_provider_refused'],[$done->state,$done->safe_code],'A role the registrar does not keep ends the request');expect(ManagementEvidence::resolved($done,$d->fresh()),'That request does not block the domain');
Cache::flush();$state->fault='GetContacts';$state->afterWrite=$notKept;$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);same('uncertain',$management->run($op->id,$driver)->state,'A lost readback stays uncertain');$state->fault='';
// Staff Check status must report this conclusive read as failed, including its retained History.
$staff=staffUser(['admin.domains.view','admin.domains.operations.recheck']);Illuminate\Support\Facades\Auth::setUser($staff);
app()->instance(DomainManagement::class,$management);app()->instance(OwnerPorts::class,new OwnerPorts(static fn()=>$driver));app()->instance(StaffOperations::class,new StaffOperations($clock));
$writes=count(array_filter($state->calls,fn($call)=>$call[0]==='Contacts'));
$action=collect(OperationActions::make($op->fresh(),StaffOperationReview::stamp($op->fresh(),$d->fresh())))->first(fn($action)=>$action->getName()==='recheck');
($action->getActionFunction())([]);$done=$op->fresh();
same(['rejected','management_provider_refused'],[$done->state,$done->safe_code],'Staff check ends a stuck request from the read');
same($writes,count(array_filter($state->calls,fn($call)=>$call[0]==='Contacts')),'Staff check never repeats the contact write');
$words=ManagementCheck::words($done);
same(['Failed',false,'The registrar did not take this change. The customer can try again.'],[$words['title'],$words['confirmed'],$words['detail']],'Rejected status check names failure and the next step');
$toast=collect(session()->get('filament.notifications'))->last();
same([$words['title'],$words['body']],[$toast['title'],$toast['body']],'The staff toast shows the rejection result');
same('Status check: failed',collect(StaffReviewHistory::rows($done))->last()['event'],'History keeps the failed check result');
// Earlier entries saved only confirmed; their recorded after-state still distinguishes rejection.
$legacy=$done->replicate();$policy=$legacy->policy;foreach($policy['staff_actions'] as &$entry){if(isset($entry['management_check'])){$entry['management_check']=['confirmed'=>false];}}unset($entry);$legacy->policy=$policy;
same('Status check: failed',collect(StaffReviewHistory::rows($legacy))->last()['event'],'Earlier rejected checks use their saved after-state');
Cache::flush();$op=$submit($d,$driver,'lock',['locked'=>false]);same('succeeded',$management->run($op->id,$driver)->state,'Tools work again after a role the registrar does not keep');
$ignore=ini_set('zend.exception_ignore_args','0');$sensitive=$contact;$sensitive['email_address']='CONTACT-TRACE-SENTINEL';
try{\Paymenter\Extensions\Servers\Domains\Registrars\Enom\ManagementInput::validate('contacts',['contact'=>$sensitive],$d->domain);throw new \RuntimeException('Expected validation refusal.');}catch(\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $e){expect(!str_contains(print_r($e->getTrace(),true),'CONTACT-TRACE-SENTINEL'),'Validation trace redacts contact values');}finally{ini_set('zend.exception_ignore_args',$ignore);}
$nativeSuccessful=true;echo "Native management scenario passed\n";
