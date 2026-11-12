<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$renewalGroup='policy';
$renewalCases=['success', 'calendar'];
require __DIR__.'/enom-renewal-holders.php';
Auth::setUser($staff);$write('test_renewal',['accept'=>false]);
foreach([false,'true',1,null] as $off){config(['domains.enom_writes.renewal'=>$off]);expect(!EnomTestRenewal::enabled($binding),'Only strict true opts in');}
try{$write('test_renewal',['accept'=>true,'verified'=>true]);throw new LogicException('Off cannot record acceptance');}catch(DomainException $e){same('TEST renewal deployment is unavailable.',$e->getMessage(),'Off review refuses safely');}
config(['domains.enom_writes.renewal'=>true]);
foreach(['live','',null] as $env){config(['domains.enom_identity.environment'=>$env]);expect(!EnomTestRenewal::enabled($binding),'Live and unset cannot compose TEST renewal');expect(str_contains(EnomTestRenewal::reason(),'not test'),'Safe wrong-environment reason');}
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>false]);expect(!EnomTestRenewal::enabled($binding),'Catalogue off refuses renewal');
config(['domains.enom_catalogue.enabled'=>true]);expect(EnomTestRenewal::enabled($binding),'Strict TEST renewal composition opens');
expect(!EnomTestRenewal::enabled(new Paymenter\Extensions\Servers\Domains\Data\AccountBinding('enom',$binding->serverId,Paymenter\Extensions\Servers\Domains\Data\Environment::Live,'test-uid')),'Live fingerprint refused');
expect(!EnomTestRenewal::accepted($binding),'New renewal acceptance defaults closed');
try{$write('test_renewal',['accept'=>true]);throw new LogicException('Independent review required');}catch(DomainException){}
$write('test_renewal',['accept'=>true,'verified'=>true]);expect(EnomTestRenewal::accepted($binding),'Audited account-bound TEST renewal review accepted');
$saved=App\Models\Server::find($server->id)->settings()->where('key',EnomTestRenewal::KEY)->sole();expect($saved->encrypted,'Renewal review stored encrypted');
expect(!EnomTestRenewal::accepted(new Paymenter\Extensions\Servers\Domains\Data\AccountBinding('enom',$binding->serverId,Paymenter\Extensions\Servers\Domains\Data\Environment::Test,'foreign-uid')),'Accepted fingerprint cannot cross accounts');
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>false,'type'=>'boolean']);
try{$billing->invoice($holders['success']->id,$user);throw new LogicException('Manual gate stays off');}catch(Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible){}
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
$calendar=DomainBilling::calendar($holders['calendar']);same(['expiry'=>'2027-10-21','issue'=>'2027-09-30','due'=>'2027-10-14'],$calendar,'Installed billing calendar uses day boundaries');
foreach(['2027-09-29','2027-10-14'] as $day){$clock->at=new DateTimeImmutable($day.'T00:00:00Z');try{$billing->invoice($holders['calendar']->id);throw new LogicException('Calendar boundary must refuse');}catch(Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible){}}
$clock->at=new DateTimeImmutable('2027-09-30T00:00:00Z');$calendarInvoice=$billing->invoice($holders['calendar']->id);same('2027-10-14',$calendarInvoice->due_at->format('Y-m-d'),'Issue day creates due-day debt');
$nativeSuccessful=true;
echo "Installed renewal policy passed.\n";
