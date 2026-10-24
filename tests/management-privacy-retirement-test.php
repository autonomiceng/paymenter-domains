<?php
// Synthetic retired privacy money paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/management.php';
use App\Models\{Credit,Invoice,InvoiceItem,InvoiceTransaction,Role};
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,ManagementRequest,ManagementResult,RequestContext};
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,ManagementEvidence,PrivacyRetirement,RefundCandidates,RefundProof,Refunds,StaffOperationReview,StaffOperations};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries;
$product->server->settings()->create(['key'=>'domains_privacy_price','value'=>'5.00','type'=>'string']);
$refunds=new Refunds($clock);app()->instance(Refunds::class,$refunds);
$role=Role::create(['name'=>'Privacy credit','permissions'=>['admin.domains.view','admin.domains.manage','admin.invoices.update','admin.domains.operations.credit']]);
$staff=commerceUser();$staff->update(['role_id'=>$role->id]);$staffOps=new StaffOperations($clock);
$staffCredit=static function($op,?string $ticket=null)use($staff,$staffOps,$clock){$op=$op->fresh();$d=$op->domain->fresh();
    $ticket??=StaffOperationReview::ticket($staff->id,$d->id,$op->id,'credit',StaffOperationReview::stamp($op,$d),$clock);
    $staffOps->run($staff,$d->id,$op->id,'credit',$ticket,'Reviewed late privacy payment');return $ticket;};
$balance=static fn()=>\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)(Credit::where('user_id',$user->id)->value('amount')??'0.00'));
$payment=static fn($op,$amount=null,$status='succeeded')=>InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$amount??$op->amount,'fee'=>'0.00','status'=>$status]);
$seq=0;$issue=static function($action,$claim='')use(&$seq,$holding,$state,$submit,$clock){
    [$d,$driver]=$holding(placeholderComFixtureName('privacy-retired-'.++$seq.'-placeholder.com'));$state->privacy=$action==='privacy_renew';$state->enabled=$state->privacy;$state->expiry='2027-10-18';$state->cost='8.00';
    $op=$submit($d,$driver,$action);
    if($claim!==''){$a=['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32)];
        if($claim==='deferral'){$a['management_deferrals']=[$clock->now()->format(DATE_ATOM),$clock->now()->format(DATE_ATOM)];}
        $op->update(['state'=>$claim==='deferral'?'queued':'held','safe_code'=>$claim==='deferral'?'management_deferred':'management_unconfirmed','started_at'=>$clock->now(),'attempt'=>$a]);}
    return [$d,$driver,$op->fresh()];};
$visible=static function($op,$d){
    expect(RefundCandidates::query(Operation::query())->whereKey($op->id)->exists(),'Request credit-review SQL includes retired privacy independently of eligibility');
    expect(DomainQueries::creditReview(Domain::query())->whereKey($d->id)->exists(),'Holding credit-review SQL includes retired privacy independently of eligibility');
    expect(RefundCandidates::matches($op->fresh(),$op->fresh()->funding??[]),'Presentation candidate includes uncredited retirement');};
foreach(['privacy_purchase','privacy_renew'] as $action){
    foreach ([false,true] as $deferred) {
        [$d,$driver,$op]=$issue($action); $payment($op); $preparations=0; $observed=null;
        $prepare=static function () use ($op,$deferred,&$preparations,&$observed): void {
            $preparations++;
            $entered=$op->fresh(); $observed=[$entered->state,$entered->attempt['management_claim']??null];
            if($deferred){throw new \Paymenter\Extensions\Servers\Domains\Services\ReadDeferred('Synthetic local deferral');}
            throw new RuntimeException('Synthetic preparation failure');
        };
        $enteredDriver=new class(CatalogueAcceptances::binding(Account::where('server_id',$op->server_id)->sole()),$driver,$prepare) extends ManagementProtocol {
            public function __construct(AccountBinding $b,private ManagementProtocol $real,private Closure $prepare){parent::__construct($b,static fn()=>throw new LogicException('Unused exchange'));}
            public function capabilities():Capabilities{return $this->real->capabilities();}
            public function validate(string $a,array $p,string $n):array{return $this->real->validate($a,$p,$n);}
            public function prepare(ManagementRequest $r,RequestContext $c):array{($this->prepare)();throw new LogicException('Preparation must refuse');}
            public function observe(ManagementRequest $r,RequestContext $c):ManagementResult{throw new LogicException('Unused observation');}
            protected function apply(ManagementRequest $r,RequestContext $c):ManagementResult{throw new LogicException('Unsent claim cannot apply');}
            protected function readCommands():array{return [];}
            protected function validateRead(string $c,array $p):void{throw new LogicException('Unused read');}
            protected function writeCommand(string $a):string{throw new LogicException('Unused write');}
            protected function origin(RequestContext $c):string{throw new LogicException('Unused origin');}
        };
        $entered=$management->run($op->id,$enteredDriver);
        same(1,$preparations,'Real worker reaches preparation once');
        same('running',$observed[0],'Preparation observes real entered worker claim');
        expect(preg_match('/^[a-f0-9]{32}$/D',$observed[1]??'')===1,'Preparation observes valid real claim token');
        expect(preg_match('/^[a-f0-9]{32}$/D',$entered->attempt['management_claim']??'')===1,'Failed preparation retains real claim token');
        same($deferred?'queued':'held',$entered->state,'Real claim records deferral or preparation failure');
        same('absent',$entered->attempt['seal'],'Entered failure never seals');
        same('definitely_unsent',$entered->attempt['send_state'],'Entered failure is definitely unsent');
        same($deferred?1:0,count($entered->attempt['management_deferrals']??[]),'Real deferred claim retains one timestamp');
        expect($entered->started_at!==null,'Real entered worker retains start time');
        $finance=[]; foreach(['invoices','invoice_items','invoice_transactions','credits'] as $table){$finance[$table]=DB::table($table)->orderBy('id')->get()->toJson();}
        $before=$entered->fresh()->getRawOriginal(); $calls=$state->calls;
        try{$management->retire($user,$op->id);throw new RuntimeException('Expected funded retirement refusal');}
        catch(DomainException $error){same('Privacy financial activity requires review.',$error->getMessage(),'Real entered funding reaches the financial retirement guard');}
        if(!$deferred){$management->run($op->id,$driver);}
        same($before,$op->fresh()->getRawOriginal(),'Funded entered claim cannot become unpaid retirement or reopen held work');
        same($calls,$state->calls,'Refused retirement and held replay perform no provider work');
        foreach($finance as $table=>$rows){same($rows,DB::table($table)->orderBy('id')->get()->toJson(),'Entered claim retirement retains '.$table);}
    }

    foreach(['native','cancelled','claim','deferral','staff'] as $mode){
        [$d,$driver,$op]=$issue($action,in_array($mode,['claim','deferral'],true)?$mode:'');
        $item=InvoiceItem::findOrFail($op->invoice_item_id);commerceReject(fn()=>$item->update(['description'=>'Changed pending privacy line']));$item->refresh();$itemBytes=$item->getRawOriginal();$serviceBytes=$d->service->getRawOriginal();
        if($mode==='staff'){$op->update(['policy'=>[...$op->policy,'staff_actions'=>[['action'=>'hold','phase'=>'completed','actor_id'=>$staff->id,'reason'=>'[redacted]','at'=>$clock->now()->format(DATE_ATOM)]]]]);}
        $calls=count($state->calls);$op=$management->retire($user,$op->id);$attempt=$op->attempt;expect(PrivacyRetirement::safe($op,$d),'Exact new retirement is independently verified');
        same('cancelled',Invoice::findOrFail($op->invoice_id)->status,'Retirement cancels untouched native invoice');
        $visible($op,$d);
        if($mode==='staff'){Auth::setUser($staff);same([['time'=>'Oct 18, 2026 12:00 UTC','event'=>'Put on hold','sub'=>'[redacted]','by'=>'Staff']],StaffReviewHistory::rows($op),'Privacy-charge retirement preserves an already-redacted reason without actor lookup');}
        $tx=$payment($op);
        same('paid',Invoice::findOrFail($op->invoice_id)->status,'Real late succeeded native transaction pays previously cancelled invoice');
        if($mode==='cancelled'){DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);}
        $before=$balance();
        if($mode==='staff'){$ticket=$staffCredit($op);commerceReject(fn()=>$staffCredit($op,$ticket));}
        else{same(['amount'=>$op->amount,'currency'=>'USD'],$refunds->creditRejected($op->id),'Retired privacy full native funding becomes account credit');}
        $after=$balance();same($before+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount),$after,'One exact account credit');
        same(null,$refunds->creditRejected($op->id),'Repeat automatic credit is inert');commerceReject(fn()=>$staffCredit($op));$refunds->retryPending();same($after,$balance(),'Staff/automatic retries never credit twice');
        $op=$op->fresh();same('rejected',$op->state,'Retirement stays rejected');same('management_retired',$op->safe_code,'Retirement reason retained');same($attempt,$op->attempt,'Complete claim/deferral/retirement history retained');
        same($itemBytes,$item->fresh()->getRawOriginal(),'Issued invoice line retained byte-for-byte');same($serviceBytes,$d->service->fresh()->getRawOriginal(),'Acquired native service retained');
        same($tx->id,InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole()->id,'Native payment history retained');same('late_retired_payment',$op->funding['refund']['reason'],'Plain retained credit reason');
        same($op->id,$management->run($op->id,$driver)->id,'Retired operation never reopens');same(true,ManagementEvidence::resolved($op,$d),'Retirement remains peer-resolved');same($calls,count($state->calls),'Retirement/payment/credit/retries never call registrar');
        commerceReject(fn()=>$op->update(['attempt'=>array_replace($op->attempt,['management_terminal'=>null])]));
        commerceReject(fn()=>$op->update(['policy'=>array_replace($op->policy,['privacy'=>[]])]));
    }
    // A native write failure rolls back both credit and once marker; the same staff action can retry.
    [$d,$driver,$op]=$issue($action);$op=$management->retire($user,$op->id);$payment($op);$before=$balance();
    DB::unprepared("CREATE TRIGGER privacy_credit_fail BEFORE UPDATE ON credits BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
    try{$refunds->creditRejected($op->id);expect(false,'Expected native credit failure');}catch(\Illuminate\Database\QueryException){}
    DB::unprepared('DROP TRIGGER privacy_credit_fail');same($before,$balance(),'Failed credit transaction rolls back');expect(!array_key_exists('refund',$op->fresh()->funding??[]),'Failed credit has no once marker');$staffCredit($op);
    foreach(['partial','failed','reversed'] as $kind){
        [$d,$driver,$op]=$issue($action);$op=$management->retire($user,$op->id);$visible($op,$d);
        if($kind==='partial'){$payment($op,'1.00');}elseif($kind==='failed'){$payment($op,null,'failed');}else{$payment($op);$payment($op,'-1.00');}
        DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);$before=$balance();$calls=count($state->calls);
        commerceReject(fn()=>$refunds->creditRejected($op->id));commerceReject(fn()=>$staffCredit($op));same($before,$balance(),'Partial/failed/reversed funding cannot credit');same($calls,count($state->calls),'Funding refusal is local');
        expect(!array_key_exists('refund',$op->fresh()->funding??[]),'Funding refusal retains uncredited request');$visible($op,$d);
    }
    foreach(['processing','failed','succeeded'] as $status){
        [$d,$driver,$op]=$issue($action);$payment($op,'1.00',$status);commerceReject(fn()=>$management->retire($user,$op->id));expect(!isset($op->fresh()->attempt['management_terminal']),'Any pre-retirement native transaction blocks retirement');
    }
    // Corrupt encrypted records via a deliberate test-only bypass; the production writer still rejects them.
    $bad=[
        'send-state'=>fn($op)=>['attempt'=>array_replace($op->attempt,['send_state'=>'possibly_sent'])],
        'fingerprint'=>fn($op)=>['request_fingerprint'=>str_repeat('b',64)],
        'lease'=>fn($op)=>['lease_expires_at'=>$clock->now()->modify('+1 minute')],
        'funding-authorization'=>fn($op)=>['funding'=>['refund_authorization'=>null]],
        'funding-adjustments'=>fn($op)=>['funding'=>['financial_adjustments'=>[]]],
        'wrong-actor'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>array_replace($op->attempt['management_terminal'],['actor_id'=>999])])],
        'wrong-reason'=>fn($op)=>['policy'=>array_replace($op->policy,['management'=>array_replace($op->policy['management'],['reason'=>'foreign_reason'])])],
        'issued-price'=>fn($op)=>['policy'=>array_replace($op->policy,['privacy'=>array_replace($op->policy['privacy'],['issued_line'=>array_replace($op->policy['privacy']['issued_line'],['price'=>'0.01'])])])],
        'seal'=>fn($op)=>['attempt'=>array_replace($op->attempt,['seal'=>'sealed'])],
        'ack-null'=>fn($op)=>['attempt'=>[...$op->attempt,'management_ack'=>null]],
        'dispatch-null'=>fn($op)=>['attempt'=>[...$op->attempt,'dispatch_attempted_at'=>null]],
        'execution-null'=>fn($op)=>['attempt'=>[...$op->attempt,'execution'=>null]],
        'unknown-null'=>fn($op)=>['attempt'=>[...$op->attempt,'unknown'=>null]],
        'funding-seal-null'=>fn($op)=>['funding'=>['seal'=>null]],
        'funding-claim-null'=>fn($op)=>['funding'=>['claim'=>null]],
        'provider-order'=>fn($op)=>['provider_order_id'=>'7001'],
        'policy-dispatch-null'=>fn($op)=>['policy'=>[...$op->policy,'dispatch_attempted_at'=>null]],
        'foreign-key'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>array_replace($op->attempt['management_terminal'],['operation_key'=>'foreign'])])],
        'generation'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>array_replace($op->attempt['management_terminal'],['generation'=>99])])],
        'invoice'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>array_replace($op->attempt['management_terminal'],['invoice_id'=>99])])],
        'transactions'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>array_replace($op->attempt['management_terminal'],['transactions'=>[1]])])],
        'audit-time'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>array_replace($op->attempt['management_terminal'],['at'=>'2026-02-30T12:00:00+00:00'])])],
        'claim-null'=>fn($op)=>['attempt'=>[...$op->attempt,'management_claim'=>null]],
        'deferral-no-claim'=>fn($op)=>['attempt'=>[...$op->attempt,'management_deferrals'=>['2026-10-18T12:00:00+00:00']]],
        'legacy-marker'=>fn($op)=>['attempt'=>array_replace($op->attempt,['management_terminal'=>['actor_id'=>(int)$user->id,'at'=>$clock->now()->format(DATE_ATOM)]])],
        'stale-binding'=>fn($op)=>['authority_generation'=>$op->authority_generation+1],
    ];
    foreach($bad as $kind=>$corrupt){
        [$d,$driver,$op]=$issue($action);$op=$management->retire($user,$op->id);$payment($op);
        Operation::withoutEvents(fn()=>$op->update($corrupt($op)));expect(!PrivacyRetirement::safe($op->fresh(),$d),'Verifier itself refuses '.$kind);$before=$balance();$calls=count($state->calls);
        commerceReject(fn()=>$refunds->creditRejected($op->id));same($before,$balance(),'Forged/sent '.$kind.' cannot credit');expect(!isset($op->fresh()->funding['refund']),'Unsafe proof retains no credit marker');same($calls,count($state->calls),'Unsafe retirement never calls registrar');
    }
    foreach(['description'=>'Changed issued line','quantity'=>2,'price'=>'1.00','reference_type'=>'foreign','reference_id'=>99] as $field=>$value){
        [$d,$driver,$op]=$issue($action);$op=$management->retire($user,$op->id);$payment($op);DB::table('invoice_items')->where('id',$op->invoice_item_id)->update([$field=>$value]);$before=$balance();
        commerceReject(fn()=>$refunds->creditRejected($op->id));same($before,$balance(),'Changed exact issued line refuses credit');$visible($op,$d);
    }
    foreach(['2026-10-18T11:59:59+00:00','2026-10-18T12:00:01+00:00'] as $outside){
        [$d,$driver,$op]=$issue($action,'claim');$op=$management->retire($user,$op->id);$payment($op);Operation::withoutEvents(fn()=>$op->update(['started_at'=>$outside]));expect(!PrivacyRetirement::safe($op->fresh(),$d),'Claim start outside issued/retired window refuses');commerceReject(fn()=>$refunds->creditRejected($op->id));
    }
    foreach([['management_claim'=>'bad'],['management_deferrals'=>['2026-02-30T12:00:00+00:00']],['management_deferrals'=>['2026-10-18T12:00:01+00:00']],['management_deferrals'=>[]]] as $corrupt){
        [$d,$driver,$op]=$issue($action,'deferral');$op=$management->retire($user,$op->id);$payment($op);Operation::withoutEvents(fn()=>$op->update(['attempt'=>array_replace($op->attempt,$corrupt)]));commerceReject(fn()=>$refunds->creditRejected($op->id));
    }
}
$nativeSuccessful=true;echo "retired privacy native payment, staff credit, atomic retry and refusal boundaries passed\n";
