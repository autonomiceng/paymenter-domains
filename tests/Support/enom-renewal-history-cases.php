<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, EnomTestRenewal, EnomTestSendLimit, EnomTestSendLimitSchema, NativeDomainGuard, Refunds, RuntimePorts, RuntimeWork, StaffOperationReview, StaffOperations};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$renewalGroup='history';
$renewalCases=['uncertain'];
require __DIR__.'/enom-renewal-holders.php';
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
require __DIR__.'/enom-renewal-execution-fixture.php';
$start('uncertain');$op=$ops['uncertain'];$before=$money();$GLOBALS['enomWire']['during']=static function(array $p):void{if($p['Command']==='Extend'){$GLOBALS['enomWire']['timeout']=true;}};
(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);same('uncertain',$op->fresh()->state,'Lost write reply stays uncertain');$GLOBALS['enomWire']['timeout']=false;$GLOBALS['enomWire']['during']=null;
$count=count($GLOBALS['enomWire']['forms']);Cache::flush();(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);// A lost reply reads the domain once on the next check; without accepted history it never reads the order history or resends.
same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp'],array_column(array_slice($GLOBALS['enomWire']['forms'],$count),'Command'),'Missing captured ID and unaccepted history reads the domain but never resends');same(['uncertain','renewal_confirmation_required'],[$op->fresh()->state,$op->fresh()->safe_code],'Domain read alone never settles a lost renewal reply');same(null,(new Refunds($clock))->creditRejected($op->id),'Uncertain renewal grants no automatic credit');same($before,$money(),'Uncertainty retains native invoice/payment/credit bytes');
expect(!str_contains(json_encode($op->fresh()->attempt),'TEST-SECRET-TOKEN'),'Credential redaction');
$s->after=true;$s->bill=(new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']))->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');$clock->at=$clock->at->modify('+10 minutes');
// The held request offers the history read in one click, only where it can clear this hold.
$request=fn()=>Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id]);
$recovery=Paymenter\Extensions\Servers\Domains\Admin\Support\HistoryRecovery::class;$rights=$staff->role->permissions;
$staff->role->update(['permissions'=>array_values(array_diff($rights,['admin.domains.settings.history']))]);$request()->assertActionHidden($recovery::NAME);$staff->role->update(['permissions'=>$rights]);
$other=$op->fresh();$other->action='register';expect(!$recovery::offered($other),'A registration read needs a captured order, so history cannot clear it');
DB::table('domains_accounts')->where('server_id',$server->id)->update(['environment'=>'live']);expect(!$recovery::offered($op->fresh()),'Live history values are not confirmed');DB::table('domains_accounts')->where('server_id',$server->id)->update(['environment'=>'test']);
// Past the read window the hold stays and the page says what is left. Check status still reads what Enom shows for the domain.
// After the domain read, the answer step is what is left; it replaces the too-old line.
$sent=new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']);$now=$clock->at;$tooOld='Ask Enom to check this attempt, then enter its answer.';
$clock->at=$sent->modify('+86399 seconds');$request()->assertActionVisible($recovery::NAME)->assertDontSee($tooOld);
$clock->at=$sent->modify('+86400 seconds');$request()->assertActionHidden($recovery::NAME)->assertSee($tooOld)->assertSee('Check status');$clock->at=$now;
$request()->assertSee('Enom’s reply was lost.')->assertActionVisible($recovery::NAME)->callAction($recovery::NAME);
$request()->assertActionHidden($recovery::NAME);
$clock->at=$sent->modify('+1 day');$request()->assertSee($tooOld);$clock->at=$now;
foreach([[],['9002','9003'],['9002']] as $ids){
    $s->orders=$ids;Cache::flush();resetWire(['reply'=>$renewReply]);(new RuntimeWork($clock,new RuntimePorts))->operation($op->id);
    same(count($ids)===1?'succeeded':'uncertain',$op->fresh()->state,'Accepted history requires exactly one matching renewal charge');
    same(['GetDomainInfo','GetRenew','GetDomainInfo','GetDomainExp','GetOrderList',...array_fill(0,count($ids),'GetOrderDetail')],array_column($GLOBALS['enomWire']['forms'],'Command'),'History continuation preserves domain/expiry before bounded order reads');same($ids,array_column(array_values(array_filter($GLOBALS['enomWire']['forms'],static fn($p)=>$p['Command']==='GetOrderDetail')),'OrderID'),'History details use exactly the listed candidate IDs');same($before,$money(),'History never adjusts native money');
}


$nativeSuccessful=true;
echo "Installed renewal history passed.\n";
