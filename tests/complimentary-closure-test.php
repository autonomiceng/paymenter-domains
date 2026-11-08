<?php
// Native audited support answers must close issued zero receipts without inventing a payment.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use App\Models\{Credit,Invoice,InvoiceTransaction,Role,Service};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,AdmittedRegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{DriverResult,Disposition,SendState,ResultReason,Evidence};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,ComplimentaryAccounts,DomainOperations,NativeDomainGuard,HolderPricing,RegistrarAnswers,Refunds,StaffOperations,StaffOperationReview,StaffSettingsReview as Review,StaffSettingsWrites};
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
app()->instance(Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);app()->instance(Refunds::class,new Refunds($clock));
$staff=commerceUser();$staff->update(['role_id'=>Role::create(['name'=>'Complimentary closure','permissions'=>[
    'admin.domains.view','admin.domains.manage','admin.domains.fund','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.invoices.update','admin.domains.operations.credit']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Audited closure account',$clock);
staffPanel();$settings=new StaffSettingsWrites($native,$sellers,$bindings);
$supportWriter=new class($writer) implements AdmittedRegistrarDriver {
    public function __construct(private $inner){}
    public function register($op,$context):DriverResult{return $this->inner->register($op,$context);}
    public function renew($op,$context):DriverResult{throw new LogicException('No renewal in this fixture.');}
    public function transferIn($op,$context):DriverResult{
        $this->inner->transferIn($op,$context);
        return new DriverResult($op->binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(transferReference:'9001'));
    }
};
$engine=new DomainOperations($claims,$preflight,$supportWriter,$clock);$writer->engine=$engine;
foreach(['register','transfer']as$action){
    $clock->instant=$now;
    $op=$new(placeholderComFixtureName('comp-support-'.$action.'-placeholder.com'),false,true,$action);
    $op=$engine->execute($op->id);same('sealed',$op->attempt['seal']??null,'The support fixture retains a real sealed attempt');
    if($action==='transfer'){
        // Synthetic final readback retains the reference captured by the original accepted send.
        $op->update(['policy'=>[...$op->policy,'reconciliation'=>['transfer_status'=>'rejected','reason'=>'transfer_rejection_unreconciled']]]);
    }
    $clock->instant=$now->modify('+25 hours');expect(RegistrarAnswers::eligible($op,$clock->now()),'The original attempt is eligible for an account-specific final answer');
    $attempt=$op->attempt;$calls=$writer->calls;$money=$nativeMoney();
    $review=new Review($staff->id,$op->server_id,null,'enom_answer',Review::ticket($staff->id,$op->server_id,null,'enom_answer',Review::stamp($op->server_id,null)),'Record final complimentary order answer');
    $input=['operation_id'=>$op->id,'attempt_hash'=>RegistrarAnswers::identity($op),'outcome'=>$action==='register'?'not_processed':'transfer_rejected',
        'order_reference'=>$action==='register'?'':'9001','case_reference'=>'synthetic-complimentary-case','evidence'=>'Registrar confirmed this account and original attempt cannot complete.',
        'confirmed'=>true,'final_confirmed'=>true,'charge'=>'0.00','registrar_refund'=>'0.00'];
    // A corrupted receipt must refuse the final answer before it can close either order.
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'pending']);
    $retained=$op->fresh()->getRawOriginal();$badMoney=$nativeMoney();$refusal=null;
    try{$settings->run($review,$input,$clock->now());}catch(DomainException $e){$refusal=$e;}
    same('Complimentary receipt authority changed.',$refusal?->getMessage(),'The staff answer refuses a non-Paid zero receipt: '.$action);
    same([$retained,$badMoney],[$op->fresh()->getRawOriginal(),$nativeMoney()],'A refused answer preserves its operation and every native money row: '.$action);
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    $review=new Review($staff->id,$op->server_id,null,'enom_answer',Review::ticket($staff->id,$op->server_id,null,'enom_answer',Review::stamp($op->server_id,null)),'Record final complimentary order answer');
    // Fail the native service projection after the final answer commits, once only.
    $failService=(int)$op->service_id;
    DB::listen(static function($q)use(&$failService):void {
        if($failService&&str_starts_with($q->sql,'update "services"')&&in_array('cancelled',$q->bindings,true)&&(int)end($q->bindings)===$failService){
            $failService=0;throw new RuntimeException('Synthetic transient closure failure');
        }
    });
    $failure=null;try{$settings->run($review,$input,$clock->now());}catch(RuntimeException $e){$failure=$e;}
    expect($failService===0&&$failure?->getMessage()==='Synthetic transient closure failure','The final answer reaches a transient native closure failure');
    $op->refresh();same('rejected',$op->state,'Final answer remains committed');
    expect(RegistrarAnswers::answer($op,$op->domain)!==null,'Retry uses the retained exact final answer');
    expect(Service::findOrFail($op->service_id)->status!=='cancelled','Failed closure has not cancelled the native service');
    same($money,$nativeMoney(),'Failed complimentary closure moves no money');
    $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);
    $page->assertActionVisible('credit')->callAction('credit',['reason'=>'Complete retained complimentary order closure'])->assertHasNoActionErrors()->assertNotified('Order closed')->assertActionHidden('credit');
    $op->refresh();
    $events=array_column(\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::rows($op),'event');
    expect(in_array('Order closed',$events,true)&&!in_array('Refund checked',$events,true),'Actual complimentary Close order history never calls it a refund');
    same(0,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->count(),'Successful staff retry clears its failed-closure alert');
    same(['rejected',$action==='register'?'registration_rejected':'transfer_rejected','cancelled'],[$op->state,$op->domain->status,Service::findOrFail($op->service_id)->status],'The final answer closes the operation, domain and native service');
    same($attempt,$op->attempt,'Support closure preserves the original sealed attempt');
    same('paid',Invoice::findOrFail($op->invoice_id)->status,'The zero receipt stays Paid');
    expect(!isset($op->funding['refund']),'No refund is invented for an account-funded order');
    $closed=[$op->fresh()->getRawOriginal(),$op->domain->fresh()->getRawOriginal(),Service::findOrFail($op->service_id)->getRawOriginal()];
    app(Refunds::class)->retryPending();
    same($closed,[$op->fresh()->getRawOriginal(),$op->domain->fresh()->getRawOriginal(),Service::findOrFail($op->service_id)->getRawOriginal()],'The paid refund sweep leaves a closed complimentary operation, domain and service untouched');
    same(null,app(Refunds::class)->creditRejected($op->id),'Repeated closure returns no credit');
    $ticket=StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,'credit',StaffOperationReview::stamp($op,$op->domain),$clock);
    (new StaffOperations($clock))->run($staff,$op->domain_id,$op->id,'credit',$ticket,'Recheck complimentary closure');
    same($money,$nativeMoney(),'Support, repeat closure and staff retry preserve native money history');
    same($calls,$writer->calls,'Support and retry never resend the registrar order');
}
same(0,Credit::count(),'Complimentary answers create no credit');same(0,InvoiceTransaction::count(),'Complimentary answers create no payment');
$nativeSuccessful=true;echo "Complimentary support closure and staff retry preserve zero receipts.\n";
