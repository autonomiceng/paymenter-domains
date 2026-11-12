<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$renewalGroup='refusals';
$renewalCases=['partial', 'anchor', 'overrun', 'auto', 'grace'];
require __DIR__.'/enom-renewal-holders.php';
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
require __DIR__.'/enom-renewal-execution-fixture.php';
$start('partial');config(['domains.enom_test_sends_per_day'=>$recentSends()+5]);
(new RuntimeWork($clock,new RuntimePorts))->operation($ops['partial']->id);same(['held','enom_test_renewal_call_cap'],[$ops['partial']->fresh()->state,$ops['partial']->fresh()->safe_code],'Cap swallowed by preflight remains a safe unsent hold');same(5,count($GLOBALS['enomWire']['forms']),'Partial preflight never oversends');same([],(array)$ops['partial']->fresh()->attempt,'Partial preflight is unsealed');config(['domains.enom_test_sends_per_day'=>1000]);
$start('anchor');$s->day='10/22/2027';(new RuntimeWork($clock,new RuntimePorts))->operation($ops['anchor']->id);expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Assessment anchor drift refuses write');same([],(array)$ops['anchor']->fresh()->attempt,'Anchor drift is unsealed');
$start('overrun');$s->price='11.00';(new RuntimeWork($clock,new RuntimePorts))->operation($ops['overrun']->id);same('renewal_cost_overrun',$ops['overrun']->fresh()->safe_code,'Frozen cost cap holds overrun');expect($holders['overrun']->fresh()->renewal_policy['automatic_spend_paused'],'Overrun pauses future spending');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Overrun no spending write');
$start('auto');$s->auto='1';(new RuntimeWork($clock,new RuntimePorts))->operation($ops['auto']->id);same('provider_auto_renew_unconfirmed',$ops['auto']->fresh()->safe_code,'Auto-renew must stay off');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Auto-renew refuses spend');
$start('grace');$s->expiry='10/21/2027 5:00:00 AM';$clock->at=new DateTimeImmutable('2027-10-22T12:00:00Z');(new RuntimeWork($clock,new RuntimePorts))->operation($ops['grace']->id);expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true)&&!in_array('UpdateExpiredDomains',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Grace remains separately closed');

$nativeSuccessful=true;
echo "Installed renewal refusals passed.\n";
