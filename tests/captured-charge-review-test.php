<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/renewal-runtime.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment, Money};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestSendLimitSchema, RenewalCycles};

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
foreach(['changed'=>['20.00','actual_charge_changed'],'overrun'=>['16.00','actual_cost_overrun']]as$case=>[$limit,$reason]){
 config(['domains.enom_identity.environment'=>null]);
 [$seed,$d,$b]=$makeRenewal(placeholderComFixtureName('captured-'.$case.'-placeholder.com'),false);$cycles->retireUnpaid($seed->id);
 $clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$review($d);
 $op=$cycles->complimentary($d->id,$staff,2,new Money($limit,'USD'),'staff_reviewed');
 $state->years=2;$state->after='2029-10-18';$state->expiry='10/18/2027 5:00:00 AM';$state->charge='16.01';$state->fail=null;$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');Cache::flush();config(['domains.enom_identity.environment'=>'test']);
 [$engine,$writer,$completion]=$compose($b);same('pending',$engine->run($op->id,$writer)->state,'Real two-year send');$state->expiry='2029-10-18';$held=$completion->run($op->id);same($reason,$held->safe_code,'Real captured financial hold');
 app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::class,$completion);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$renewBindings);
 $work=$renewalRuntime($b,$engine,$writer,$completion);$observe=static function()use($work,$op):void{app(\Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->enqueue($op->id,true);\Illuminate\Support\Facades\Queue::assertPushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class,fn($job)=>$job->operationId===$op->id);(new \Paymenter\Extensions\Servers\Domains\Jobs\RunOperation($op->id))->handle($work);};
 $attempt=$held->attempt;$cost=$held->cost;$amount=$held->amount;$expiry=$d->fresh()->provider_expires_at->format('Y-m-d');$writes=$state->writes;$calls=count($state->calls);
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);$page->assertActionVisible('resume_reads')->callAction('resume_reads')->assertNotified('Status checks restarted');
 same($calls,count($state->calls),'Audited action makes no inline provider request');
 $state->charge='16.02';
 // A concurrent worker must see the consumed one-read permission before the first read returns.
 $state->during=static function()use($completion,$op,$state):void{$before=count($state->calls);$completion->run($op->id);same($before,count($state->calls),'A concurrent worker cannot consume the same resume');};
 try{$observe();}catch(Throwable $error){$failures[]=$case.': changed observation must be retained without replacing frozen proof ('.$error::class.')';}
 $fresh=$op->fresh();$observations=$fresh->policy['captured_charge_observations']??[];
 $check(count($observations)===1&&\Paymenter\Extensions\Servers\Domains\Services\WriteMoney::compare($observations[0]['proof']['charge']['amount']??'0','16.02')===0,$case.': changed charge is separately retained');
 $check($fresh->attempt===$attempt&&$fresh->cost===$cost&&$fresh->amount===$amount,$case.': original attempt, actual cost and customer amount stay byte-equivalent');
 $check($fresh->state==='uncertain'&&$fresh->safe_code===$reason&&$d->fresh()->provider_expires_at->format('Y-m-d')===$expiry&&$d->fresh()->renewal_policy['automatic_spend_paused'],$case.': changed observation keeps financial hold, expiry and spending pause');
 $calls=count($state->calls);$completion->run($op->id);$check($calls===count($state->calls),$case.': automatic reads stop after the one resumed observation');
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id]);$check(!($page->instance()->getAction('approve_charge',false)?->isVisible()??false),$case.': different charge cannot approve original evidence');
 if(!class_exists(\Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::class)){continue;}
 // A failed read is consumed too and cannot reuse a previous successful observation for approval.
 $page->callAction('resume_reads');$state->fail=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Order;$observe();$state->fail=null;
 same(null,($op->fresh()->policy['captured_charge_observations'][1]['proof']??null),'Unavailable read retains no invented proof');$calls=count($state->calls);$completion->run($op->id);same($calls,count($state->calls),'Failed one-read permission stays consumed');
 $state->charge='16.01';Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');$observe();
 $fresh=$op->fresh();same(3,count($fresh->policy['captured_charge_observations']),'Each audited read has one retained outcome');
 $row=$fresh->policy['captured_charge_observations'][2];same([$staff->id,$fresh->operation_key,$fresh->account_fingerprint],[$row['actor_id'],$row['operation_key'],$row['fingerprint']],'Observation binds the audited actor and current original attempt');
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('approve_charge')->assertSee('The registrar already charged this order');
 // Current permission loss is checked at submit, not taken from the mounted confirmation.
 $page->mountAction('approve_charge')->setActionData(['reason'=>'Review the same incurred registrar charge']);$before=[$fresh->getRawOriginal(),$d->fresh()->getRawOriginal()];
 $role->update(['permissions'=>array_values(array_diff($rights,['admin.domains.costs']))]);
 $page->callMountedAction();same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()],'Revoked cost access cannot approve');
 Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('approve_charge')->assertDontSee('16.01')->assertDontSee('16.02');$role->update(['permissions'=>$rights]);
 // A mounted old approval is stale after another audited observation.
 $stale=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->mountAction('approve_charge')->setActionData(['reason'=>'Approve stale charge review']);
 Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('resume_reads');$observe();$unchanged=$op->fresh()->getRawOriginal();
 $stale->callMountedAction();same($unchanged,$op->fresh()->getRawOriginal(),'Stale approval changes nothing');
 $fresh=$op->fresh();$ticket=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ticket($staff->id,$d->id,$op->id,'approve_charge',\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($fresh,$d->fresh()),$clock);
 $page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('approve_charge',['reason'=>'Approve the same incurred charge after review'])->assertHasNoActionErrors()->assertNotified('Captured charge approved')->assertActionHidden('approve_charge');
 $done=$op->fresh();same(['succeeded','2029-10-18'],[$done->state,$d->fresh()->provider_expires_at->format('Y-m-d')],'Approval completes only the proved expiry');
 same([$attempt,$cost,$amount],[$done->attempt,$done->cost,$done->amount],'Approval never rewrites frozen proof, actual cost or customer amount');
 expect($d->fresh()->renewal_policy['automatic_spend_paused'],'Approval does not clear any future spending pause');same('paid',App\Models\Invoice::findOrFail($done->invoice_id)->status,'The zero receipt remains Paid');
 $before=[$done->getRawOriginal(),$d->fresh()->getRawOriginal()];
 try{(new \Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$d->id,$op->id,'approve_charge',$ticket,'Replay old captured approval');expect(false,'Old approval must refuse');}catch(DomainException){}
 same($before,[$op->fresh()->getRawOriginal(),$d->fresh()->getRawOriginal()],'Replay preserves the completed debt and anchor');
 $changed=$done->policy;$changed['captured_charge_observations'][0]['proof']['charge']['amount']='1.00';
 try{$op->fresh()->update(['policy'=>$changed]);expect(false,'Retained observation must be protected');}catch(DomainException){}
 same($writes,$state->writes,'No staff review resends Extend');
}
expect($failures===[],implode('; ',$failures));
// Ordinary exact-charge paid completion still uses its frozen two-year total and no staff approval.
[$paid,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('captured-paid-control-placeholder.com'),true,2,beforeBill:$review);
[$engine,$writer,$completion]=$compose($b);$state->years=2;$state->after='2029-10-18';$state->charge='16.00';same('pending',$engine->run($paid->id,$writer)->state,'Paid exact two-year send');$state->expiry='2029-10-18';
$done=$completion->run($paid->id);same(['succeeded',2,'2029-10-18'],[$done->state,$state->lastWrite[1]['NumYears'],$d->fresh()->provider_expires_at->format('Y-m-d')],'Exact paid charge completes the real native invoice');same($paid->amount,$invoice->items()->sole()->price,'Original retail total stays frozen');
echo "Native captured-charge observation and audited approval passed\n";$nativeSuccessful=true;
