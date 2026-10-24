<?php
// Installed unsent-cycle settlement; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,EnomTestCalls,EnomTestCallSchema,RuntimePorts,RuntimeWork,StaffOperationReview,StaffOperations};
use Illuminate\Support\Facades\{Cache,DB};
$start('replacement');$op=$ops['replacement'];$used=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');
config(['domains.enom_test_daily_call_cap'=>$used+5]);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('held',$op->fresh()->state,'Replacement begins with proven unsent cap hold');
config(['domains.enom_test_daily_call_cap'=>1000]);
$reviewAction=static function(string $action)use($op,$staff,$clock):void {
    $domain=$op->domain()->firstOrFail();$ticket=StaffOperationReview::ticket($staff->id,$domain->id,$op->id,$action,StaffOperationReview::stamp($op->fresh(),$domain),$clock);
    (new StaffOperations($clock))->run($staff,$domain->id,$op->id,$action,$ticket,'Review unsent renewal settlement and replacement');
};
$expiry=$op->domain->provider_expires_at->format('Y-m-d');$reviewAction('refund');same('rejected',$op->fresh()->state,'Audited unsent refund closes the old obligation');
same($op->invoice_id,$billing->invoice($op->domain_id,$user)->id,'Without replacement review billing retains the settled old debt');
$reviewAction('replacement');$old=$op->fresh()->getRawOriginal();$replacement=$make($op->domain);
same($op->operation_key.':replacement:1',$replacement->operation_key,'One independently authorized replacement');same($expiry,$replacement->domain->provider_expires_at->format('Y-m-d'),'Credit and replacement retain expiry');
$clock->at=new DateTimeImmutable('2027-10-20T12:00:00Z');Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire(['reply'=>$renewReply]);
(new RuntimeWork($clock,new RuntimePorts))->operation($replacement->id);same('pending',$replacement->fresh()->state,'Replacement paid obligation sends once');
$count=count($GLOBALS['enomWire']['forms']);(new RuntimeWork($clock,new RuntimePorts))->operation($replacement->id);
same(1,count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Extend')),'Replacement never resends');same($old,$op->fresh()->getRawOriginal(),'Replacement preserves old obligation and refund evidence');
