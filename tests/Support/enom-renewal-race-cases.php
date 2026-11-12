<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$renewalGroup='race';
$renewalCases=['race', 'issuance'];
require __DIR__.'/enom-renewal-holders.php';
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
require __DIR__.'/enom-renewal-execution-fixture.php';
Auth::setUser($staff);$count=count($GLOBALS['enomWire']['forms']);\Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->assertSeeInOrder(['Sales','Renewals','On','DNS, nameservers and contacts']);same($count,count($GLOBALS['enomWire']['forms']),'Staff page makes zero provider calls');
$continued=json_decode(file_get_contents($receipt.'.renewal'),true,512,JSON_THROW_ON_ERROR);$continued['race_operation']=$ops['race']->id;$continued['issuance_domain']=$holders['issuance']->id;$continued['user']=$user->id;$continued['money']=$money();$continued['used']=DB::table(EnomTestSendLimitSchema::SENDS)->count();file_put_contents($receipt.'.renewal',json_encode($continued,JSON_THROW_ON_ERROR));
file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));$nativeSuccessful=true;

$nativeSuccessful=true;
echo "Installed renewal race passed.\n";
