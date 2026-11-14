<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native billing with synthetic registrar evidence.
declare(strict_types=1);
require __DIR__.'/Support/owner-auto-renew.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;
[$op]=$prepare(placeholderComFixtureName('owner-calendar-placeholder.com'));
$d=$op->domain()->firstOrFail();
$pending=$owners->present($d);
expect(property_exists($pending,'renewalSchedule'),'Owner DTO exposes the registrar renewal calendar');
same([],$pending->renewalSchedule,'Pending registration has no confirmed renewal schedule');
same('succeeded',$reconcile->run($op->id)->state,'Registration confirms the registrar expiry');
$d=$d->fresh();$server=$d->service->product->server;
foreach(['domains_issue_days'=>30,'domains_due_days'=>7] as $key=>$days){$server->settings()->updateOrCreate(['key'=>$key],['value'=>$days,'type'=>'integer']);}
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
$expected=['expiry'=>'2027-10-18','issue'=>'2027-09-18','due'=>'2027-10-11'];
$dto=$owners->present($d->fresh());
same($expected,$dto->renewalSchedule,'Owner DTO uses the saved registrar calendar');
same(null,$dto->renewalDue,'A projection never becomes an issued invoice due date');
same($expected,$owners->present($d->fresh(),false)->renewalSchedule,'Service projection retains the calendar without forms');
$summary=(new DomainSummaries())->one($user,'managed',$d->id,$clock->now()->getTimestamp());
same([$expected['issue'],$expected['due']],[$summary->renewalIssueAt,$summary->renewalDueAt],'Summary shares billing calendar dates');
$d->update(['provider_expires_at'=>null]);
same([],$owners->present($d->fresh())->renewalSchedule,'No registrar expiry means no projected dates');
$d->update(['provider_expires_at'=>$expected['expiry']]);
$policy=$d->renewal_policy;$d->update(['renewal_policy'=>[...$policy,'owner_auto_renew'=>false]]);
same([],$owners->present($d->fresh())->renewalSchedule,'Renewal off has no scheduled invoice');
$d->update(['renewal_policy'=>$policy]);
$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>'invalid','type'=>'string']);
same([],$owners->present($d->fresh())->renewalSchedule,'Invalid saved calendar cannot invent dates');
$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>7,'type'=>'integer']);
$clock->instant=new DateTimeImmutable('2027-09-18T23:59:59Z');
same($expected,$owners->present($d->fresh())->renewalSchedule,'Issue date itself retains the planned calendar');
$clock->instant=new DateTimeImmutable('2027-09-19T00:00:00Z');
same([],$owners->present($d->fresh())->renewalSchedule,'Overdue issue is not presented as a future invoice');
$clock->instant=new DateTimeImmutable('2027-09-17T23:30:00-01:00');
same($expected,$owners->present($d->fresh())->renewalSchedule,'Calendar cutoff uses the UTC billing date');
$clock->instant=new DateTimeImmutable('2027-09-18T23:30:00-01:00');
same([],$owners->present($d->fresh())->renewalSchedule,'UTC midnight ends the projected issue date');
// An early retained invoice owns its dates even before the projected issue date.
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
$invoice=$billing->invoice($d->id,$user,1);
same($expected['due'],$invoice->due_at->format('Y-m-d'),'Writer shares the displayed due date');
$renewal=Operation::where('invoice_id',$invoice->id)->sole();
foreach(['pending','paid','cancelled'] as $status){
    DB::table('invoices')->where('id',$invoice->id)->update(['status'=>$status]);
    $issued=$owners->present($d->fresh());
    same([],$issued->renewalSchedule,'Retained '.$status.' invoice replaces the projected schedule');
    if($status==='pending'){same($invoice->due_at->format('Y-m-d'),$issued->renewalDue,'Exact retained pending invoice owns its due date');}
}
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>'held']);
same([],$owners->present($d->fresh())->renewalSchedule,'Invoice under review never falls back to projected dates');
// Staff must not forecast another payment for an early invoice already paid in this cycle.
Illuminate\Support\Facades\Auth::setUser(staffUser([Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]));
$staffRenewal=static fn()=>Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh())['Renewal']['sub'];
$issue=Paymenter\Extensions\Servers\Domains\Presentation\Fact::day($expected['issue'])->text;
expect(!str_contains($staffRenewal(),$issue),'An issued pending invoice replaces the staff payment forecast');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
foreach(['queued','succeeded'] as $state){
    $renewal->update(['state'=>$state]);
    expect(!str_contains($staffRenewal(),$issue),'A paid '.$state.' renewal cannot forecast another payment for this cycle');
}
// A cancelled unpaid cycle may be replaced, and a paid older cycle does not settle this one.
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);
$renewal->update(['state'=>'rejected','safe_code'=>'renewal_cancelled_unpaid']);
expect(str_contains($staffRenewal(),$issue),'A cancelled unpaid renewal retains the future payment forecast');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
DB::table('domains_operations')->where('id',$renewal->id)->update(['base_expires_at'=>$d->provider_expires_at->subYear()]);
expect(str_contains($staffRenewal(),$issue),'A paid older cycle cannot hide this cycle payment forecast');
// Fully returned funding with replacement authority no longer pays for the current cycle.
DB::table('domains_operations')->where('id',$renewal->id)->update(['base_expires_at'=>$d->provider_expires_at]);
$renewal->refresh()->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,$renewal->amount);
$refundStaff=staffUser(['admin.domains.manage','admin.invoices.update']);
(new Paymenter\Extensions\Servers\Domains\Services\Refunds($clock))->refundHeld($refundStaff,$renewal->id,'service_cancelled');
Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::recoverDomain($d->fresh(),$clock);
same('paid',$invoice->fresh()->status,'A refunded invoice retains its native paid status');
expect(Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::reviewed($renewal->fresh(),$d->fresh()),'Returned funding releases this cycle for replacement');
expect(str_contains($staffRenewal(),$issue),'A refunded renewal authorized for replacement retains the future payment forecast');
$nativeSuccessful=true;echo "Owner renewal schedule passed.\n";
