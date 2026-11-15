<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{InvoiceTransaction,Server};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities,DateEvidence,DispatchReadiness,Disposition,DomainSnapshot,DriverResult,Environment,Evidence,ExpiryPrecision,HistoryAcceptance,Money,OrderEvidence,RegistrationEvidence,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState,TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,HistoryAcceptances,ObligationAlerts,OperationClaims,OperationReconciliation,PayCheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

$failures=[];
$check=static function(Closure $assert)use(&$failures):void {try{$assert();}catch(RuntimeException $e){$failures[]=$e->getMessage();}};
$open=static fn($op)=>DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->count();
$raise=static fn($op)=>ObligationAlerts::record($op->domain,'acquisition_retirement_review',$clock->now()->getTimestamp());
$pay=static function($op)use($transfers,$user):void {
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
    if($op->action==='transfer'){
        $request=Illuminate\Http\Request::create('/internal-transfer-input','POST',['transfer_code'=>'SyntheticOnly']);
        $request->setUserResolver(static fn()=>$user);$transfers->submitCode($request,$op->id);
    }
};
$race=null;
DB::listen(static function($query)use(&$race):void {
    if($race&&str_starts_with($query->sql,'select "invoice_id" from "domains_operations"')&&str_contains($query->sql,'"created_at" <')){
        $run=$race;$race=null;same(0,DB::transactionLevel(),'Payment wins before retirement takes locks');$run();
    }
});
foreach(['register','transfer'] as $action){
    $clock->instant=$now;
    $op=$new(placeholderComFixtureName('retirement-race-'.$action.'-placeholder.com'),paid:false,action:$action);
    $account=Account::where('server_id',$op->server_id)->sole();
    DB::table('domains_operations')->where('id',$op->id)->update(['created_at'=>$clock->now()->modify('-2 hours')]);
    $race=static fn()=>$pay($op);$retirement->expireUnpaid($account);
    expect($race===null,'Expiry preselection exercised the payment race');
    $check(fn()=>same(0,$open($op),$action.' paid race raises no unpaid review'));
    $raise($op);$money=$nativeMoney();$retirement->inspect($op->domain_id);
    $check(fn()=>same(0,$open($op),$action.' payment clears an existing review before send'));
    same($money,$nativeMoney(),'Review cleanup preserves native money and jobs');
    commerceReject(fn()=>$retirement->retire($op->domain_id,$staff,$ticket($op),'Paid retirement still refuses'));

    // Exercise original claim, seal and finish; only synthetic ports run.
    $claim=$claims->claim($op->id);expect($claim!==null,'Paid '.$action.' claims: '.$op->fresh()->safe_code);[$claimed,$domain,$binding]=$claim;
    $context=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+300 seconds'),new RequestBudget(10),$clock);
    $r=$preflight->inspect($claimed,$domain,$context);
    $ready=new DispatchReadiness($r->binding,$r->query,$r->capabilities,$r->cost,$r->eligible,$r->premium,$r->observedAt,$r->validUntil,$r->payloadDigest,
        $action==='transfer'?['base_expiry'=>'2027-10-18','privacy'=>false]:null);
    $sealed=$claims->seal($claimed,$ready,$clock->now());expect($sealed!==null,'Funded acquisition seals');
    $reply=new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
        $action==='register'?new Evidence(registration:new RegistrationEvidence($r->query,'9001',null,null,[])):new Evidence(transferReference:'7001'));
    $claims->finish($sealed,$reply);$op->refresh();
    $retirement->closeUnpaid((int)$op->invoice_id,PayCheck::notice('expired'));
    $check(fn()=>same(0,$open($op),$action.' paid acquisition after send raises no unpaid review'));
    $raise($op);$money=$nativeMoney();$retirement->expireUnpaid($account);$retirement->expireUnpaid($account);
    $check(fn()=>same(0,$open($op),$action.' repeated expiry sweeps clear sent paid review'));
    same($money,$nativeMoney(),'Expiry rechecks preserve money and jobs');

    // Complete through the real reconciliation writer with normalized synthetic observations.
    $expiry=new DateEvidence($action==='transfer'?'2028-10-18':'2027-10-18',ExpiryPrecision::Date,'synthetic');
    $facts=new Evidence(domain:new DomainSnapshot($binding,$domain->domain,true,$expiry,false,providerReference:'456'),
        candidates:[new OrderEvidence($binding,$r->query,new Money('99.00','USD'),'9001',new DateEvidence($now->format(DATE_ATOM),ExpiryPrecision::Instant,'synthetic','UTC'),$expiry)],
        historyComplete:true,transfer:$action==='transfer'?TransferStatus::Completed:TransferStatus::Unknown,transferReference:$action==='transfer'?'7001':null);
    $reader=new FakeRegistrar($binding,new Capabilities($binding,[]),new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,$facts));
    $registry=new Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
    $registry->register('enom',['token'],new class implements AccountVerifier {public function verify(Environment $e,array $c):string{return $c['token'];}},static fn()=>$reader);
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,$action==='transfer'?'enom_transfer_v1':'enom_registration_v1'));
    $clock->instant=$now->modify('+6 minutes');$raise($op);
    $completed=(new OperationReconciliation($claims,new AccountBindings($registry),$clock))->run($op->id);
    same('succeeded',$completed->state,'Synthetic original acquisition completes');
    $money=$nativeMoney();$retirement->inspect($op->domain_id);$retirement->expireUnpaid($account);
    $check(fn()=>same(0,$open($op),$action.' completed acquisition clears old review'));
    same($money,$nativeMoney(),'Completed review cleanup preserves money and jobs');
    same($domain->domain,$completed->domain->reservation_name,'Paid acquisition keeps its name');
    $completed->domain->operations()->create(['service_id'=>$completed->service_id,'server_id'=>$completed->server_id,'registrar_key'=>$completed->registrar_key,
        'account_fingerprint'=>$completed->account_fingerprint,'authority_generation'=>$completed->authority_generation,'operation_key'=>'synthetic-correction:'.$completed->id,
        'action'=>'nameservers','term'=>1,'state'=>'queued','funding_basis'=>'non_billable_management','amount'=>'0.00','currency'=>'USD']);
    $raise($op);$retirement->inspect($op->domain_id);
    $check(fn()=>same(0,$open($op),$action.' completed acquisition remains valid with follow-up work'));

    foreach(['identity','mixed','processing','partial','send','money'] as $fault){
        $clock->instant=$now;
        $bad=$new(placeholderComFixtureName('retirement-'.$action.'-'.$fault.'-placeholder.com'),action:$action);
        if($fault==='identity'){DB::table('invoice_items')->where('id',$bad->invoice_item_id)->update(['quantity'=>2]);}
        if($fault==='mixed'){App\Models\Invoice::find($bad->invoice_id)->items()->create(['reference_type'=>'synthetic','price'=>'1.00','quantity'=>1,'description'=>'Synthetic additional line']);}
        if($fault==='processing'){InvoiceTransaction::create(['invoice_id'=>$bad->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);}
        if($fault==='partial'){DB::table('invoice_transactions')->where('invoice_id',$bad->invoice_id)->update(['amount'=>'1.00']);}
        if($fault==='send'){$bad->update(['attempt'=>['seal'=>'sealed','send_state'=>'definitely_unsent']]);}
        if($fault==='money'){$bad->update(['funding'=>['unconfirmed_payment'=>true]]);}
        $money=$nativeMoney();$retirement->closeUnpaid((int)$bad->invoice_id,PayCheck::notice('expired'));
        $retirement->inspect($bad->domain_id);$retirement->expireUnpaid($account);$retirement->expireUnpaid($account);
        same(1,$open($bad),$action.' '.$fault.' retains a real review after repeated sweeps');
        same($bad->domain->domain,$bad->domain->fresh()->reservation_name,'Conflict retains reservation');
        same($money,$nativeMoney(),'Conflict changes no money or jobs');
    }
}
expect($failures===[],implode("\n",$failures));
echo "Unpaid retirement review payment races, completion and conflicts passed.\n";$nativeSuccessful=true;
