<?php
// Socket-free installed renewal composition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,DomainBilling,EnomTestRenewal,EnomTestCalls,EnomTestCallSchema,NativeDomainGuard,Refunds,RuntimePorts,RuntimeWork,StaffOperationReview,StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
// The preceding registration scenario proves this holder's real native checkout,
// synthetic payment, captured-order charge and expiry. It leaves renewals off.
$staff->role->update(['permissions'=>[...$staff->role->permissions,'admin.invoice_transactions.create']]);
$nativeSuccessful=false;config(['domains.enom_test_daily_call_cap'=>1000]);
$clock->at=new DateTimeImmutable('2026-10-22T00:10:00Z');Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();resetWire(['reply'=>$reply]);
// Discard only the preceding scenario's synthetic transfer acceptance before this independent annual import.
$write('acceptance',['ending'=>'com','accept'=>true,'terms'=>[1],'registry'=>true,'verified'=>true,'test_registration_accepted'=>true,'transfer'=>false]);
$write('probe');$write('import',['ending'=>'com']);$write('publish',[],$tld->id);
$holders=[];
foreach(['success','cap','overrun','uncertain','auto','grace','partial','anchor','replacement','calendar','race','issuance'] as $case){
    $clock->at=$clock->at->modify('+61 seconds');Cache::flush();$original=$new(placeholderComFixtureName('renewal-'.$case.'-placeholder.com'));$GLOBALS['testRegisteredName']=$original->domain->domain;
    $worker->operation($original->id);Cache::flush();$worker->operation($original->id);
    same('succeeded',$original->fresh()->state,'Renewal starts with proven original holding');$holders[$case]=$original->domain()->firstOrFail();unset($GLOBALS['testRegisteredName']);
}
$money=static fn()=>array_map(static fn($table)=>DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),['invoices','invoice_items','invoice_transactions','credits']);
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
$make=static function($domain)use($clock,$bindings,$user,$server):Operation {
    $clock->at=new DateTimeImmutable('2027-09-27T12:00:00Z');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());Cache::flush();
    $billing=new DomainBilling($bindings,app(NativeDomainGuard::class),$clock);
    $invoice=$billing->invoice($domain->id,$user);same($invoice->id,$billing->invoice($domain->id,$user)->id,'One obligation per cycle');
    $op=Operation::where('invoice_id',$invoice->id)->sole();App\Helpers\ExtensionHelper::addProcessingPayment($invoice->id,null,$op->amount,'0.00','annual-'.$op->id);
    App\Helpers\ExtensionHelper::addPayment($invoice->id,null,$op->amount,'0.00','annual-'.$op->id);App\Helpers\ExtensionHelper::addPayment($invoice->id,null,$op->amount,'0.00','annual-'.$op->id);same(1,$invoice->transactions()->count(),'Duplicate native callback creates one successful payment');
    same('paid',App\Models\Invoice::find($invoice->id)->status,'Native paid renewal invoice');return $op->fresh();
};
config(['settings.credits_auto_use'=>false]);
$billing=new DomainBilling($bindings,app(NativeDomainGuard::class),$clock);
try{$billing->invoice($holders['success']->id,$user);throw new LogicException('Manual gate stays off');}catch(Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible){}
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
$calendar=DomainBilling::calendar($holders['calendar']);same(['expiry'=>'2027-10-21','issue'=>'2027-09-30','due'=>'2027-10-14'],$calendar,'Installed billing calendar uses day boundaries');
foreach(['2027-09-29','2027-10-14'] as $day){$clock->at=new DateTimeImmutable($day.'T00:00:00Z');try{$billing->invoice($holders['calendar']->id);throw new LogicException('Calendar boundary must refuse');}catch(Paymenter\Extensions\Servers\Domains\Services\RuntimeIneligible){}}
$clock->at=new DateTimeImmutable('2027-09-30T00:00:00Z');$calendarInvoice=$billing->invoice($holders['calendar']->id);same('2027-10-14',$calendarInvoice->due_at->format('Y-m-d'),'Issue day creates due-day debt');
$ops=[];foreach($holders as $case=>$domain){if($case!=='issuance'){$ops[$case]=$make($domain);}}

config(['domains.enom_writes.registration'=>false]);
$s=(object)['name'=>'','after'=>false,'price'=>'10.00','auto'=>'0','expiry'=>'10/21/2027 5:00:00 AM','day'=>'10/21/2027','bill'=>'','order'=>'9002','lost'=>false,'confirmed'=>'2028-10-21','charge'=>'10.00','orders'=>[]];
require __DIR__.'/enom-renewal-wire.php';
$start=static function(string $case)use($s,$ops,$clock,$renewReply,$server):void {
    $s->name=$ops[$case]->domain->domain;$s->after=false;$s->auto='0';$s->price='10.00';$s->expiry='10/21/2027 5:00:00 AM';$s->day='10/21/2027';
    $clock->at=new DateTimeImmutable('2027-10-20T12:00:00Z');Cache::flush();DB::table('domains_read_budgets')->where('server_id',$server->id)->delete();resetWire(['reply'=>$renewReply]);
};
$op=$ops['success'];$start('success');
$stopDomain=$op->domain()->firstOrFail();$stopReview=Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::class;
$stopKey=$stopReview::ticket($staff->id,$stopDomain->id,'stop',$stopReview::stamp(Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($stopDomain,advisory:true)),$clock);
(new $stopReview(app(NativeDomainGuard::class),$clock))->run($staff,$stopDomain->id,'stop',$stopKey,'Stop future cycles and retain the paid issued renewal');
$before=$money();same($op->invoice_id,$billing->invoice($stopDomain->id,$user)->id,'Future stop retains issued paid debt');
config(['domains.enom_writes.renewal'=>false]);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same([],$GLOBALS['enomWire']['forms'],'Off sends no requests');
same(['queued','renewal_acceptance_required',[]],[$op->fresh()->state,$op->fresh()->safe_code,(array)$op->fresh()->attempt],'Read-only recovery keeps disabled renewal queued and unsealed');same($before,$money(),'Disabled renewal preserves paid money');config(['domains.enom_writes.renewal'=>true]);
$write('test_renewal',['accept'=>false]);Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same([],$GLOBALS['enomWire']['forms'],'Missing action acceptance refuses before preflight');
$write('test_renewal',['accept'=>true,'verified'=>true]);
$GLOBALS['enomWire']['during']=static function(array $p)use($op,$clock):void {if($p['Command']==='Extend'){(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);}};
Cache::flush();$used=(int)(DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used')??0);
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);$commands=array_column($GLOBALS['enomWire']['forms'],'Command');
same(['GetDomainInfo','GetExtendInfo','Check','GetDomainInfo','GetRenew','GetBalance','GetDomainInfo','GetExtendInfo','Check','GetDomainInfo','GetRenew','GetBalance','Extend'],$commands,'Two bounded six-read assessments and one Extend');
same('pending',$op->fresh()->state,'Paid renewal acknowledged once');same($used+13,(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Write reservation counted once');same($before,$money(),'Renewal dispatch changes no native financial bytes');
$extend=end($GLOBALS['enomWire']['forms']);same(['1','no'],[$extend['NumYears'],$extend['UseCreditCard']],'One annual renewal without card');
$s->after=true;$s->bill=(new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');
foreach([['2027-10-21','10.00'],['2028-10-20','10.00'],['2028-10-21','invalid']] as [$expiry,$charge]){
    $s->confirmed=$expiry;$s->charge=$charge;Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
    same('pending',$op->fresh()->state,'Missing full-year expiry or valid charge cannot complete');same($before,$money(),'Incomplete confirmation keeps paid money unchanged');
}
$s->confirmed='2028-10-21';$s->charge='10.00';
$capUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');
config(['domains.enom_test_daily_call_cap'=>$capUsed+4]);$count=count($GLOBALS['enomWire']['forms']);$attempt=$op->fresh()->attempt;$polls=$op->fresh()->policy['renewal_polls']??0;
Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp'],array_column(array_slice($GLOBALS['enomWire']['forms'],$count),'Command'),'Mid-confirmation cap retains fresh four-read prefix without reordering');
same(['pending',$attempt,$polls],[$op->fresh()->state,$op->fresh()->attempt,$op->fresh()->policy['renewal_polls']??0],'Partial capped confirmation keeps original attempt and covered-poll count');same($before,$money(),'Partial confirmation never refunds or resends');
config(['domains.enom_test_daily_call_cap'=>1000]);
Cache::flush();$count=count($GLOBALS['enomWire']['forms']);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
same('succeeded',$op->fresh()->state,'Unique renewal charge and full-year owned expiry complete cycle');same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp','GetOrderDetail'],array_column(array_slice($GLOBALS['enomWire']['forms'],$count),'Command'),'Captured renewal uses exactly five reconciliation reads');
same(['amount'=>'10.00','currency'=>'USD'],$op->fresh()->cost['actual'],'Actual renewal cost retained');same('2028-10-21',$op->domain()->firstOrFail()->provider_expires_at->format('Y-m-d'),'Expiry advances one year');expect($stopDomain->fresh()->renewal_policy['renewals_stopped'],'Completion preserves future stop');same(null,$op->domain()->firstOrFail()->service->expires_at,'Native expiry remains null');
$count=count($GLOBALS['enomWire']['forms']);Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same($count,count($GLOBALS['enomWire']['forms']),'Repeated completed worker sends nothing');
$start('cap');$op=$ops['cap'];$capUsed=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');config(['domains.enom_test_daily_call_cap'=>$capUsed+12]);
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same(['held','enom_test_renewal_call_cap'],[$op->fresh()->state,$op->fresh()->safe_code],'Cap before write is held safely');same([],array_values(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Extend')),'Cap sends no write');same([],(array)$op->fresh()->attempt,'Cap precedes seal');same(12,count($GLOBALS['enomWire']['forms']),'All completed preflight reads counted');
config(['domains.enom_test_daily_call_cap'=>1000,'domains.enom_writes.renewal'=>false]);$domain=$op->domain()->firstOrFail();$offTicket=StaffOperationReview::ticket($staff->id,$domain->id,$op->id,'release',StaffOperationReview::stamp($op->fresh(),$domain),$clock);
try{(new StaffOperations($clock))->run($staff,$domain->id,$op->id,'release',$offTicket,'Reviewed hold while TEST renewal is off');throw new LogicException('Off cannot release renewal cap');}catch(DomainException){}
same('held',$op->fresh()->state,'Deployment off retains cap hold');config(['domains.enom_writes.renewal'=>true]);$ticket=StaffOperationReview::ticket($staff->id,$domain->id,$op->id,'release',StaffOperationReview::stamp($op->fresh(),$domain),$clock);
(new StaffOperations($clock))->run($staff,$domain->id,$op->id,'release',$ticket,'Reviewed unsent renewal and restored TEST call capacity');same('queued',$op->fresh()->state,'Audited release makes unsent cap retryable');
Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('pending',$op->fresh()->state,'Released cap renewal freshly admits');same(1,count(array_filter($GLOBALS['enomWire']['forms'],fn($p)=>$p['Command']==='Extend')),'Released cap sends one Extend');
file_put_contents($receipt.'.renewal',json_encode(['operation'=>$op->id,'used'=>(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'money'=>$money()],JSON_THROW_ON_ERROR));
$start('partial');$used=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');config(['domains.enom_test_daily_call_cap'=>$used+5]);
(new RuntimeWork($clock,new RuntimePorts))->operation($ops['partial']->id);same(['held','enom_test_renewal_call_cap'],[$ops['partial']->fresh()->state,$ops['partial']->fresh()->safe_code],'Cap swallowed by preflight remains a safe unsent hold');same(5,count($GLOBALS['enomWire']['forms']),'Partial preflight never oversends');same([],(array)$ops['partial']->fresh()->attempt,'Partial preflight is unsealed');config(['domains.enom_test_daily_call_cap'=>1000]);
$start('anchor');$s->day='10/22/2027';(new RuntimeWork($clock,new RuntimePorts))->operation($ops['anchor']->id);expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Assessment anchor drift refuses write');same([],(array)$ops['anchor']->fresh()->attempt,'Anchor drift is unsealed');
$start('overrun');$s->price='11.00';(new RuntimeWork($clock,new RuntimePorts))->operation($ops['overrun']->id);same('renewal_cost_overrun',$ops['overrun']->fresh()->safe_code,'Frozen cost cap holds overrun');expect($holders['overrun']->fresh()->renewal_policy['automatic_spend_paused'],'Overrun pauses future spending');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Overrun no spending write');
$start('auto');$s->auto='1';(new RuntimeWork($clock,new RuntimePorts))->operation($ops['auto']->id);same('provider_auto_renew_unconfirmed',$ops['auto']->fresh()->safe_code,'Auto-renew must stay off');expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Auto-renew refuses spend');
$start('grace');$s->expiry='10/21/2027 5:00:00 AM';$clock->at=new DateTimeImmutable('2027-10-22T12:00:00Z');(new RuntimeWork($clock,new RuntimePorts))->operation($ops['grace']->id);expect(!in_array('Extend',array_column($GLOBALS['enomWire']['forms'],'Command'),true)&&!in_array('UpdateExpiredDomains',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Grace remains separately closed');
$start('uncertain');$op=$ops['uncertain'];$before=$money();$GLOBALS['enomWire']['during']=static function(array $p):void{if($p['Command']==='Extend'){$GLOBALS['enomWire']['timeout']=true;}};
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('uncertain',$op->fresh()->state,'Lost write reply stays uncertain');$GLOBALS['enomWire']['timeout']=false;$GLOBALS['enomWire']['during']=null;
$count=count($GLOBALS['enomWire']['forms']);Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same($count,count($GLOBALS['enomWire']['forms']),'Missing captured ID and unaccepted history never reads or resends');same(null,(new Refunds($clock))->creditRejected($op->id),'Uncertain renewal grants no automatic credit');same($before,$money(),'Uncertainty retains native invoice/payment/credit bytes');
expect(!str_contains(json_encode($op->fresh()->attempt),'TEST-SECRET-TOKEN'),'Credential redaction');
$write('history',['history_action'=>'renew','accept'=>true,'verified'=>true,'timezone'=>'America/Los_Angeles','lag'=>60,'retention'=>86400]);
$s->after=true;$s->bill=(new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('n/j/Y g:i:s A');$clock->at=$clock->at->modify('+10 minutes');
foreach([[],['9002','9003'],['9002']] as $ids){
    $s->orders=$ids;Cache::flush();resetWire(['reply'=>$renewReply]);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
    same(count($ids)===1?'succeeded':'uncertain',$op->fresh()->state,'Accepted history requires exactly one matching renewal charge');
    same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp','GetOrderList',...array_fill(0,count($ids),'GetOrderDetail')],array_column($GLOBALS['enomWire']['forms'],'Command'),'History continuation preserves domain/expiry before bounded order reads');same($ids,array_column(array_values(array_filter($GLOBALS['enomWire']['forms'],static fn($p)=>$p['Command']==='GetOrderDetail')),'OrderID'),'History details use exactly the listed candidate IDs');same($before,$money(),'History never adjusts native money');
}

require __DIR__.'/enom-renewal-replacement.php';
Auth::setUser($staff);$count=count($GLOBALS['enomWire']['forms']);\Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->assertSeeInOrder(['Setup checklist','Renewals','On','DNS, nameservers and contacts']);same($count,count($GLOBALS['enomWire']['forms']),'Staff page makes zero provider calls');
$continued=json_decode(file_get_contents($receipt.'.renewal'),true,512,JSON_THROW_ON_ERROR);$continued['race_operation']=$ops['race']->id;$continued['issuance_domain']=$holders['issuance']->id;$continued['user']=$user->id;$continued['money']=$money();$continued['used']=(int)DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');file_put_contents($receipt.'.renewal',json_encode($continued,JSON_THROW_ON_ERROR));
file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));$nativeSuccessful=true;
echo "Installed TEST renewal: native payment, cap, unique charge/expiry, restart and uncertainty passed.\n";
