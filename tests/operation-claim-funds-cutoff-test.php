<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-claims.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
// The real worker must release its own unsealed claim when the funds cutoff passes during preflight.
$op=$new('funds-seal-cutoff.example.com');
$op->update(['policy'=>[...$op->policy,'funds_wait_at'=>$clock->now()->modify('-3 days +1 second')->format(DATE_ATOM)]]);
$preflight->during=static fn()=>$clock->instant=$clock->now()->modify('+2 seconds');
$result=$assertNoSend($op);$preflight->during=null;
same('registrar_balance_insufficient',$result->safe_code,'Elapsed funds wait keeps a sweepable unsent hold');
same(null,$result->lease_expires_at,'No running lease remains after refusal to seal');
$clock->instant=$clock->now()->modify('+'.(ExecuteOperation::LEASE_SECONDS+1).' seconds');
$engine->execute($op->id);same('held',$op->fresh()->state,'Later worker cannot turn this no-send into stale_claim');
$credit=fn()=>\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)(App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));$before=$credit();
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class,new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock));
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole();
// Two deadlocks roll the whole closure back and queue it with no staff review; the next sweep runs it first.
$runner=is_file(dirname(__DIR__).'/Domains/Services/MoneyWorkSchema.php');if($runner){\Paymenter\Extensions\Servers\Domains\Services\MoneyWorkSchema::install();}
$fails=2;Operation::updating(static function(Operation $row)use(&$fails):void {
    if($fails<1||$row->state!=='rejected'||!$row->isDirty('state')){return;}
    $fails--;same(1,DB::transactionLevel(),'The funds closure runs in one outer transaction');
    $cause=new PDOException('Synthetic deadlock');$cause->errorInfo=['40001',1213,'Synthetic'];
    throw new Illuminate\Database\QueryException('sqlite','synthetic funds closure',[],$cause);
});
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
same([0,'held',0],[$fails,$op->fresh()->state,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','funds_wait_review')->count()],'Contention twice changes nothing and asks no staff review');
same([['kind'=>'funds_wait','subject_id'=>$op->id]],!$runner?[]:DB::table('domains_money_work')->get(['kind','subject_id'])->map(fn($r)=>(array)$r)->all(),'The deferred closure is queued');
Illuminate\Support\Facades\Cache::forever('domains.funds-wait-cursor.'.$op->server_id,$op->id); // Only the queue can reach it.
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
same(['rejected',0],[$op->fresh()->state,DB::table('domains_money_work')->count()],'The next sweep closes the queued claim and finishes the work');
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
same($before+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount),$credit(),'Elapsed claim returns its payment once without staff repair');
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
