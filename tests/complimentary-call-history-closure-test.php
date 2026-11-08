<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real engine, local Enom counter and native closure.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Illuminate\Support\Facades\{Cache,Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,EnomTestSendLimit,EnomTestSendLimitSchema,FundsWaits,HolderPricing,NativeDomainGuard,OperationClaims,OperationTerminalProof,RefundProof};
EnomTestSendLimitSchema::install();(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$staff=commerceUser();$staff->update(['role_id'=>App\Models\Role::create(['name'=>'History closure','permissions'=>['admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Synthetic history closure',$clock);$failures=[];
foreach(['register','transfer']as$action){
    $clock->instant=$now;Cache::flush();$op=$new(placeholderComFixtureName('comp-history-'.$action.'-placeholder.com'),false,true,$action);
    config(['domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
    EnomTestSendLimit::admit(Environment::Test,'account-enom','Check');
    config(['domains.enom_test_sends_per_day'=>DB::table(EnomTestSendLimitSchema::SENDS)->count()]);
    $preflight->during=static fn()=>EnomTestSendLimit::admit(Environment::Test,'account-enom','Check');
    $held=$engine->execute($op->id);$preflight->during=null;
    same('held',$held->state,'Real Enom counter refusal holds the claimed '.$action);expect(isset($held->attempt['test_call_refusals'][0]),'Engine retains real call refusal history');
    $held->update(['safe_code'=>'registrar_balance_insufficient','policy'=>[...$held->policy,'funds_wait_at'=>$now->format(DATE_ATOM)]]);
    $history=$held->attempt['test_call_refusals'];$virgin=$held->getRawOriginal();$account=Account::where('server_id',$op->server_id)->sole();$money=$nativeMoney();
    foreach(['malformed','foreign','sent']as$case){
        $attempt=$held->attempt;
        if($case==='malformed'){unset($attempt['test_call_refusals'][0]['phase']);}
        if($case==='foreign'){$attempt['terminal']=['operation_key'=>'foreign','generation'=>999];}
        if($case==='sent'){$attempt['seal']='sealed';$attempt['send_state']='possibly_sent';}
        DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>Crypt::encryptString(json_encode($attempt))]);
        FundsWaits::sweep($account,$now->modify('+3 days'));
        same('held',$op->fresh()->state,'Damaged/sent history never closes: '.$action.':'.$case);same($money,$nativeMoney(),'Damaged history moves no money');
        DB::table('domains_operations')->where('id',$op->id)->update($virgin);
    }
    FundsWaits::sweep($account,$now->modify('+3 days'));$closed=$op->fresh();
    if([$closed->state,App\Models\Service::findOrFail($op->service_id)->status]!==['rejected','cancelled']){$failures[]=$action.': completed call history must close once';continue;}
    same($history,$closed->attempt['test_call_refusals'],'Exact call history survives completion');expect(OperationTerminalProof::safe($closed,$closed->domain),'Completed history proves terminal closure');
    RefundProof::requireUnsent($closed,$closed->domain);expect(!OperationClaims::testCallRefusalUnsent($closed)&&!OperationClaims::testCallRefusalRetryable($closed),'Terminal proof never widens live send or retry authority');
    foreach(['missing','malformed','foreign','sent','future']as$case){
        $bad=clone $closed;$facts=$bad->attempt;
        if($case==='missing'){$facts['test_call_refusals']=[];}
        if($case==='malformed'){unset($facts['test_call_refusals'][0]['phase']);}
        if($case==='foreign'){$facts['terminal']['generation']=999;}
        if($case==='sent'){$facts['send_state']='possibly_sent';}
        if($case==='future'){$facts['test_call_refusals'][0]['refused_at']=$now->modify('+4 days')->format(DATE_ATOM);}
        $bad->attempt=$facts;if(OperationTerminalProof::safe($bad,$closed->domain)){$failures[]=$action.': '.$case.' completed history must not become terminal proof';}
        commerceReject(fn()=>RefundProof::requireUnsent($bad,$closed->domain));
    }
    $raw=$closed->getRawOriginal();FundsWaits::sweep($account,$now->modify('+4 days'));same($raw,$op->fresh()->getRawOriginal(),'Repeated sweep preserves the terminal history');same($money,$nativeMoney(),'Closure preserves Paid zero receipt and financial history');
}
foreach($failures as$failure){echo 'FAIL: '.$failure."\n";}expect($failures===[],implode('; ',$failures));same(0,$writer->calls,'No registrar purchase was sent');
echo "Native completed call history closes without live send authority\n";$nativeSuccessful=true;
