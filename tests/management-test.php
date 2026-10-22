<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
[$d,$driver,$original]=$holding('manage-basic.com');
commerceReject(fn()=>$management->request(commerceUser(),$d->id,'lock',['locked'=>false],$key(),$driver));
commerceReject(fn()=>$submit($d,$driver,'auth_code_display'));same([],$state->calls,'Unsupported/stranger actions do no I/O');
$nonce=$key();$op=$management->request($user,$d->id,'lock',['locked'=>false],$nonce,$driver);
same($op->id,$management->request($user,$d->id,'lock',['locked'=>false],$nonce,$driver)->id,'Idempotent request');
commerceReject(fn()=>$management->request($user,$d->id,'lock',['locked'=>true],$nonce,$driver));
$off=new DomainManagement($bindings,$clock);same('queued',$off->run($op->id,$driver)->state,'Disabled writes leave virgin request queued');same([],$state->calls,'Default gate no provider I/O');
same('succeeded',$management->run($op->id,$driver)->state,'Lock fresh readback confirms');same(['GetDomainInfo','SetRegLock','GetDomainInfo','GetRegLock'],array_column($state->calls,0),'One lock write with owned readback');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$before=count($state->calls);$management->run($op->id,$driver);same($before,count($state->calls),'Completed write never replayed');same(true,ManagementEvidence::resolved($op->fresh(),$d),'Durable proof resolves management');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
commerceReject(fn()=>$op->fresh()->update(['policy'=>array_diff_key($op->fresh()->policy,['management'=>true])]));
foreach([array_diff_key($op->fresh()->attempt,['management_reads'=>true]),[...$op->fresh()->attempt,'management_reads'=>[]]] as $changed){
    commerceReject(fn()=>$op->fresh()->update(['attempt'=>$changed]));
}
$r=new ManagementRequest($driver->capabilities()->binding,'forged',$d->domain,'lock',['locked'=>true],'456');$ctx=new RequestContext($r->binding,RequestOrigin::Interactive,$clock->now()->modify('+100 seconds'),new RequestBudget(10),$clock);
commerceReject(fn()=>$driver->perform($r,$ctx,$management));same($before,count($state->calls),'Forged descriptor no wire authority');
Cache::flush();$ns=$submit($d,$driver,'nameservers',['nameservers'=>['NS3.Example.com.','ns4.example.com']]);same('succeeded',$management->run($ns->id,$driver)->state,'Canonical nameservers read back');
Cache::flush();$email=$submit($d,$driver,'auth_code_email');same('auth_email_accepted',$management->run($email->id,$driver)->safe_code,'Email accepted distinct from delivery');commerceReject(fn()=>$submit($d,$driver,'auth_code_email'));
[$d,$driver]=$holding('manage-unknown.com');$state->fault='SetRegLock';$op=$submit($d,$driver,'lock',['locked'=>false]);same('uncertain',$management->run($op->id,$driver)->state,'Malformed write and unmatched readback uncertain');$nativeBeforeReplay=nativeFinancialSnapshot($op);
$calls=count($state->calls);$management->run($op->id,$driver);same($calls,count($state->calls),'Unknown send never replayed');
same($nativeBeforeReplay,nativeFinancialSnapshot($op),'Unknown management replay preserves native/financial state');
$state->fault='';$state->locked=false;same('succeeded',$management->reconcile($op->id,$driver)->state,'Explicit read-only reconciliation confirms');same(true,$op->fresh()->attempt['management_reads'][1]['confirmed'],'Reconcile appends confirming readback');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SetRegLock')),'Reconciliation never repeats write');
[$d,$driver]=$holding('manage-foreign.com');$state->fault='owner';$op=$submit($d,$driver,'lock',['locked'=>false]);same('held',$management->run($op->id,$driver)->state,'Foreign provider owner refuses send');same(['GetDomainInfo'],array_column($state->calls,0),'Private/write followups blocked');
[$d,$driver]=$holding('manage-permission.com');$role=Role::create(['name'=>'Manage staff','permissions'=>['admin.domains.manage']]);$staff=commerceUser();$staff->update(['role_id'=>$role->id]);$op=$management->request($staff,$d->id,'lock',['locked'=>false],$key(),$driver,'staff_correction');$role->update(['permissions'=>[]]);same('queued',$management->run($op->id,$driver)->state,'Fresh permission revocation refuses claim');same([],$state->calls,'Revoked staff no I/O');
[$d,$driver]=$holding('manage-race.com');$op=$submit($d,$driver,'lock',['locked'=>false]);$state->during=static fn()=> $d->service->update(['status'=>'suspended']);same('held',$management->run($op->id,$driver)->state,'Native drift during preflight refuses seal');same(['GetDomainInfo'],array_column($state->calls,0),'Changed authority no send');
[$d,$driver]=$holding('manage-contacts.com');$contact=$original->payload['contacts']['registrant'];$contact['first_name']='Changed';$contact['job_title']='Director';$op=$submit($d,$driver,'contacts',['role'=>'all','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Four roles plus optional fields read back');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='Contacts'))[0][1];same(false,isset($write['ContactType']),'All roles one command without single ContactType');same('Changed',$write['TechFirstName'],'All-role fields');
Cache::flush();$contact['first_name']='Single';$op=$submit($d,$driver,'contacts',['role'=>'tech','contact'=>$contact]);same('succeeded',$management->run($op->id,$driver)->state,'Single contact role readback');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='Contacts'))[1][1];same('TECH',$write['ContactType'],'Single role command');same(false,isset($write['AdminFirstName']),'No other role fields');
[$d,$driver]=$holding('manage-default-dns.com');$state->ns=['dns1.name-services.com','dns2.name-services.com'];$state->apply=false;$op=$submit($d,$driver,'nameservers',['nameservers'=>['dns3.name-services.com','dns4.name-services.com']]);same('succeeded',$management->run($op->id,$driver)->state,'Equivalent Enom default DNS sets');
Cache::flush();$state->apply=true;$state->renew=true;$op=$submit($d,$driver,'auto_renew_off');same('succeeded',$management->run($op->id,$driver)->state,'Provider renewal disabled with fresh readback');
[$d,$driver]=$holding('manage-email-recovery.com');$state->afterWrite=static function()use($state){$state->fault='GetDomainInfo';};$op=$submit($d,$driver,'auth_code_email');same('uncertain',$management->run($op->id,$driver)->state,'Accepted email awaiting owned readback');same(true,$op->fresh()->attempt['management_ack']['email_accepted'],'Original accepted email retained before failed read');$state->fault='';$d->service->update(['status'=>'suspended']);same('succeeded',$off->reconcile($op->id,$driver)->state,'Read-only reconciliation survives lifecycle pause and gates off');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SynchAuthInfo')),'Email never resent during recovery');
[$d,$driver]=$holding('manage-expired-claim.com');$op=$submit($d,$driver,'lock',['locked'=>false]);$op->update(['state'=>'running','started_at'=>$clock->now()->modify('-400 seconds'),'lease_expires_at'=>$clock->now()->modify('-1 second'),'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32)]]);same('held',$off->run($op->id,$driver)->state,'Expired unsealed claims quarantine with writes off');same([],$state->calls,'Crash quarantine no network');same($op->fresh()->getAttributes(),$management->reconcile($op->id,$driver)->getAttributes(),'Unsealed held operation remains unchanged');
[$d,$driver]=$holding('manage-duplicate-delivery.com');$op=$submit($d,$driver,'lock',['locked'=>false]);$duplicate=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true);$state->during=static function()use($duplicate,$op,$driver){
    $nativeBeforeDuplicate=nativeFinancialSnapshot($op);
    same('running',$duplicate->run($op->id,$driver)->state,'Duplicate delivery preserves winning claim');
    same($nativeBeforeDuplicate,nativeFinancialSnapshot($op),'Duplicate management worker preserves native/financial state');
};same('succeeded',$management->run($op->id,$driver)->state,'Winning worker completes one write');
[$d,$driver]=$holding('manage-free-privacy.com');$freeEnabled=true;$freeCalls=[];
$free=new class($driver->capabilities()->binding,static function($origin,$command,$parameters,$timeout)use(&$freeEnabled,&$freeCalls){$freeCalls[]=$command;if($command==='toggle'){$freeEnabled=$parameters['enabled'];}return $freeEnabled?'1':'0';}) extends \Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol {
    public function capabilities(): \Paymenter\Extensions\Servers\Domains\Data\Capabilities { return new \Paymenter\Extensions\Servers\Domains\Data\Capabilities($this->binding,['privacy'=>new \Paymenter\Extensions\Servers\Domains\Data\Capability(\Paymenter\Extensions\Servers\Domains\Data\Support::Supported)],\Paymenter\Extensions\Servers\Domains\Data\PrivacyMode::Free); }
    public function validate(string $a,#[\SensitiveParameter] array $p,string $name):array{return ['enabled'=>$a==='privacy_enable'];}
    public function prepare(ManagementRequest $r,RequestContext $c):array{return $r->payload();}
    protected function readCommands():array{return ['read'];}
    protected function validateRead(string $command,#[\SensitiveParameter] array $parameters):void{if($parameters!==[]){throw new \DomainException('Invalid synthetic read.');}}
    protected function writeCommand(string $action):string{return 'toggle';}
    protected function origin(RequestContext $c):string{return 'https://synthetic.invalid/'.$c->origin->value;}
    protected function apply(ManagementRequest $r,RequestContext $c):\Paymenter\Extensions\Servers\Domains\Data\ManagementResult{$this->write($r,$c,'toggle',$r->payload());return $this->observe($r,$c);}
    public function observe(ManagementRequest $r,RequestContext $c):\Paymenter\Extensions\Servers\Domains\Data\ManagementResult{return new \Paymenter\Extensions\Servers\Domains\Data\ManagementResult($this->read($r,$c,'read',[])===($r->payload()['enabled']?'1':'0'));}
};
commerceReject(fn()=>$submit($d,$free,'privacy_purchase'));same([],$freeCalls,'Free capability refuses paid purchase before billing reads');$op=$submit($d,$free,'privacy_disable');same(null,$op->invoice_id,'Free privacy toggle has no invoice');same('succeeded',$management->run($op->id,$free)->state,'Neutral free privacy one write and fresh readback');same(['toggle','read'],$freeCalls,'Free driver contract enforced');
[$d,$driver]=$holding('manage-empty-email.com');$state->fault='empty_ack';$op=$submit($d,$driver,'auth_code_email');same('uncertain',$management->run($op->id,$driver)->state,'Empty success envelope never acknowledges email');same(false,isset($op->fresh()->attempt['management_ack']),'No fabricated email acknowledgement');
for($i=1;$i<20;$i++){$management->reconcile($op->id,$driver);}same(20,count($op->fresh()->attempt['management_reads']),'Readback history bounded');$calls=count($state->calls);same($op->fresh()->getAttributes(),$management->reconcile($op->id,$driver)->getAttributes(),'Exhausted history leaves the operation unchanged');same($calls,count($state->calls),'Exhausted history refuses before provider reads');
[$d,$driver]=$holding('manage-retire-local.com');$op=$submit($d,$driver,'lock',['locked'=>false]);same(true,ManagementEvidence::resolved($op,$d),'Queued unsent request never blocks renewal');same('rejected',$management->retire($user,$op->id)->state,'Unsent local request retired');same([],$state->calls,'Local retirement no I/O');
[$d,$driver]=$holding('manage-definite-refusal.com');$state->fault='refused';$op=$submit($d,$driver,'lock',['locked'=>false]);same('rejected',$management->run($op->id,$driver)->state,'Documented nonfinancial provider refusal final');same(true,ManagementEvidence::resolved($op->fresh(),$d),'Definite nonfinancial refusal releases renewal blocker');same('sealed',$op->fresh()->attempt['seal'],'Refusal never relabels send as unsent');
[$d,$driver]=$holding('manage-delayed-email.com');$one=$submit($d,$driver,'auth_code_email');$clock->instant=$clock->now()->modify('+11 minutes');$two=$submit($d,$driver,'auth_code_email');same('succeeded',$management->run($one->id,$driver)->state,'Delayed first email accepted');Cache::flush();same('held',$management->run($two->id,$driver)->state,'Send-time cooldown blocks queued second email');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SynchAuthInfo')),'Queued emails cannot burst');
$ignore=ini_set('zend.exception_ignore_args','0');$sensitive=$contact;$sensitive['email_address']='CONTACT-TRACE-SENTINEL';
try{\Paymenter\Extensions\Servers\Domains\Registrars\Enom\ManagementInput::validate('contacts',['contact'=>$sensitive],$d->domain);throw new \RuntimeException('Expected validation refusal.');}catch(\Paymenter\Extensions\Servers\Domains\Services\ContactValidation $e){expect(!str_contains(print_r($e->getTrace(),true),'CONTACT-TRACE-SENTINEL'),'Validation trace redacts contact values');}finally{ini_set('zend.exception_ignore_args',$ignore);}
[$d,$driver]=$holding('example.com');$op=$submit($d,$driver,'lock',['locked'=>false]);
foreach ([['running','sealed',0,360],['running','absent',0,-1],['uncertain','sealed',20,-1],['pending','sealed',0,-1],['succeeded','sealed',0,-1]] as [$pollState,$seal,$reads,$lease]) {
    $attempt=['seal'=>$seal,'management_reads'=>array_fill(0,$reads,['confirmed'=>false])];
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$pollState,'attempt'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($attempt)), 'lease_expires_at'=>$clock->now()->modify(($lease>=0?'+':'').$lease.' seconds')]);
    $before=$op->fresh()->getAttributes();$calls=count($state->calls);
    for($i=0;$i<2;$i++){same($before,$management->reconcile($op->id,$driver)->getAttributes(),'Ineligible poll preserves every operation field');}
    same($calls,count($state->calls),'Ineligible polls make no provider calls');
}
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['authority_generation'=>$d->authority_generation+1]);
try{$management->reconcile($op->id,$driver);expect(false,'Changed authority must still fail');}
catch(DomainException $e){same('Management binding changed.',$e->getMessage(),'Real binding failure remains visible');}
$nativeSuccessful=true;
echo "management boundary passed\n";
