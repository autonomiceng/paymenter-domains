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
$ownerRenewalDomains=1;
require __DIR__.'/Support/owner-renewal.php';
require __DIR__.'/Support/owner-renewal-issued.php';
// Retained cycles remain visible under every gate that prevents creating a new invoice.
$cycleStates=['pending'=>['state'=>'awaiting_payment','result'=>['invoice'=>route('invoices.show',$invoice)],'heading'=>'Pay invoice'],
    'paid'=>['state'=>'pending','result'=>['status'=>'paid'],'heading'=>'Updates when the renewal completes'],
    'cancelled'=>['state'=>'awaiting_payment','result'=>['status'=>'cancelled'],'heading'=>'Invoice cancelled'],
    'held'=>['state'=>'held','result'=>['status'=>'review'],'heading'=>'There’s nothing you need to do.']];
foreach(['manual','stop','service','domain','cutoff','calendar'] as $gate){
    $rawDomain=$d->fresh()->getRawOriginal();$rawService=$d->service->fresh()->getRawOriginal();$savedTime=$clock->instant;
    if($gate==='manual'){$setting('domains_manual_renewals_enabled',false);}
    if($gate==='stop'){$d->update(['renewal_policy'=>[...$d->renewal_policy,'renewals_stopped'=>true]]);}
    if($gate==='service'){$d->service->fresh()->update(['status'=>'suspended']);}
    if($gate==='domain'){$d->update(['status'=>'needs_review']);}
    if($gate==='cutoff'){$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');}
    if($gate==='calendar'){$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>'invalid','type'=>'string']);}
    foreach($cycleStates as $state=>$expected){
        DB::table('invoices')->where('id',$invoice->id)->update(['status'=>$state==='held'?'pending':$state]);
        DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$expected['state']]);$unchanged=$ledger();
        $expired=$gate==='cutoff'&&in_array($state,['pending','held'],true);
        same($expired?['status'=>'cancelled']:$expected['result'],$owners->present($d->fresh())->renewNow,'Retained '.$state.' survives '.$gate);
        $html=$page($d);same(1,substr_count($html,'>'.($expired?'Invoice cancelled':$expected['heading']).'<'),'One retained heading: '.$state.' '.$gate);
        expect(!str_contains($html,'Continue to payment'),'Retained cycle has no new request: '.$state.' '.$gate);
        same($unchanged,$ledger(),'Retained presentation is read-only: '.$state.' '.$gate);
    }
    DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);DB::table('services')->where('id',$d->service_id)->update($rawService);
    $clock->instant=$savedTime;$setting('domains_manual_renewals_enabled',true);$server->settings()->where('key','domains_due_days')->delete();
}
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment']);


foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
$nativeSuccessful=true;echo "owner-renewal-retained-test.php: passed.\n";
