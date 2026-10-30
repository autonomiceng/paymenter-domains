<?php
// Staff know what Release hold will do before they press it. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CatalogueAcceptance,Capabilities,Disposition,DriverResult,Environment,Evidence,Money,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceReads,BalanceSchema,CatalogueAcceptances,EnomTestRegistration,EnomTestSendLimitSchema,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
EnomTestSendLimitSchema::install();BalanceSchema::install();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
// The registrar edge only: the page, service and balance row are real.
$registry=new RegistrarRegistry;
$registry->register('enom',['token'],new class implements AccountVerifier { public function verify(Environment $env,#[SensitiveParameter] array $credentials): string {return $credentials['token'];} },
    static fn(AccountBinding $b,array $c)=>new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(balance:new Money('100.00','USD')))));
app()->instance(AccountBindings::class,new AccountBindings($registry));
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=CatalogueAcceptances::binding($account);
$catalogue=CatalogueAcceptances::load($binding);
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$catalogue->revision,$catalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
expect(EnomTestRegistration::enabled($binding),'Real TEST registration policy on');
$open=fn($op)=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
$aged=static function(int $seconds)use($tlds,$clock):void {
    $at=$clock->now()->getTimestamp()-$seconds;
    DB::table(BalanceSchema::TABLE)->where("server_id",$tlds[0]->server_id)->update(['read_at'=>$at,'attempted_at'=>$at]);
};
// No fresh balance: the page says so, offers Check balance, and does not offer a release that would bounce.
$op=$held(placeholderComFixtureName('truth-balance-placeholder.com'));
BalanceReads::record($binding,new Money('100.00','USD'),$clock->now(),'manual');$aged(3700);
$page=$open($op)->assertActionHidden('release')->assertActionVisible('check_balance')
    ->assertSee('Needs a balance check under an hour old')->assertSee('Check balance');
same(null,(function()use($run,$op){try{$run($op,'release');}catch(DomainException){return null;}return 'accepted';})(),'The writer refuses the same release');
same('held',$op->fresh()->state,'Nothing changed');
// Check balance runs on this page and the next step shows right away.
$page->callAction('check_balance')->assertNotified('Balance updated')->assertActionVisible('release')
    ->assertSee('Release hold sends the test purchase to Enom');
// Fresh balance: the button says it sends now, and nothing moves until the confirm.
$page->mountAction('release')->assertActionMounted('release');
same('held',$op->fresh()->state,'Opening the confirm changes nothing');
same(['Send to Enom now?',"Sends the test purchase to Enom within a few minutes. This can't be taken back."],
    [$page->instance()->getMountedAction()->getModalHeading(),$page->instance()->getMountedAction()->getModalDescription()],'The confirm says it sends now');
$page->callMountedAction()->assertNotified('Hold released')->assertSee('In progress');
same('queued',$op->fresh()->state,'Confirm queues the request');
// The balance goes stale before the worker runs: the hold comes back and history says why.
$aged(3700);$engine->execute($op->id);
same(['held','usd_balance_required'],[$op->fresh()->state,$op->fresh()->safe_code],'The worker puts it back on hold');
same('Back on hold',array_column(StaffReviewHistory::rows($op->fresh()),'event')[1]??null,'History shows the return');
$open($op->fresh())->assertSee('Back on hold')->assertSee('Not sent: check the registrar balance first')->assertSee('System');
// A fresh read clears only the age hold: a low balance keeps its reason and its Check balance.
$low=$held(placeholderComFixtureName('truth-low-placeholder.com'));$aged(60);$low->update(['safe_code'=>'funding']);
$open($low->fresh())->assertSee('Not sent: low registrar balance')->assertDontSee('Balance is up to date')->assertActionVisible('check_balance');
// No confirm where nothing would be sent.
$off=$held(placeholderComFixtureName('truth-off-placeholder.com'));$aged(60);
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
$open($off)->assertActionVisible('release')->assertDontSee('sends the test purchase')->callAction('release')->assertNotified('Hold released');
same('queued',$off->fresh()->state,'With processing off Release asks no confirm: nothing is sent');
echo "Release balance passed.\n";
$nativeSuccessful=true;
