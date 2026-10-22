<?php
// Synthetic admission-hold recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{Rdap,RenewalAcceptance,RuntimeComposition,RuntimeDispatch,RuntimePorts,RuntimeSchema,RuntimeWork,StaffOperationReview,StaffOperations,TransferPrecheck,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
app()->instance(Clock::class,$clock);
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.release']);Auth::setUser($staff);
$release=static function($op)use($staff,$clock):void {
    $op=$op->fresh();$domain=$op->domain()->firstOrFail();
    $ticket=StaffOperationReview::ticket($staff->id,$domain->id,$op->id,'release',StaffOperationReview::stamp($op,$domain),$clock);
    (new StaffOperations($clock))->run($staff,$domain->id,$op->id,'release',$ticket,'Corrected admission reader and reviewed unsent renewal');
};
[$op,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('release-admission-placeholder.com'));[$engine,$writer,$completion]=$compose($b);
$state->hold='unknown';same('held',$engine->run($op->id,$writer)->state,'Unconfirmed provider state holds before dispatch');
same('renewal_state_unconfirmed',$op->fresh()->safe_code,'Exact admission refusal');same([],($op->fresh()->attempt??[]),'No original attempt');
Auth::login($staff);
expect(in_array('After correcting the cause, choose Release unsent hold. The renewal engine repeats every admission check before any send and holds the renewal again if a check still fails.',Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::review($op->fresh()),true),'Unsent admission review names normal readmission');
$baseline=$op->fresh()->getRawOriginal();
// Raw fixture writes model retained/adversarial evidence without manufacturing a new obligation.
$restore=static fn()=>DB::table('domains_operations')->where('id',$op->id)->update($baseline);
$set=static function(array $attributes)use($op):void {Operation::withoutEvents(fn()=>$op->fresh()->update($attributes));};
$refuse=static function(string $why)use($op,$d,$release):void {
    $before=[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal(),DB::table('invoices')->get()->map(fn($row)=>(array)$row)->all(),DB::table('invoice_transactions')->get()->map(fn($row)=>(array)$row)->all()];
    try{$release($op);throw new RuntimeException('Release incorrectly accepted: '.$why);}catch(DomainException){}
    same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal(),DB::table('invoices')->get()->map(fn($row)=>(array)$row)->all(),DB::table('invoice_transactions')->get()->map(fn($row)=>(array)$row)->all()],'Refusal changes no retained state: '.$why);
};
$included=[RenewalCode::State,RenewalCode::Cost,RenewalCode::Owner,RenewalCode::AutoRenew,RenewalCode::Term,RenewalCode::Authority];
foreach(RenewalCode::cases() as $code){
    $restore();$set(['safe_code'=>$code->value]);
    if(in_array($code,$included,true)){$release($op);same('queued',$op->fresh()->state,'Correctable refusal releases: '.$code->value);same(null,$op->fresh()->safe_code,'Refusal cleared');}
    else{$refuse($code->value);}
}
foreach([
    ['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent']],
    ['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent','execution'=>null]],
    ['attempt'=>['send_state'=>'possibly_sent']],
    ['started_at'=>$clock->now()],['request_fingerprint'=>str_repeat('a',64)],
    ['lease_expires_at'=>$clock->now()->modify('+60 seconds')],['completed_at'=>$clock->now()],
    ['cost'=>['estimated'=>'8.00','currency'=>'USD']],['provider_order_id'=>'9002'],
    ['confirmed_expires_at'=>'2028-10-18'],['policy'=>[...$op->fresh()->policy,'dispatch_attempted_at'=>$clock->now()->format(DATE_ATOM)]],
] as $evidence){$restore();$set($evidence);$refuse('non-virgin '.implode(',',array_keys($evidence)));
    if(!Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::virgin($op->fresh())){expect(!isset(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($op->fresh())['admission']),'Non-virgin request has no release instruction');}}
$restore();$payment=DB::table('invoice_transactions')->where('invoice_id',$invoice->id)->sole();
DB::table('invoice_transactions')->where('id',$payment->id)->update(['amount'=>'0.00']);$refuse('stale payment despite paid label');
DB::table('invoice_transactions')->where('id',$payment->id)->update(['amount'=>$payment->amount]);
$set(['funding'=>[...($op->fresh()->funding??[]),'refund'=>['amount'=>$op->amount]]]);$refuse('refunded funding');
$restore();$d->service->update(['status'=>'cancelled']);$refuse('cancelled lifecycle');DB::table('services')->where('id',$op->service_id)->update(['status'=>'active']);
$restore();$cancel=App\Models\ServiceCancellation::create(['service_id'=>$op->service_id,'type'=>'immediate','reason'=>'Synthetic renewal stop']);$refuse('immediate cancellation');$cancel->delete();
$restore();
$set(['policy'=>[...$op->fresh()->policy,'renewal_admission'=>['key'=>str_repeat('b',32),'until'=>$clock->now()->modify('+360 seconds')->format(DATE_ATOM)]]]);
$release($op);same('queued',$op->fresh()->state,'Staff release queues existing paid obligation');
expect(!isset($op->fresh()->policy['renewal_admission']),'Release removes retained admission claim');
expect(!isset($op->fresh()->policy['renewal_execution']),'Release removes stale hold guidance and alert flags');
expect(!isset(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($op->fresh())['admission']),'Queued request has no held-release instruction');
same(0,$state->writes,'Staff release grants no send');
$offline=static fn()=>throw new LogicException('No unrelated exchange permitted');$features=new WriteFeatures(renewal:true);
$composition=RuntimeComposition::make($b,$renewBindings,$clock,$features,new WriteAcceptance($b,true,true,25),new RenewalAcceptance($b,true,true,25,true),
    new TransferPrecheck(new Rdap($offline)),new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($b,$offline),
    new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Transfer($b,$offline),$offline,new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($b,$offline));
$composition['renewal']=$engine;$composition['renewal_writer']=$writer;$composition['renewal_completion']=$completion;
$ports=new RuntimePorts;$ports->register($b->fingerprint,fn()=>$composition);$work=new RuntimeWork($clock,$ports);
RuntimeSchema::install();$dispatch=new RuntimeDispatch($clock);app()->instance(RuntimeDispatch::class,$dispatch);
$dispatch->enqueue($op->id);Queue::assertPushed(RunOperation::class,fn($job)=>$job->operationId===$op->id);
Cache::flush();$state->calls=[];(new RunOperation($op->id))->handle($work);
same('held',$op->fresh()->state,'Unfixed reader still refuses normal runtime admission');same(0,$state->writes,'Release cannot bypass original refusal');
$state->hold='False';$release($op);Cache::flush();$state->calls=[];
$money=[DB::table('invoices')->count(),DB::table('invoice_transactions')->count(),$op->fresh()->invoice_id];
(new RunOperation($op->id))->handle($work);same('pending',$op->fresh()->state,'Fixed reader freshly admits through normal runtime');
$admissionReads=[ReadCommand::Info,ReadCommand::RenewalInfo,ReadCommand::Check,ReadCommand::Info,ReadCommand::Renewal,ReadCommand::Balance];
same([...$admissionReads,...$admissionReads],$state->calls,'Both complete admission read sets repeated before send');same(1,$state->writes,'Exactly one admitted send');
(new RunOperation($op->id))->handle($work);same(1,$state->writes,'Duplicate runtime delivery cannot send again');
same($money,[DB::table('invoices')->count(),DB::table('invoice_transactions')->count(),$op->fresh()->invoice_id],'No new invoice or payment required');
echo "Unsent renewal admission release and runtime readmission passed\n";$nativeSuccessful=true;
