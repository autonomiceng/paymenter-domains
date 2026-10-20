<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Replayed payment and stale RunOperation job.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver,Clock,OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation,DispatchReadiness,DriverResult,RequestContext};
use Illuminate\Support\Facades\Queue;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,DomainManagement,DomainOperations,OperationClaims,OperationReconciliation,
    RenewalAcceptance,RenewalAdmission,RenewalAuthority,RenewalCompletion,RenewalExecution,RenewalWriter,RuntimeDispatch,RuntimePorts,RuntimeWork,WriteFeatures};

// Supply accepted ports so a missing hold cannot pass merely because no executor was installed.
$preflight=new class implements OperationPreflight {
    public int $calls=0;
    public function inspect(Operation $op,Domain $domain,RequestContext $context):DispatchReadiness
    {$this->calls++;throw new LogicException('Held transfer must not enter provider preflight.');}
};
$writer=new class implements AdmittedRegistrarDriver {
    public array $commands=[];
    public function register(AdmittedOperation $op,RequestContext $context):DriverResult
    {$this->commands[]='register';throw new LogicException('Unexpected registration command.');}
    public function transferIn(AdmittedOperation $op,RequestContext $context):DriverResult
    {$this->commands[]='transferIn';throw new LogicException('Unexpected transfer command.');}
    public function renew(AdmittedOperation $op,RequestContext $context):DriverResult
    {$this->commands[]='renew';throw new LogicException('Unexpected renewal command.');}
};
$claims=new OperationClaims($bindings,$clock);$engine=new DomainOperations($claims,$preflight,$writer,$clock);
$account=Account::where('server_id',$early->server_id)->sole();$binding=CatalogueAcceptances::binding($account);
$features=new WriteFeatures(true,true,true,true);$acceptance=new RenewalAcceptance($binding,true,true,25);
$authority=new RenewalAuthority($bindings,$clock);
$renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$acceptance,$clock),$acceptance,$features,$clock);
$unusedExchange=static fn()=>throw new LogicException('Unexpected ancillary provider command.');
$ports=new RuntimePorts;
$ports->register($binding->fingerprint,fn()=>[
    'acquisition'=>$engine,'reconciliation'=>new OperationReconciliation($claims,$bindings,$clock),
    'renewal'=>$renewal,'renewal_writer'=>new RenewalWriter($binding,$features,$acceptance,fn()=>$renewal,$unusedExchange),
    'renewal_completion'=>new RenewalCompletion($bindings,$clock),'management'=>new DomainManagement($bindings,$clock,$features,true),
    'management_protocol'=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,$unusedExchange),
]);
app()->instance(Clock::class,$clock);app()->instance(RuntimeDispatch::class,new RuntimeDispatch($clock));
expect($account->processing_enabled&&$ports->accepted($account),'Replay worker has an accepted processing composition');
expect(app(RuntimeDispatch::class)->current($early->fresh()),'Replay worker has current operation authority');
$providerReads=$reads->calls;$beforeReplay=$early->fresh()->getRawOriginal();
$nativeBeforeReplay=nativeFinancialSnapshot($early);
$inputs->paymentAccepted($early->id);
(new RunOperation($early->id))->handle(new RuntimeWork($clock,$ports));
$replayed=$early->fresh();
same('held',$replayed->state,'Payment and worker replay preserve late contact hold');
same('transfer_setup_review_required',$replayed->safe_code,'Payment and worker replay preserve staff review gate');
same($beforeReplay,$replayed->getRawOriginal(),'Payment and worker replay leave corrected operation unchanged');
same($nativeBeforeReplay,nativeFinancialSnapshot($early),
    'Payment and worker replay preserve native/financial state (reminder alerts and deliveries excluded)');
Queue::assertNotPushed(RunOperation::class);
same(0,$preflight->calls,'Held transfer never enters provider preflight');
same([],$writer->commands,'Payment and worker replay send no provider command');
same($providerReads,$reads->calls,'Payment and worker replay make zero provider reads');
