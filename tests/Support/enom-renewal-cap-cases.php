<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$renewalGroup='cap';
$renewalCases=['cap'];
require __DIR__.'/enom-renewal-holders.php';
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
require __DIR__.'/enom-renewal-execution-fixture.php';
$start('cap');$op=$ops['cap'];config(['domains.enom_test_sends_per_day'=>$recentSends()+11]);
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same(['held','enom_test_renewal_call_cap'],[$op->fresh()->state,$op->fresh()->safe_code],'Cap before write is held safely');same([],array_values(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Extend')),'Cap sends no write');same([],(array)$op->fresh()->attempt,'Cap precedes seal');same(11,count($GLOBALS['enomWire']['forms']),'The refused twelfth read is not sent');
config(['domains.enom_test_sends_per_day'=>1000,'domains.enom_writes.renewal'=>false]);$domain=$op->domain()->firstOrFail();$offTicket=StaffOperationReview::ticket($staff->id,$domain->id,$op->id,'release',StaffOperationReview::stamp($op->fresh(),$domain),$clock);
try{(new StaffOperations($clock))->run($staff,$domain->id,$op->id,'release',$offTicket,'Reviewed hold while TEST renewal is off');throw new LogicException('Off cannot release renewal cap');}catch(DomainException){}
same('held',$op->fresh()->state,'Deployment off retains cap hold');config(['domains.enom_writes.renewal'=>true]);$ticket=StaffOperationReview::ticket($staff->id,$domain->id,$op->id,'release',StaffOperationReview::stamp($op->fresh(),$domain),$clock);
(new StaffOperations($clock))->run($staff,$domain->id,$op->id,'release',$ticket,'Reviewed unsent renewal and restored TEST call capacity');same('queued',$op->fresh()->state,'Audited release makes unsent cap retryable');
Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('pending',$op->fresh()->state,'Released cap renewal freshly admits');same(1,count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Extend')),'Released cap sends one Extend');
file_put_contents($receipt.'.renewal',json_encode(['operation'=>$op->id,'used'=>DB::table(EnomTestSendLimitSchema::SENDS)->count(),'money'=>$money()],JSON_THROW_ON_ERROR));

$nativeSuccessful=true;
echo "Installed renewal cap passed.\n";
