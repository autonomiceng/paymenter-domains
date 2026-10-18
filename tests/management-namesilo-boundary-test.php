<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities, Capability, ManagementRequest, ManagementResult, RequestContext, Support};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementCorrections, ManagementPayload};
foreach (['replace','add','remove','old_ip','privacy_terms'] as $mode) {
    [$d,$driver]=$holding('adverse-'.$mode);
    $adverse=new class($binding,fn()=>throw new LogicException('Adverse driver reached wire')) extends ManagementProtocol {
        public string $mode;
        public function capabilities(): Capabilities { return new Capabilities($this->binding,['lock'=>new Capability(Support::Supported)]); }
        public function validate(string $a,#[SensitiveParameter] array $p,string $n): array { return ManagementPayload::normalize($a,$p,$n,'namesilo'); }
        public function prepare(ManagementRequest $r,RequestContext $c): array { return match($this->mode) {'replace'=>['locked'=>true],'add'=>$r->payload()+['injected'=>true],'old_ip'=>$r->payload()+['old_ip'=>'192.0.2.1'],'privacy_terms'=>$r->payload()+['privacy_terms'=>[]],default=>[]}; }
        public function observe(ManagementRequest $r,RequestContext $c): ManagementResult { return new ManagementResult(true); }
        protected function apply(ManagementRequest $r,RequestContext $c): ManagementResult { $this->write($r,$c,'domainUnlock',$r->payload()); return new ManagementResult(true); }
        protected function readCommands(): array { return []; }
        protected function validateRead(string $c,#[SensitiveParameter] array $p): void { throw new LogicException; }
        protected function writeCommand(string $a): string { return 'domainUnlock'; }
        protected function origin(RequestContext $c): string { return 'https://synthetic.invalid'; }
    };
    $adverse->mode=$mode; $op=$submit($d,$adverse,'lock',['locked'=>false]);
    same('held',$management->run($op->id,$adverse)->state,'Changed prepared desired state refuses');
    same('absent',$op->fresh()->attempt['seal'],'Adverse preparation never seals');
    same(['locked'=>false],$op->fresh()->payload,'Frozen desired state preserved');
}
[$d,$driver,$original]=$holding('correction',false); $observe->fault='auto';
same('uncertain',$reconcile->run($original->id)->state,'Flags mismatch prevents acquisition completion');
$original=$original->fresh(); $snapshot=$original->getAttributes(); $originalWire=$state->calls;
commerceReject(fn()=>$submit($d,$driver,'auto_renew_off'));
commerceReject(fn()=>$management->request($user,$d->id,'nameservers',['nameservers'=>['ns3.example.com','ns4.example.com']],$key(),$driver,correction:$original->operation_key));
foreach (['nameservers','privacy_disable','auto_renew_off','contact_profile','contacts'] as $action) {
    Cache::flush(); $input=match($action) {'nameservers'=>['nameservers'=>$original->payload['nameservers']],
        'contact_profile','contacts'=>['role'=>'all','contact'=>$original->payload['contacts']['registrant']],default=>[]};
    $op=$management->request($user,$d->id,$action,$input,$key(),$driver,correction:$original->operation_key);
    same('succeeded',$management->run($op->id,$driver)->state,'Explicit frozen acquisition correction '.$action);
    same($snapshot,$original->fresh()->getAttributes(),'Correction never changes original acquisition or financial evidence');
    same(null,$d->fresh()->renewal_policy['billing_anchor']??null,'Correction never sets financial anchor');
}
same(['changeNameServers','removePrivacy','removeAutoRenewal','contactAdd','contactDomainAssociate'],array_column($writes(),0),'No purchase/transfer replay');
foreach ([null,'different-acquisition-key'] as $link) {
    $peer=$op->fresh()->replicate(); $policy=$peer->policy; unset($policy['management']['correction']);
    if ($link!==null) { $policy['management']['correction']=$link; } $peer->policy=$policy;
    same(false,ManagementCorrections::resolvedFor($peer,$original,$d),'Unlinked or differently linked correction cannot release acquisition peer gate');
}
$observe->fault=''; same('succeeded',$reconcile->run($original->id)->state,'Original acquisition reconciles after bound corrections');
expect(isset($d->fresh()->renewal_policy['billing_anchor']),'Independent financial reconciliation establishes anchor');
same($originalWire,$state->calls,'Correction and completion never replay original transfer');
foreach (['queued','held'] as $stateName) {
    [$d,$driver,$original]=$holding('idle-correction-'.$stateName,false);
    $op=$management->request($user,$d->id,'auto_renew_off',[],$key(),$driver,correction:$original->operation_key);
    if ($stateName==='held') { $ms->fault='owner'; same('held',$management->run($op->id,$driver)->state,'Not-yet-owned correction stays unsent'); }
    same([],$writes(),'Idle correction has no writes');
    $before=$state->calls; same('succeeded',$reconcile->run($original->id)->state,'Acquisition completes past idle linked correction '.$stateName);
    same($before,$state->calls,'Idle correction never replays acquisition');
}
[$d,$driver,$original]=$holding('uncertain-correction',false); $ms->fault='lost';
$op=$management->request($user,$d->id,'auto_renew_off',[],$key(),$driver,correction:$original->operation_key);
same('uncertain',$management->run($op->id,$driver)->state,'Sent correction remains unresolved');
same('uncertain',$reconcile->run($original->id)->state,'Sealed uncertain correction blocks acquisition completion');
same(null,$d->fresh()->renewal_policy['billing_anchor']??null,'Uncertain correction never establishes acquisition anchor');
[$d,$driver,$original]=$holding('correction-race',false);
$op=$management->request($user,$d->id,'auto_renew_off',[],$key(),$driver,correction:$original->operation_key);
$ms->during=fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$original->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']);
same('held',$management->run($op->id,$driver)->state,'Reversed acquisition authority refuses correction seal'); same([],$writes(),'No write after payment drift');
$nativeSuccessful=true; echo "NameSilo management grant boundaries passed\n";
