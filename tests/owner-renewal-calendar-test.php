<?php
// Native owner renewal matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, OwnedDomains, RenewalCycles, RuntimeIneligible, RuntimeReason};
$ownerRenewalDomains=0;
require __DIR__.'/Support/owner-renewal.php';
$setting('domains_manual_renewals_enabled',true);$setting('domains_billing_enabled',true);
// Late requests keep horizon-bounded terms and share the preview's expiry limit with the writer.
foreach ([true,false] as $autoRenew) {
    [$lateRegistration]=$prepare(placeholderComFixtureName('owner-last-day-'.($autoRenew?'on':'off').'-placeholder.com'));
    same('succeeded',$reconcile->run($lateRegistration->id)->state,'Late owner registration confirmed');
    $lastDay=$lateRegistration->domain()->firstOrFail();
    $lastDay->update(['renewal_policy'=>[...($lastDay->renewal_policy??[]),'owner_auto_renew'=>$autoRenew]]);
    $clock->instant=new DateTimeImmutable('2027-10-17T23:59:59Z');
    same(['terms'=>range(1,9),'order_by'=>'2027-10-17'],$billing->requestOptions($lastDay),'Last-day preview: '.($autoRenew?'On':'Off'));
    $response=$controller->renew($req($lastDay,['years'=>'2']),$lastDay->id);
    $lastOp=Operation::where('domain_id',$lastDay->id)->where('action','renew')->sole();$lastInvoice=Invoice::findOrFail($lastOp->invoice_id);
    same(route('invoices.show',$lastInvoice),$response->getTargetUrl(),'Last-day POST accepts two-year request');
    same('2027-10-17',$lastInvoice->due_at->format('Y-m-d'),'Late invoice is due today');
    same($lastInvoice->id,$billing->invoice($lastDay->id,$user,2)->id,'Late duplicate reuses invoice');
    if($autoRenew){same($lastInvoice->id,$billing->invoice($lastDay->id)->id,'Scheduled billing reuses late manual cycle');}
    else{same(null,$billing->invoice($lastDay->id),'Owner Off still skips scheduled billing');}
    same(1,Operation::where('domain_id',$lastDay->id)->where('action','renew')->count(),'One late cycle invoice');
    $clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');$before=$ledger();
    same(['status'=>'cancelled'],$owners->present($lastDay->fresh())->renewNow,'Expiry hides retained payment link before cancellation');
    $html=$page($lastDay);expect(!str_contains($html,route('invoices.show',$lastInvoice))&&!str_contains($html,'Pay invoice'),'Expiry page has no invoice link');
    expect(str_contains($html,'Invoice cancelled'),'Expiry page shows retained cancelled status');
    same('pending',$lastInvoice->fresh()->status,'Owner page leaves pending debt unchanged');
    $refused($lastDay,['years'=>'2'],'This domain is too close to its expiry date to renew online. Contact support to renew it.');
    same($before,$ledger(),'Expired duplicate and page are read-only');
}

// Owner copy for every billing refusal hides reasons and internal messages.
foreach([[new RuntimeIneligible(RuntimeReason::SpendingPaused),OwnerRenewalController::UNAVAILABLE],[new RuntimeIneligible(RuntimeReason::LifecycleNotReady),OwnerRenewalController::UNAVAILABLE],
    [new RuntimeIneligible(RuntimeReason::MissingAnchor),OwnerRenewalController::UNAVAILABLE],[new DomainException('Domain renewal is unavailable.'),OwnerRenewalController::UNAVAILABLE],
    [new DomainException('Renewal is off for this adopted registration.'),'Auto-renew is off for this domain. Contact support if you want to renew it.'],
    [new RuntimeException('Renewal term exceeds the registry horizon.'),OwnerRenewalController::UNAVAILABLE]] as [$error,$copy]){same($copy,OwnerRenewalController::refusal($error),'Owner copy for '.$error->getMessage());}
foreach([[null,'This domain already has a renewal invoice for a different term. Pay that invoice, or contact support to change it.'],['cancelled',OwnerRenewalController::CANCELLED],
    ['review','This renewal is under review. Contact support if you need an update on this renewal.']] as [$state,$copy]){same($copy,OwnerRenewalController::refusal(new DomainException('Existing renewal obligation requires review.'),$state),'Issued cycle copy for '.($state??'term'));}
foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
// Money that lands on a closed early invoice: the customer page says where it went.
$setting('domains_manual_renewals_enabled',true);$retire=new RenewalCycles($bindings,$clock);$refunds=new Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
$clock->instant=new DateTimeImmutable('2026-10-18T12:00:00Z');[$source]=$prepare(placeholderComFixtureName('owner-late-credited-placeholder.com'));same('succeeded',$reconcile->run($source->id)->state,'Domain ready');$domain=$source->domain()->firstOrFail();
$clock->instant=new DateTimeImmutable('2027-08-01T12:00:00Z');$closed=$billing->invoice($domain->id,$user,1);$op=Operation::where('invoice_id',$closed->id)->sole();DB::table('domains_operations')->where('id',$op->id)->update(['created_at'=>'2027-08-01 12:00:00']);
$clock->instant=new DateTimeImmutable('2027-08-09T12:00:00Z');$retire->closeEarlyUnpaid($domain->id);same('cancelled',$closed->fresh()->status,'The week closes the invoice');
App\Helpers\ExtensionHelper::addPayment($closed->id,null,'1.00');$refunds->creditRejected($op->id);
expect(str_contains($page($domain),'We added $1.00 to account credit. You can apply it on an invoice.'),'Customer page says where the money went');

foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
$nativeSuccessful=true;echo "owner-renewal-calendar-test.php: passed.\n";
