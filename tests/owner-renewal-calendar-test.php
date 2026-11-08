<?php
// Native owner renewal calendar; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
// Native checkout, locked invoice creation and owner confirmation share the existing financial fixture.
$registrationNames=['owner-renew-placeholder.com','owner-renew-late-placeholder.com'];
require __DIR__.'/Support/owner-renewal-fixture.php';
require __DIR__.'/Support/owner-renewal-terms.php';
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole());
$publishOwnerCalendarTerms($binding);
$setting('domains_manual_renewals_enabled',true);$setting('domains_billing_enabled',true);
// Late requests keep horizon-bounded terms and share the preview's expiry limit with the writer.
foreach ([true,false] as $autoRenew) {
    $lastDay=$autoRenew?$d:$late;
    $lastDay->update(['renewal_policy'=>[...($lastDay->renewal_policy??[]),'owner_auto_renew'=>$autoRenew]]);
    $clock->instant=new DateTimeImmutable('2027-10-17T23:59:59Z');
    same(['terms'=>[1,2],'order_by'=>'2027-10-17'],$billing->requestOptions($lastDay),'Last-day preview: '.($autoRenew?'On':'Off'));
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
    storefrontViews();app('view')->getFinder()->flush();
    $html=$page($lastDay);expect(!str_contains($html,route('invoices.show',$lastInvoice))&&!str_contains($html,'Pay invoice'),'Expiry page has no invoice link');
    expect(str_contains($html,'Invoice cancelled'),'Expiry page shows retained cancelled status');
    same('pending',$lastInvoice->fresh()->status,'Owner page leaves pending debt unchanged');
    $refused($lastDay,['years'=>'2'],'This domain is too close to its expiry date to renew online. Contact support to renew it.');
    same($before,$ledger(),'Expired duplicate and page are read-only');
}

$nativeSuccessful=true;echo "Owner exact-term renewal calendar passed.\n";
