<?php
// Synthetic exact-order support recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Environment,ExecutionRecord};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport,TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomAnswers,HistoryAcceptances,OperationReconciliation,ReconciliationEvidence,Refunds,RenewalAuthority,StaffSettingsReview as Review,StaffSettingsWrites};
app()->instance(Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);
$staff=commerceUser();$role=App\Models\Role::create(['name'=>'Purchase answers','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update']]);$staff->update(['role_id'=>$role->id]);
$settings=new StaffSettingsWrites($native,$sellers,$bindings);
$review=static fn($op)=>new Review($staff->id,$op->server_id,null,'enom_answer',Review::ticket($staff->id,$op->server_id,null,'enom_answer',Review::stamp($op->server_id,null)),'Record exact synthetic purchase answer');
$input=static fn($op,$outcome)=>['operation_id'=>$op->id,'attempt_hash'=>EnomAnswers::identity($op),'outcome'=>$outcome,
    'order_reference'=>$outcome==='accepted'?'9001':'','processed_at'=>'2026-10-18T05:00:01-07:00',
    'case_reference'=>'synthetic-purchase-case','evidence'=>'Registrar confirmed the account, domain, sealed attempt, exact order number and processing time with UTC offset.','confirmed'=>true];
$wireState=(object)['name'=>'','calls'=>[],'fault'=>''];
$wire=new class($wireState) implements DeadlineTransport {
    public function __construct(private $s){}
    public function send($e,$c,$r,#[SensitiveParameter] string $key):TransportResponse{throw new LogicException;}
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $key,int $ms):TransportResponse{
        same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Readback stays outside financial locks');$s=$this->s;$s->calls[]=$r->command;
        $reply=['code'=>300,'detail'=>'synthetic']+match($r->command){
            'orderDetails'=>['order_number'=>$s->fault==='id'?'9002':'9001','order_date'=>$s->fault==='time'?'2026-10-18 05:00:02':'2026-10-18 05:00:01','total'=>'99.00',
                'order_details'=>[['description'=>($s->fault==='name'?'other.example.com':$s->name).' - registration','years_qty'=>$s->fault==='term'?'2':'1',
                    'status'=>$s->fault==='status'?'Credited':'Processed','subtotal'=>'99.00']]],
            'getDomainInfo'=>['created'=>'2026-10-18','expires'=>$s->fault==='expiry'?'2026-10-18':'2027-10-18','status'=>'Active','auto_renew'=>'No','private'=>'No','locked'=>'Yes'],
            default=>throw new LogicException('Exact support order must not enumerate history'),
        };
        return new TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>$reply]));
    }
};
$readRegistry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$readRegistry->register('namesilo',['token'],$verifier,fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),['renew'],$clock));
$reads=new AccountBindings($readRegistry);$reconcile=new OperationReconciliation($claims,$reads,$clock);
foreach(['accepted','not_processed'] as $outcome){
    $clock->instant=new DateTimeImmutable('2026-10-18T12:00:00Z');$makeEngine($binding,$exchange);
    $registration->mode=$outcome==='accepted'?'300':'unknown';
    $op=$runRegistration($nsNew(placeholderComFixtureName('support-'.str_replace('_','-',$outcome).'-placeholder.com')));$attempt=$op->attempt;$calls=$registration->calls;
    $wireState->name=$op->domain->domain;$wireState->calls=[];
    commerceReject(fn()=>$settings->run($review($op),$input($op,$outcome),$clock->now()));
    $clock->instant=$clock->now()->modify('+25 hours');
    // Support is the fallback when no accepted history/timezone envelope exists.
    App\Models\Server::find($op->server_id)->settings()->where('key',HistoryAcceptances::KEY.'_register')->delete();
    expect(EnomAnswers::eligible($op,$clock->now()),'NameSilo no-ID purchase accepts a final account-bound support answer');
    if($outcome==='accepted'){
        $bad=$input($op,'not_processed');commerceReject(fn()=>$settings->run($review($op),$bad,$clock->now()));
        foreach(['','2026-10-18 05:00:01','2026-10-19T05:00:01-07:00'] as $date){$bad=$input($op,$outcome);$bad['processed_at']=$date;commerceReject(fn()=>$settings->run($review($op),$bad,$clock->now()));}
    }
    $settings->run($review($op),$input($op,$outcome),$clock->now());$op->refresh();
    same($attempt,$op->attempt,'Answer never changes the original sent attempt');
    if($outcome==='accepted'){
        $a=ReconciliationEvidence::attempt($op,$op->domain,$binding);same(ExecutionRecord::StaffAnswer,$a->execution,'Exact order carries staff provenance');
        foreach(['id','name','term','status','time','expiry'] as $fault){
            $wireState->fault=$fault;$r=$reconcile->run($op->id);expect(in_array($r->state,['pending','uncertain'],true),'A support answer never overrides mismatching readback: '.$fault);
            $p=$r->policy;$p['reconciliation']['polls']=0;$r->update(['policy'=>$p]);
        }
        $wireState->fault='';$r=$reconcile->run($op->id);same('succeeded',$r->state,'Exact supported order and owned full term resolve absent history');
        same(['9001','staff_answer','99.00'],[$r->provider_order_id,$r->attempt['reconciliation']['source'],$r->cost['actual']['amount']],'Read charge and supported order retained');
        expect(RenewalAuthority::completed($r,$r->domain,$binding),'Completion independently reconstructs exact support time and order');
        expect(!in_array('listOrders',$wireState->calls,true),'No unsupported historical search');
    }else{
        expect(isset($op->funding['refund']),'Final no-order/no-charge answer returns the payment');
        same(null,app(Refunds::class)->creditRejected($op->id),'Negative answer returns once');
    }
    $engine->execute($op->id);same($calls,$registration->calls,'Support never repeats registerDomain');
}
echo "NameSilo support answers retain account, attempt, exact order/time and original financial evidence.\n";$nativeSuccessful=true;
