<?php
// Native late renewal payments; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['renew-late-partial','renew-late-twice','renew-late-frozen'];
require __DIR__.'/Support/renewal-late-cycle.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
[$late,$lateDomain,,$lateInvoice]=$closed('renew-late-partial-placeholder.com');
$pay::addPayment($lateInvoice->id,null,'1.00');$refunds->retryPending();
same(100,$cash(),'A $1 payment on a closed invoice becomes $1 of credit');
same([$words('$1.00')],$notes($lateDomain),'The closed request shows the amount and where it goes');
same($words('$1.00'),\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::requestStatus($late->fresh())['reason'],'Staff read the same words');
same(0,$refunds->retryPending(),'The next sweep changes nothing');same(100,$cash(),'A payment is credited once');
same(1,Operation::where('domain_id',$lateDomain->id)->where('action','renew')->count(),'Late money starts no renewal');
[$twice,$twiceDomain,,$twiceInvoice]=$closed('renew-late-twice-placeholder.com');$amount=PricingRule::minor($twice->amount);
foreach([1,2] as $n){$pay::addPayment($twiceInvoice->id,null,$twice->amount);$refunds->retryPending();same($n*$amount,$cash(),'Full payment '.$n.' on a closed invoice becomes credit');}
same([$words(\Paymenter\Extensions\Servers\Domains\Services\RenewalReminders::money(PricingRule::decimal(2*$amount)))],$notes($twiceDomain),'The note adds up what was credited');
[$frozen,,,$frozenInvoice]=$closed('renew-late-frozen-placeholder.com');$first=$pay::addPayment($frozenInvoice->id,null,$frozen->amount);
$refund=['amount'=>$frozen->amount,'payments'=>[['id'=>(int)$first->id,'amount_minor'=>PricingRule::minor($frozen->amount)]],'to'=>'account_credit'];$frozen=$frozen->fresh();$frozen->update(['funding'=>[...($frozen->funding??[]),'refund'=>$refund]]);
$pay::addPayment($frozenInvoice->id,null,'2.00');$refunds->retryPending();
same([200,$refund],[$cash(),$frozen->fresh()->funding['refund']],'A later payment is credited and the frozen credit stays as it was');
same([],array_keys($registrations),'All independent acquisition scenarios ran');
echo "Closed renewal invoice incremental credits passed\n"; $nativeSuccessful=true;
