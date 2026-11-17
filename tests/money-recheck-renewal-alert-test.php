<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';
use App\Models\{Gateway,InvoiceTransaction,Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffDomainInputs;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client,ReadCommand,Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomAnswers,HistoryAcceptances,HoldingLocks,InvoiceMoney,ObligationAlerts,OperationTerminalProof,PricingRule,RegistrarRegistry,Refunds,ReminderSchema,RenewalCompletion,StaffSettingsReview as Review,StaffSettingsWrites};

ReminderSchema::install();
app()->instance(Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);
$staff=commerceUser();$role=Role::create(['name'=>'Synthetic final answer','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update']]);$staff->update(['role_id'=>$role->id]);
[$op,$domain,$binding,$invoice]=$makeRenewal(placeholderComFixtureName('money-recheck-renewal-placeholder.com'));
MoneyReceiptGateway::$lost=false;
$gateway=Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'receipt_recheck_renewal']);
[$engine,$renewWriter]=$compose($binding);$state->lost=true;
same('uncertain',$engine->run($op->id,$renewWriter)->state,'Original renewal response was lost');
$writes=$state->writes;
// Retained historical escalation from the unresolved renewal, before the final answer.
$op=$op->fresh();$policy=$op->policy;$policy['renewal_execution']['staff_alert']=true;$op->update(['policy'=>$policy]);
$scan=static function()use($domain,$clock):void{
    DB::transaction(function()use($domain,$clock):void{
        $l=HoldingLocks::take($domain->fresh());$now=$clock->now()->getTimestamp();
        ObligationAlerts::finish($l['domain'],ObligationAlerts::retained($l,$now),$now);
    });
};
$open=static fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','operation_review')->whereNull('resolved_at');
$scan();same(1,$open()->count(),'Unresolved renewal correctly needs review before the answer');
// Existing scheduled read fixture, with an empty complete order history and unchanged expiry.
$history=new class($transport) implements Transport {
    public function __construct(private Transport $domain){}
    public function send($env,ReadCommand $command,array $parameters,string $uid,string $token,int $timeoutMs):string{
        if($command===ReadCommand::Orders){return enomHistoryEnvelope(enomHistoryPage([], $parameters['Start'], 0));}
        return $this->domain->send($env,$command,$parameters,$uid,$token,$timeoutMs);
    }
};
$historyRegistry=new RegistrarRegistry;
$historyRegistry->register('enom',['token'],$verify,fn($b,$c)=>new Driver(new Client($b,'account-enom',$c['token'],$history),['com']));
$historyBindings=new AccountBindings($historyRegistry);
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',60,2592000,'enom_renewal_v1'));
$clock->instant=(new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']))->modify('+25 hours');Cache::flush();
$state->after='2027-10-18';$state->lost=false;
same('uncertain',(new RenewalCompletion($historyBindings,$clock))->run($op->id)->state,'No-order read alone does not settle the original outcome');
$op=$op->fresh();
$review=new Review($staff->id,$op->server_id,null,'enom_answer',Review::ticket($staff->id,$op->server_id,null,'enom_answer',Review::stamp($op->server_id,null)),'Record synthetic no-order answer');
$settings=new StaffSettingsWrites($native,$sellers,$bindings);
$settings->run($review,['operation_id'=>$op->id,'attempt_hash'=>EnomAnswers::identity($op),'outcome'=>'not_processed','order_reference'=>'','case_reference'=>'',
    'evidence'=>'Complete history has no order and the registrar expiry is unchanged.','confirmed'=>true],$clock->now());
$op=$op->fresh();
same(['rejected','refund_approved',true],[$op->state,$op->safe_code,EnomAnswers::unprocessed($op,$domain->fresh())],'Audited final no-order answer closes renewal');
same(true,OperationTerminalProof::safe($op,$domain->fresh()),'Terminal proof independently accepts the final answer');
same(['refunded'],array_column($op->funding['receipt_return_results'],'state'),'Original renewal payment was refunded');
$rows=InvoiceTransaction::where('invoice_id',$op->invoice_id)->orderBy('id')->get();
same([PricingRule::minor($op->amount),-PricingRule::minor($op->amount)],$rows->map(fn($r)=>PricingRule::minor($r->amount))->all(),'No later payment exists');
$record=DB::transaction(fn()=>InvoiceMoney::load(HoldingLocks::take($domain->fresh()),(int)$op->invoice_id));
same([0,true],[$record['pool'],$record['settled']],'Invoice money record proves fully settled return');
$money=$nativeMoney();$scan();$first=$open()->count();$scan();$second=$open()->count();
$inputs=StaffDomainInputs::for([$domain->fresh()])[$domain->id];
$visible=array_values(array_column(array_filter($inputs['alerts'],fn($a)=>$a['request']===(int)$op->id),'code'));
echo 'RENEWAL_RECHECK '.json_encode(['answer'=>$op->policy['enom_answer']['outcome'],'state'=>$op->state,'domain_status'=>$domain->fresh()->status,
    'staff_alert'=>$op->policy['renewal_execution']['staff_alert']??null,'pool'=>$record['pool'],'settled'=>$record['settled'],
    'native_amounts'=>$rows->pluck('amount')->all(),'open_alerts_first'=>$first,'open_alerts_repeat'=>$second,
    'staff_alert_codes_for_renewal'=>$visible],JSON_THROW_ON_ERROR)."\n";
same($money,$nativeMoney(),'Attention inspection writes no money');
same($writes,$state->writes,'Final answer and inspection send no replacement renewal');
same([0,0,[]],[$first,$second,$visible],'Settled terminal renewal must leave no operation-review staff task');
$nativeSuccessful=true;
