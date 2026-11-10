<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/renewal-runtime.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, CostUnit, Environment, HistoryAcceptance, Money};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema, HistoryAcceptances, RenewalCycles};

// Native debt, funding, seal and completion need the existing financial fixture; a pure rule test cannot
// prove invoice totals, the final no-send boundary or preservation of an incurred charge discrepancy.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class, $clock);
staffPanel();
EnomTestSendLimitSchema::install();
$review = static function ($d) use ($ports, $clock): void {
    $t = Tld::where('server_id', $d->server_id)->where('tld', 'com')->sole();
    $facts = TermSupport::attach('com', OfferState::facts($t->catalogue_facts), TermSupport::REVIEWED_EVIDENCE['enom']['com'] ?? null);
    DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::encodeFacts($facts)]);
    $b = \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $d->server_id)->sole());
    $q = new Query($b, ['com'], 'renew');
    $ports->apply(new FeedObserved($b, 'com', [new Cost('renew', 2, true, '16.0000', CostUnit::TermTotal,
        'enom_renew_annual_total', $clock->now(), $clock->now()->modify('+30 days'))]), $ports->readOffers($q, Context::Staff)[0]->revision);
    $choices = $GLOBALS['billing']->renewalChoices($d);
    same([1, 2], $choices['terms'], 'Normal owner panel offers reviewed Enom TEST two-year renewal');
    same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::total($choices['next_annual'], 2), $choices['lines'][2]['total'], 'Panel charges twice annual sell price');
};

config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.renewal'=>true]);
$rights=['admin.domains.view','admin.domains.costs','admin.domains.manage','admin.domains.fund','admin.invoices.update','admin.domains.operations.resume_reads','admin.domains.operations.recheck','admin.domains.operations.approve_charge'];
$staff=staffUser($rights);$role=$staff->role;$failures=[];$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;}};
try{foreach(['changed'=>['20.00','actual_charge_changed'],'overrun'=>['16.00','actual_cost_overrun']]as$case=>[$limit,$reason]){
 app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);config(['domains.enom_identity.environment'=>null]);
 [$seed,$d,$b]=$makeRenewal(placeholderComFixtureName('captured-'.$case.'-placeholder.com'),false);$cycles->retireUnpaid($seed->id);
 $clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$review($d);
 $op=$cycles->complimentary($d->id,$staff,2,new Money($limit,'USD'),'staff_reviewed');
 $state->years=2;$state->after='2029-10-18';$state->expiry='10/18/2027 5:00:00 AM';$state->charge='16.01';$state->fail=null;$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');Cache::flush();config(['domains.enom_identity.environment'=>'test']);
 [$engine,$writer,$completion]=$compose($b);same('pending',$engine->run($op->id,$writer)->state,'Real two-year send');$state->expiry='2029-10-18';
 // The changed case is captured while auto-renew is still on at the registrar, so its frozen proof keeps that correction.
 if($case==='changed'){$state->auto='1';}
 $held=$completion->run($op->id);same($reason,$held->safe_code,'Real captured financial hold');same($case==='changed'?['auto_renew']:[],$held->attempt['reconciliation']['observed_corrections'],'Frozen proof keeps the auto-renew correction it saw');
 app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class,$completion);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$renewBindings);
 $work=$renewalRuntime($b,$engine,$writer,$completion);$observe=static function()use($work,$op):void{app(\Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->enqueue($op->id,true);\Illuminate\Support\Facades\Queue::assertPushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,fn($job)=>$job->operationId===$op->id);(new \Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id))->handle($work);};
 $rawProof=[$held->getRawOriginal('attempt'),$held->getRawOriginal('cost')];$attempt=$held->attempt;$cost=$held->cost;$amount=$held->amount;$expiry=$d->fresh()->provider_expires_at->format('Y-m-d');$writes=$state->writes;$calls=count($state->calls);
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);$page->assertActionVisible('resume_reads')->callAction('resume_reads')->assertNotified('Status checks restarted');
 same($calls,count($state->calls),'Audited action makes no inline provider request');
 // While the restarted read waits in the queue, the notice offers Check status for that one read, not another restart.
 same('recheck',\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($op->fresh())['action']??null,$case.': a queued audited read offers Check status');
 Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('recheck')->assertActionHidden('resume_reads');
 $state->charge='16.02';
 // A concurrent worker must see the consumed one-read permission before the first read returns.
 $state->during=static function()use($completion,$op,$state):void{$before=count($state->calls);$completion->run($op->id);same($before,count($state->calls),'A concurrent worker cannot consume the same resume');};
 try{$observe();}catch(Throwable $error){$failures[]=$case.': changed observation must be retained without replacing frozen proof ('.$error::class.')';}
 $fresh=$op->fresh();$observations=$fresh->policy['captured_charge_observations']??[];
 $check(count($observations)===1&&\Paymenter\Extensions\Servers\Domains\Services\WriteMoney::compare($observations[0]['proof']['charge']['amount']??'0.00','16.02')===0,$case.': changed charge is separately retained');
 $check([$fresh->getRawOriginal('attempt'),$fresh->getRawOriginal('cost')]===$rawProof&&$fresh->attempt===$attempt&&$fresh->cost===$cost&&$fresh->amount===$amount,$case.': original attempt, actual cost and customer amount stay byte-equivalent');
 $check($fresh->state==='uncertain'&&$fresh->safe_code===$reason&&$d->fresh()->provider_expires_at->format('Y-m-d')===$expiry&&$d->fresh()->renewal_policy['automatic_spend_paused'],$case.': changed observation keeps financial hold, expiry and spending pause');
 $calls=count($state->calls);$completion->run($op->id);$check($calls===count($state->calls),$case.': automatic reads stop after the one resumed observation');
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);$check(!($page->instance()->getAction('approve_charge',false)?->isVisible()??false),$case.': different charge cannot approve original evidence');
 // A failed read is consumed too and cannot reuse a previous successful observation for approval.
 $page->callAction('resume_reads');$state->fail=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Order;$observe();$state->fail=null;
 same(null,($op->fresh()->policy['captured_charge_observations'][1]['proof']??null),'Unavailable read retains no invented proof');$calls=count($state->calls);$completion->run($op->id);same($calls,count($state->calls),'Failed one-read permission stays consumed');
 // The matching read comes after the registrar confirmed auto-renew Off. Check status takes it now; the delayed queued read then finds it used.
 $state->charge='16.01';$state->auto='0';$resumed=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');
 $calls=count($state->calls);$resumed->assertActionVisible('recheck')->callAction('recheck');expect(count($state->calls)>$calls,$case.': Check status reads the original order');
 $calls=count($state->calls);$observe();same($calls,count($state->calls),$case.': the delayed queued read finds the audited read already used');
 $fresh=$op->fresh();same(3,count($fresh->policy['captured_charge_observations']),'Each audited read has one retained outcome');
 $row=$fresh->policy['captured_charge_observations'][2];same([$staff->id,$fresh->operation_key,$fresh->account_fingerprint],[$row['actor_id'],$row['operation_key'],$row['fingerprint']],'Observation binds the audited actor and current original attempt');
 $d->refresh()->update(['renewal_policy'=>[...$d->renewal_policy,'owner_auto_renew'=>false]]);
 same([],$row['proof']['observed_corrections'],'The matching read saw auto-renew Off');
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('approve_charge')->assertSee('The registrar already charged this order');
 // Current permission loss is checked at submit, not taken from the mounted confirmation.
 $page->mountAction('approve_charge')->setActionData(['reason'=>'Review the same incurred registrar charge']);$before=[$fresh->getRawOriginal(),$d->fresh()->getRawOriginal()];
 // Every staff action has its wording; each one that opens a confirmation shows a sentence.
 foreach(array_keys(\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ACTIONS) as $action){$text=\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::description($action);expect($text===null||is_string($text)&&$text!=='',$case.': wording for '.$action);}
 foreach(['approve_charge','disposition','refund','credit','replacement'] as $action){expect(is_string(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::description($action)),$case.': confirmation wording for '.$action);}
 same(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::description('approve_charge'),$page->instance()->getMountedAction()?->getModalDescription(),'The live dialog uses the shared approval wording');
 $role->update(['permissions'=>array_values(array_diff($rights,['admin.domains.costs']))]);
 $page->callMountedAction();same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()],'Revoked cost access cannot approve');
 Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('approve_charge')->assertDontSee('16.01')->assertDontSee('16.02');$role->update(['permissions'=>$rights]);
 // Approval needs its own permission, even with a current ticket and the same proof.
 $role->update(['permissions'=>array_values(array_diff($rights,['admin.domains.operations.approve_charge']))]);
 $key=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket($staff->id,$d->id,$op->id,'approve_charge',\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($op->fresh(),$d->fresh()),$clock);$before=[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()];
 try{(new \Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$d->id,$op->id,'approve_charge',$key,'Approve without the approval permission');expect(false,'Missing approval permission must refuse');}catch(DomainException $refused){same("You don't have permission to do this.",$refused->getMessage(),'Refused for the missing approval permission');}
 same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()],'Missing approval permission changes nothing');$role->update(['permissions'=>$rights]);
 expect(\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::approvable($op->fresh()),'The same proof stays approvable for permitted staff');
 // Foreign captured proof cannot turn current observations into approval authority.
 $rawAttempt=$op->fresh()->getRawOriginal('attempt');$foreign=$op->fresh()->attempt;$foreign['reconciliation']['fingerprint']=str_repeat('f',64);
 DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($foreign))]);
 $wrong=$op->fresh();$key=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket($staff->id,$d->id,$op->id,'approve_charge',\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($wrong,$d->fresh()),$clock);$before=$wrong->getRawOriginal();
 try{(new \Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$d->id,$op->id,'approve_charge',$key,'Review foreign captured evidence');expect(false,'Foreign proof must refuse');}catch(DomainException){}
 same($before,$op->fresh()->getRawOriginal(),'Foreign proof approval changes nothing');DB::table('domains_operations')->where('id',$op->id)->update(['attempt'=>$rawAttempt]);
 // A mounted old approval is stale after another audited observation.
 $stale=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->mountAction('approve_charge')->setActionData(['reason'=>'Approve stale charge review']);
 Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');$observe();$unchanged=$op->fresh()->getRawOriginal();
 $stale->callMountedAction();same($unchanged,$op->fresh()->getRawOriginal(),'Stale approval changes nothing');
 $fresh=$op->fresh();$ticket=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket($staff->id,$d->id,$op->id,'approve_charge',\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($fresh,$d->fresh()),$clock);
 $calls=count($state->calls);$page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('approve_charge',['reason'=>'Approve the same incurred charge after review'])->assertHasNoActionErrors()->assertNotified('Captured charge approved')->assertActionHidden('approve_charge');
 same($calls,count($state->calls),'Approving retained proof makes no provider request');$done=$op->fresh();same(['succeeded','2029-10-18'],[$done->state,$d->fresh()->provider_expires_at->format('Y-m-d')],'Approval completes only the proved expiry');
 same($rawProof,[$done->getRawOriginal('attempt'),$done->getRawOriginal('cost')],'Approval preserves original encrypted bytes');same([$attempt,$cost,$amount],[$done->attempt,$done->cost,$done->amount],'Approval never rewrites frozen proof, actual cost or customer amount');
 expect($d->fresh()->renewal_policy['automatic_spend_paused']&&$d->fresh()->renewal_policy['owner_auto_renew']===false,'Approval does not clear any future spending pause or owner preference');same(null,$done->invoice_id,'Manual complimentary approval creates no customer debt or receipt');
 $before=[$done->getRawOriginal(),$d->fresh()->getRawOriginal()];
 try{(new \Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$d->id,$op->id,'approve_charge',$ticket,'Replay old captured approval');expect(false,'Old approval must refuse');}catch(DomainException){}
 same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()],'Replay preserves the completed debt and anchor');
 $changed=$done->policy;$changed['captured_charge_observations'][0]['proof']['charge']['amount']='1.00';
 try{$op->fresh()->update(['policy'=>$changed]);expect(false,'Retained observation must be protected');}catch(DomainException){}
 same($writes,$state->writes,'No staff review resends Extend');
}
}catch(Throwable $error){throw new RuntimeException($error->getMessage().' at '.implode(' > ',array_map(static fn($frame)=>($frame['file']??'').':'.($frame['line']??0),$error->getTrace())),0,$error);}
expect($failures===[],implode('; ',$failures));
// Accepted renewal history can prove a captured charge after a lost reply. Eligibility and approval accept it alike, with no other send.
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);config(['domains.enom_identity.environment'=>null]);
[$seed,$d,$b]=$makeRenewal(placeholderComFixtureName('captured-history-placeholder.com'),false);$cycles->retireUnpaid($seed->id);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$review($d);$op=$cycles->complimentary($d->id,$staff,2,new Money('20.00','USD'),'staff_reviewed');
$state->years=2;$state->after='2029-10-18';$state->expiry='10/18/2027 5:00:00 AM';$state->charge='16.01';$state->fail=null;$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');Cache::flush();config(['domains.enom_identity.environment'=>'test']);
[$engine,$writer,$completion]=$compose($b);$state->lost=true;same('uncertain',$engine->run($op->id,$writer)->state,'Lost two-year reply');$state->lost=false;$writes=$state->writes;
$accept=static fn()=>HistoryAcceptances::save(new HistoryAcceptance($b,'America/Los_Angeles',0,9*86400,'enom_renewal_v1'));$accept();
$clock->instant=$clock->now()->modify('+10 minutes');$state->expiry='2029-10-18';
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class,$completion);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$renewBindings);
$held=$completion->run($op->id);same(['actual_charge_changed','accepted_history'],[$held->safe_code,$held->attempt['reconciliation']['source']??null],'Accepted history proves the captured charge');
$rawProof=[$held->getRawOriginal('attempt'),$held->getRawOriginal('cost')];$work=$renewalRuntime($b,$engine,$writer,$completion);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');(new \Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id))->handle($work);
same('accepted_history',collect($op->fresh()->policy['captured_charge_observations'])->last()['proof']['source']??null,'The audited read finds the same order in history');
DB::transaction(fn()=>HistoryAcceptances::revokeLocked($b,Action::Renew));
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('approve_charge');$accept();
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('approve_charge')
    ->callAction('approve_charge',['reason'=>'Approve the same charge found in order history'])->assertHasNoActionErrors()->assertNotified('Captured charge approved');
$done=$op->fresh();same(['succeeded','2029-10-18'],[$done->state,$d->fresh()->provider_expires_at->format('Y-m-d')],'History approval completes only the proved expiry');
same($rawProof,[$done->getRawOriginal('attempt'),$done->getRawOriginal('cost')],'History approval preserves original encrypted bytes');same($writes,$state->writes,'History approval sends nothing');
expect($d->fresh()->renewal_policy['automatic_spend_paused'],'History approval keeps future spending paused');DB::transaction(fn()=>HistoryAcceptances::revokeLocked($b,Action::Renew));
// History that stops covering the attempt cannot approve, even from an open dialog, and an audited read after that is used up as unavailable.
$clockBefore=$clock->instant;app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);config(['domains.enom_identity.environment'=>null]);
[$seed,$d,$b]=$makeRenewal(placeholderComFixtureName('captured-history-expired-placeholder.com'),false);$cycles->retireUnpaid($seed->id);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$review($d);$op=$cycles->complimentary($d->id,$staff,2,new Money('20.00','USD'),'staff_reviewed');
$state->years=2;$state->after='2029-10-18';$state->expiry='10/18/2027 5:00:00 AM';$state->charge='16.01';$state->fail=null;$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');Cache::flush();config(['domains.enom_identity.environment'=>'test']);
[$engine,$writer,$completion]=$compose($b);$state->lost=true;same('uncertain',$engine->run($op->id,$writer)->state,'Lost two-year reply before history expires');$state->lost=false;$writes=$state->writes;$accept();
$clock->instant=$clock->now()->modify('+10 minutes');$state->expiry='2029-10-18';
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class,$completion);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$renewBindings);
$held=$completion->run($op->id);same('accepted_history',$held->attempt['reconciliation']['source']??null,'Current history proves the captured charge');
$rawProof=[$held->getRawOriginal('attempt'),$held->getRawOriginal('cost')];$work=$renewalRuntime($b,$engine,$writer,$completion);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');(new \Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id))->handle($work);
$dialog=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('approve_charge')->mountAction('approve_charge')->setActionData(['reason'=>'Approve before history expires']);
$clock->instant=$clock->now()->modify('+9 days');$before=[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()];
expect(!\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::approvable($op->fresh()),'Expired history is not approvable');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('approve_charge');
$dialog->callMountedAction();same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()],'History that expires while the dialog is open cannot approve');
$observed=count($op->fresh()->policy['captured_charge_observations']);$reads=count($op->fresh()->policy['captured_charge_reads']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');$calls=count($state->calls);(new \Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id))->handle($work);
$fresh=$op->fresh();same([$reads+1,$observed+1,null],[count($fresh->policy['captured_charge_reads']),count($fresh->policy['captured_charge_observations']),collect($fresh->policy['captured_charge_observations'])->last()['proof']],'The audited read after expiry is retained as unavailable');
same($calls,count($state->calls),'Expired history makes no registrar read');$calls=count($state->calls);$completion->run($op->id);same([$calls,$observed+1],[count($state->calls),count($op->fresh()->policy['captured_charge_observations'])],'The used read is not taken again');
$notice=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($op->fresh());
same(null,$notice['action'],'Expired history offers no read that cannot settle the charge');expect(str_contains($notice['body'],'Ask Enom if this renewal went through.'),'Expired history names the next step');
same([$rawProof,'uncertain',true],[[$fresh->getRawOriginal('attempt'),$fresh->getRawOriginal('cost')],$fresh->state,$d->fresh()->renewal_policy['automatic_spend_paused']],'Expired history keeps the frozen proof, cost, hold and spending pause');
same($writes,$state->writes,'Expired history sends nothing');DB::transaction(fn()=>HistoryAcceptances::revokeLocked($b,Action::Renew));$clock->instant=$clockBefore;
// Ordinary exact-charge paid completion still uses its frozen two-year total and no staff approval.
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);config(['domains.enom_identity.environment'=>null]);
[$paid,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('captured-paid-control-placeholder.com'),true,2,beforeBill:$review);
config(['domains.enom_identity.environment'=>'test']);[$engine,$writer,$completion]=$compose($b);$state->years=2;$state->after='2029-10-18';$state->charge='16.00';same('pending',$engine->run($paid->id,$writer)->state,'Paid exact two-year send');$state->expiry='2029-10-18';
$done=$completion->run($paid->id);same(['succeeded',2,'2029-10-18'],[$done->state,$state->lastWrite[1]['NumYears'],$d->fresh()->provider_expires_at->format('Y-m-d')],'Exact paid charge completes the real native invoice');same($paid->amount,$invoice->items()->sole()->price,'Original retail total stays frozen');
// The ordinary exact-charge complimentary path remains independent of excess-charge approval.
config(['domains.enom_identity.environment'=>null]);[$seed,$d,$b]=$makeRenewal(placeholderComFixtureName('captured-comp-control-placeholder.com'),false);$cycles->retireUnpaid($seed->id);$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$review($d);
$comp=$cycles->complimentary($d->id,$staff,2,new Money('16.00','USD'),'staff_reviewed');$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');config(['domains.enom_identity.environment'=>'test']);Cache::flush();
config(['domains.enom_identity.environment'=>'test']);[$engine,$writer,$completion]=$compose($b);$state->years=2;$state->after='2029-10-18';$state->charge='16.00';same('pending',$engine->run($comp->id,$writer)->state,'Complimentary exact two-year send');$state->expiry='2029-10-18';
same(['succeeded','2029-10-18','0.00'],[$completion->run($comp->id)->state,$d->fresh()->provider_expires_at->format('Y-m-d'),$comp->amount],'Exact complimentary charge completes without the approval action');
echo "Native captured-charge observation and audited approval passed\n";$nativeSuccessful=true;
