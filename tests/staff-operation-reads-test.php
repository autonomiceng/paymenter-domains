<?php
// Native staff workflow boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Every holding below belongs to Enom; no second registrar enrollment is needed.
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/staff-operation-fixture.php';
// These assertions need native financial events, not PDF layout.
require __DIR__.'/Support/native-invoice-attachment.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{CataloguePreview,EnomTestReconciliationReader};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
// Native re-check calls the existing read-only reconciliation engine; actual proof alone can complete.
[$check]=$prepare(placeholderComFixtureName('staff-recheck-placeholder.com'));$calls=count($observation->calls);
$checkedPage=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$check->id])->callAction('recheck')->assertNotified('Registrar checked')
    ->assertSee('Completed')->assertSee('Registrar checked')->assertDontSee('has not confirmed')->assertDontSee('More')->assertActionHidden('recheck');
same('succeeded',$check->fresh()->state,'Reconciliation engine verifies actual charge and expiry');expect(count($observation->calls)>$calls,'Only explicit action reads');
$history=$check->fresh()->policy['staff_actions'];same('read_finished',end($history)['phase'],'Read result audited');
unset($checkedPage);gc_collect_cycles();
// Captured unknown/sent replies use the same audited staff route as accepted orders.
$preflight->cost='8.95';
[$captured]=$prepare(placeholderComFixtureName('staff-captured-placeholder.com'),'captured');
$original=$captured->attempt;
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$captured->id])->callAction('recheck')->assertNotified('Registrar checked');
same('succeeded',$captured->fresh()->state,'Staff settles captured order without another purchase');
same($original,array_diff_key($captured->fresh()->attempt,['reconciliation'=>true]),'Staff recovery preserves original unknown execution');
// The send guard refuses before a response. The page must say that and name a next step.
[$deferred]=$prepare(placeholderComFixtureName('staff-deferred-placeholder.com'),'captured');
$observation->fault='send_guard';
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$deferred->id])->callAction('recheck')
    ->assertNotified('Check delayed')->assertSee('Check delayed')->assertSee('Our sending settings paused this check.')->assertSee('The next check is at')->assertDontSee('Status checked');
same('deferred',$deferred->fresh()->policy['status_check']['outcome'],'Guard refusal is saved as deferred, never checked');
expect($deferred->fresh()->policy['status_check']['next_at']>$clock->now()->getTimestamp(),'Deferred read has an automatic next due time');
// One date format: the staff pages' own Pacific time, not a UTC clock.
$nextAt=(new DateTimeImmutable('@'.(int)$deferred->fresh()->policy['status_check']['next_at']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('M j, Y, g:i A T');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$deferred->id])->assertSee('The next check is at '.$nextAt.'.')->assertDontSee(' UTC.');
// d879bad2 retains a lost reply and the 24-hour staff answer boundary instead of claiming an unavailable read.
[$uncheckable]=$prepare(placeholderComFixtureName('staff-no-reply-placeholder.com'),'null');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$uncheckable->id])->callAction('recheck')
    ->assertNotified('Enom has not answered yet')->assertSee('Ask Enom to check this attempt.')->assertSee('You can record Enom')->assertDontSee('Status checked');
same('awaiting_answer',$uncheckable->fresh()->policy['status_check']['outcome'],'Lost reply retains the registrar answer state');
same((new \DateTimeImmutable($uncheckable->attempt['dispatch_attempted_at']))->modify('+24 hours')->getTimestamp(),$uncheckable->fresh()->policy['status_check']['answer_after'],'Staff answer waits 24 hours from this dispatch');
expect(in_array(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Info,$observation->calls,true),'A lost reply still reads supported registrar facts before asking for a case');
[$transportFailure]=$prepare(placeholderComFixtureName('staff-failed-read-placeholder.com'),'captured');
$observation->fault='transport';
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$transportFailure->id])->callAction('recheck')
    ->assertNotified('Cannot check registrar')->assertSee('Support can check the original order.');
[$lastPoll]=$prepare(placeholderComFixtureName('staff-last-read-placeholder.com'),'captured');
$policy=$lastPoll->policy;$policy['reconciliation']['polls']=4;$lastPoll->update(['policy'=>$policy]);$observation->fault='wrong-charge';
$reconcile->run($lastPoll->id);
same(null,$lastPoll->fresh()->policy['status_check']['next_at'],'Exhausted reads promise no automatic retry');
[$op,$binding]=$prepare(placeholderComFixtureName('staff-lost-read-placeholder.com'),'absent');
$clock->instant=$clock->now()->modify('+25 hours');
DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>false]);
$staff->role->update(['permissions'=>[...$all,'admin.domains.manage-policy','admin.domains.settings.enom_answer']]);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('enom_answer')->assertSee('Registrar check pending');
$before=[$op->attempt,$op->funding,$op->domain->reservation_name,$credit(),$writer->calls];
$reconcile->run($op->id);
expect(in_array(ReadCommand::Info,$observation->calls,true),'Automatic recovery reads supported registrar facts before asking for a case');
expect(!in_array(ReadCommand::Order,$observation->calls,true)&&!in_array(ReadCommand::Orders,$observation->calls,true),'Missing order identity and unaccepted history never authorize an order search');
$op->refresh();
same(['owned'=>true,'expiry'=>'2027-10-18','expiry_precision'=>'date','charge_confirmed'=>false],$op->policy['status_check']['facts'],'Partial facts are recorded without inventing an original charge');
same('awaiting_answer',$op->policy['status_check']['outcome'],'Unknown original charge still needs registrar evidence');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('enom_answer')->assertSee('The original charge is still unconfirmed.');
same('uncertain',$op->state,'An observed holding cannot settle a lost purchase');
same(null,$op->cost['actual']??null,'Unknown charge stays unknown');
same($before,[$op->attempt,$op->funding,$op->domain->fresh()->reservation_name,$credit(),$writer->calls],'Reads preserve original attempt, spending reservation and payment');
// The deployed wrapper must permit the same sealed-attempt facts, with its history gate closed.
$driver=$readBindings->reader($op->server_id);
$reader=new EnomTestReconciliationReader(new CataloguePreview($driver),$driver);
$attempt=ReconciliationEvidence::attempt($op,$op->domain->fresh(),$binding);
$context=new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock);
same(true,$reader->reconcile($attempt,$context)->result()->evidence()->domain?->owned,'Installed reader permits bounded facts through a paid-changes hold');
// A failed read records no affirmative ownership or charge.
$observation->fault='transport';$reconcile->run($op->id);
same([], $op->fresh()->policy['status_check']['facts'],'Failed reads cannot repeat prior positive evidence as current');
same(null,$op->fresh()->cost['actual']??null,'Read failure cannot settle the fee');
// Old renewal history can be outside its accepted window, but its holding remains readable.
DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>true]);
[$renew,$domain,$binding]=$makeRenewal(placeholderComFixtureName('staff-lost-renewal-placeholder.com'));
$state->lost=true;$runRenewal($renew,$binding);$renew->refresh();$state->calls=[];
$clock->instant=$clock->now()->modify('+25 hours');
DB::table('domains_accounts')->where('server_id',$renew->server_id)->update(['processing_enabled'=>false]);
$before=[$renew->attempt,$renew->funding,$domain->fresh()->provider_expires_at->format('Y-m-d'),$credit(),$state->writes];
(new \Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion($renewBindings,$clock))->run($renew->id);
expect(in_array(ReadCommand::Info,$state->calls,true),'Automatic renewal recovery reads before the support fallback');
same(false,$renew->fresh()->policy['status_check']['facts']['charge_confirmed'],'A later expiry is not proof of this renewal charge');
same($before,[$renew->fresh()->attempt,$renew->fresh()->funding,$domain->fresh()->provider_expires_at->format('Y-m-d'),$credit(),$state->writes],'Unknown renewal cost retains its original authority and money');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$renew->id])->assertActionVisible('recheck')->assertSee('Check status');

echo "Native staff-operation-reads passed.\n";
$nativeSuccessful=true;
