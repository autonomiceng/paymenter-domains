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
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
same('rejected',$op->fresh()->state,'Sweep closes the definitely-unsent elapsed claim');
\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
same($before+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount),$credit(),'Elapsed claim returns its payment once without staff repair');
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
