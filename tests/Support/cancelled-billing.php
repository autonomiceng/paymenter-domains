<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Scheduled cancellation is a quiet native billing decision.
declare(strict_types=1);
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$billingTime=$clock->instant;$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$beforeCancellation=serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),Operation::all()->map->getRawOriginal()->all(),$d->fresh()->getRawOriginal(),$s->fresh()->getRawOriginal()]);
$cancel=ServiceCancellation::create(['service_id'=>$s->id,'type'=>'end_of_period','reason'=>'Synthetic owner request']);
same(null,$billing->invoice($d->id),'Cancelled native service is skipped quietly by scheduled billing');
same($beforeCancellation,serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),Operation::all()->map->getRawOriginal()->all(),$d->fresh()->getRawOriginal(),$s->fresh()->getRawOriginal()]),'Cancellation skip creates no debt or ledger/native side effects');
$setting('domains_manual_renewals_enabled',true);
try{$billing->invoice($d->id,$user);throw new RuntimeException('Cancelled manual billing must refuse');}
catch(DomainException $e){same('Renewal billing lifecycle requires review.',$e->getMessage(),'Manual cancellation refuses at lifecycle authority with its gate enabled');}
$setting('domains_manual_renewals_enabled',false);
DB::table('services')->where('id',$s->id)->update(['quantity'=>2]);
commerceReject(fn()=>$billing->invoice($d->id)); // Cancellation cannot bypass native structural authority.
DB::table('services')->where('id',$s->id)->update(['quantity'=>1]);
DB::table('service_cancellations')->where('id',$cancel->id)->delete();
foreach(['suspended','cancelled']as$status){
    DB::table('services')->where('id',$s->id)->update(['status'=>$status]);
    commerceReject(fn()=>$billing->invoice($d->id));
}
DB::table('services')->where('id',$s->id)->update(['status'=>'active']);
// An unrelated native cancellation cannot hide this domain's broken processing authority.
[$otherSource]=$prepare('unrelated-cancellation.com');
same('succeeded',$reconcile->run($otherSource->id)->state,'Uncancelled manual control has a real completed anchor');
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$setting('domains_manual_renewals_enabled',true);
expect($billing->invoice($otherSource->domain_id,$user) instanceof App\Models\Invoice,'Otherwise eligible uncancelled manual billing succeeds');
$setting('domains_manual_renewals_enabled',false);
$unrelated=ServiceCancellation::create(['service_id'=>$otherSource->service_id,'type'=>'end_of_period','reason'=>'Synthetic unrelated request']);
$bindings->setProcessing($server->id,false);commerceReject(fn()=>$billing->invoice($d->id));$bindings->setProcessing($server->id,true);
DB::table('service_cancellations')->where('id',$unrelated->id)->delete();
$clock->instant=$billingTime;
