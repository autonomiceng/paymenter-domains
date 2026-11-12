<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$renewalGroup='confirmation';
$renewalCases=['success'];
require __DIR__.'/enom-renewal-holders.php';
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
require __DIR__.'/enom-renewal-execution-fixture.php';
$op=$ops['success'];$start('success');
$stopDomain=$op->domain()->firstOrFail();$stopReview=Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::class;
$stopKey=$stopReview::ticket($staff->id,$stopDomain->id,'stop',$stopReview::stamp(Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($stopDomain,advisory:true)),$clock);
(new $stopReview($clock))->run($staff,$stopDomain->id,'stop',$stopKey,'Stop future cycles and retain the paid issued renewal');
$before=$money();same($op->invoice_id,$billing->invoice($stopDomain->id,$user)->id,'Future stop retains issued paid debt');
config(['domains.enom_writes.renewal'=>false]);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same([],$GLOBALS['enomWire']['forms'],'Off sends no requests');
same(['queued','renewal_acceptance_required',[]],[$op->fresh()->state,$op->fresh()->safe_code,(array)$op->fresh()->attempt],'Read-only recovery keeps disabled renewal queued and unsealed: '.json_encode([$op->fresh()->state,$op->fresh()->safe_code,(array)$op->fresh()->attempt]));same($before,$money(),'Disabled renewal preserves paid money');config(['domains.enom_writes.renewal'=>true]);
$write('test_renewal',['accept'=>false]);Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same([],$GLOBALS['enomWire']['forms'],'Missing action acceptance refuses before preflight');
same([false,false],array_values(array_intersect_key(Paymenter\Extensions\Servers\Domains\Services\RenewalBillingSettings::read($server->id),['scheduled'=>1,'manual'=>1])),'Renewals off stops new invoices too');same('queued',$op->fresh()->state,'A paid renewal waits, it is not lost');
$write('test_renewal',['accept'=>true,'verified'=>true]);$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$GLOBALS['enomWire']['during']=static function(array $p)use($op,$clock):void {if($p['Command']==='Extend'){(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);}};
Cache::flush();$used=DB::table(EnomTestSendLimitSchema::SENDS)->count();
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);$commands=array_column($GLOBALS['enomWire']['forms'],'Command');
same(['GetDomainInfo','GetExtendInfo','Check','GetDomainInfo','GetRenew','GetBalance','GetDomainInfo','GetExtendInfo','Check','GetDomainInfo','GetRenew','GetBalance','Extend'],$commands,'Two bounded six-read assessments and one Extend: '.json_encode([$op->fresh()->state,$op->fresh()->safe_code,$commands]));
same('pending',$op->fresh()->state,'Paid renewal acknowledged once');same($used+13,DB::table(EnomTestSendLimitSchema::SENDS)->count(),'Extend counted once among the 13 sends');same($before,$money(),'Renewal dispatch changes no native financial bytes');
$extend=end($GLOBALS['enomWire']['forms']);same(['1','no'],[$extend['NumYears'],$extend['UseCreditCard']],'One annual renewal without card');
$s->after=true;$s->bill=(new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
foreach([['2027-10-21','10.00'],['2028-10-20','10.00'],['2028-10-21','invalid']] as [$expiry,$charge]){
    $s->confirmed=$expiry;$s->charge=$charge;Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
    same('pending',$op->fresh()->state,'Missing full-year expiry or valid charge cannot complete');same($before,$money(),'Incomplete confirmation keeps paid money unchanged');
}
$s->confirmed='2028-10-21';$s->charge='10.00';
config(['domains.enom_test_sends_per_day'=>$recentSends()+4]);$count=count($GLOBALS['enomWire']['forms']);$attempt=$op->fresh()->attempt;$polls=$op->fresh()->policy['renewal_polls']??0;
Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp'],array_column(array_slice($GLOBALS['enomWire']['forms'],$count),'Command'),'Mid-confirmation cap retains fresh four-read prefix without reordering');
same(['pending',$attempt,$polls],[$op->fresh()->state,$op->fresh()->attempt,$op->fresh()->policy['renewal_polls']??0],'Partial capped confirmation keeps original attempt and covered-poll count');same($before,$money(),'Partial confirmation never refunds or resends');
config(['domains.enom_test_sends_per_day'=>1000]);
Cache::flush();$count=count($GLOBALS['enomWire']['forms']);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
same('succeeded',$op->fresh()->state,'Unique renewal charge and full-year owned expiry complete cycle');same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp','GetOrderDetail'],array_column(array_slice($GLOBALS['enomWire']['forms'],$count),'Command'),'Captured renewal uses exactly five reconciliation reads');
same(['amount'=>'10.00','currency'=>'USD'],$op->fresh()->cost['actual'],'Actual renewal cost retained');same('2028-10-21',$op->domain()->firstOrFail()->provider_expires_at->format('Y-m-d'),'Expiry advances one year');expect($stopDomain->fresh()->renewal_policy['renewals_stopped'],'Completion preserves future stop');same(null,$op->domain()->firstOrFail()->service->expires_at,'Native expiry remains null');
$count=count($GLOBALS['enomWire']['forms']);Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same($count,count($GLOBALS['enomWire']['forms']),'Repeated completed worker sends nothing');

$nativeSuccessful=true;
echo "Installed renewal confirmation passed.\n";
