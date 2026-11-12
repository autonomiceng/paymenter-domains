<?php
// Socket-free installed renewal composition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/enom-test-sales-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
// Registration contracts remain in enom-test-registration.php. Seed only this renewal group.
$staff->role->update(['permissions'=>[...$staff->role->permissions,'admin.invoice_transactions.create']]);
$nativeSuccessful=false;config(['domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
// The limit counts this login's sends in the rolling 24 hours before the scenario clock.
$recentSends=static fn():int=>EnomTestSendLimit::usage('test-uid')['day'];
$clock->at=new DateTimeImmutable('2026-10-22T00:10:00Z');Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();resetWire(['reply'=>$reply]);
// Independent current annual evidence replaces the preceding transfer fixture's support.
Auth::setUser($staff);config(['domains.enom_writes.renewal'=>true]);
$write('test_renewal',['accept'=>true,'verified'=>true]);$write('test_transfer',['accept'=>false]);
$tld=installedOfferEvidence($binding,$clock);
$write('acceptance',['sell'=>['terms'=>['com'=>['longest'=>1,'transfer'=>false]]]]);
$write('probe');$write('publish',[],$tld->id);
$holders=[];
foreach($renewalCases as $case){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();$original=$new(placeholderComFixtureName('renewal-'.$renewalGroup.'-'.$case.'-placeholder.com'));$GLOBALS['testRegisteredName']=$original->domain->domain;
    $worker->operation($original->id);Cache::flush();$worker->operation($original->id);
    same('succeeded',$original->fresh()->state,'Renewal starts with proven original holding');$holders[$case]=$original->domain()->firstOrFail();unset($GLOBALS['testRegisteredName']);
}
$money=static fn()=>array_map(static fn($table)=>DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),['invoices','invoice_items','invoice_transactions','credits']);
$make=static function($domain)use($clock,$bindings,$user,$server,$staff,$binding):Operation {
    $clock->at=new DateTimeImmutable('2027-09-27T12:00:00Z');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
    Auth::setUser($staff);installedRenewalFacts($binding,$clock->now(),$clock->now()->modify('+30 days'));
    $billing=new DomainBilling($bindings,app(NativeDomainGuard::class),$clock,catalogue: $GLOBALS['caps']??null);
    $invoice=$billing->invoice($domain->id,$user);same($invoice->id,$billing->invoice($domain->id,$user)->id,'One obligation per cycle');
    $op=Operation::where('invoice_id',$invoice->id)->sole();App\Helpers\ExtensionHelper::addProcessingPayment($invoice->id,null,$op->amount,'0.00','annual-'.$op->id);
    App\Helpers\ExtensionHelper::addPayment($invoice->id,null,$op->amount,'0.00','annual-'.$op->id);App\Helpers\ExtensionHelper::addPayment($invoice->id,null,$op->amount,'0.00','annual-'.$op->id);same(1,$invoice->transactions()->count(),'Duplicate native callback creates one successful payment');
    same('paid',App\Models\Invoice::find($invoice->id)->status,'Native paid renewal invoice');return $op->fresh();
};
App\Models\Setting::updateOrCreate(['key'=>'credits_auto_use','settingable_type'=>null],['value'=>false,'type'=>'boolean']);config(['settings.credits_auto_use'=>false]); // Stored too: a page save reloads settings from the database.
$billing=new DomainBilling($bindings,app(NativeDomainGuard::class),$clock,catalogue: $GLOBALS['caps']??null);
