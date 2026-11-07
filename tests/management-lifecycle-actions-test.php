<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
$off=new DomainManagement($bindings,$clock);
[$d,$driver]=$holding(placeholderComFixtureName('manage-default-dns-placeholder.com'));$state->ns=['dns1.name-services.com','dns2.name-services.com'];$state->apply=false;$op=$submit($d,$driver,'nameservers',['nameservers'=>['dns3.name-services.com','dns4.name-services.com']]);same('succeeded',$management->run($op->id,$driver)->state,'Equivalent Enom default DNS sets');
Cache::flush();$state->apply=true;$state->renew=true;$op=$submit($d,$driver,'auto_renew_off');same('succeeded',$management->run($op->id,$driver)->state,'Provider renewal disabled with fresh readback');
[$d,$driver]=$holding(placeholderComFixtureName('manage-email-recovery-placeholder.com'));$state->afterWrite=static function()use($state){$state->fault='GetDomainInfo';};$op=$submit($d,$driver,'auth_code_email');same('uncertain',$management->run($op->id,$driver)->state,'Accepted email awaiting owned readback');same(true,$op->fresh()->attempt['management_ack']['email_accepted'],'Original accepted email retained before failed read');$state->fault='';$d->service->update(['status'=>'suspended']);same('succeeded',$off->reconcile($op->id,$driver)->state,'Read-only reconciliation survives lifecycle pause and gates off');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SynchAuthInfo')),'Email never resent during recovery');
[$d,$driver]=$holding(placeholderComFixtureName('manage-expired-claim-placeholder.com'));$op=$submit($d,$driver,'lock',['locked'=>false]);$op->update(['state'=>'running','started_at'=>$clock->now()->modify('-400 seconds'),'lease_expires_at'=>$clock->now()->modify('-1 second'),'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32)]]);same('held',$off->run($op->id,$driver)->state,'Expired unsealed claims quarantine with writes off');same([],$state->calls,'Crash quarantine no network');same($op->fresh()->getAttributes(),$management->reconcile($op->id,$driver)->getAttributes(),'Unsealed held operation remains unchanged');
[$d,$driver]=$holding(placeholderComFixtureName('manage-duplicate-delivery-placeholder.com'));$op=$submit($d,$driver,'lock',['locked'=>false]);$duplicate=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true);$state->during=static function()use($duplicate,$op,$driver){
    $nativeBeforeDuplicate=nativeFinancialSnapshot($op);
    same('running',$duplicate->run($op->id,$driver)->state,'Duplicate delivery preserves winning claim');
    same($nativeBeforeDuplicate,nativeFinancialSnapshot($op),'Duplicate management worker preserves native/financial state');
};same('succeeded',$management->run($op->id,$driver)->state,'Winning worker completes one write');
[$d,$driver]=$holding(placeholderComFixtureName('manage-free-privacy-placeholder.com'));$freeEnabled=true;$freeCalls=[];
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
$nativeSuccessful=true;echo "Native management scenario passed\n";
