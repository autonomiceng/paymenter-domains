<?php
// Automatic balance holds and remaining staff releases say what will happen. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native invoices, claims and the actual Filament action must agree; reuse the existing offline fixture.
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CatalogueAcceptance,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,HistoryAcceptance,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,BalanceRetries,BalanceSchema,CatalogueAcceptances,EnomTestRegistration,EnomTestSendLimitSchema,RegistrarRegistry,DomainOperations,HistoryAcceptances,OperationClaims,RegistrationPreflight,RenewalAuthority,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
EnomTestSendLimitSchema::install();BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
// The registrar edge only: the page, service and balance row are real.
$registry=new RegistrarRegistry;
$registry->register('enom',['token'],new class implements AccountVerifier { public function verify(Environment $env,#[SensitiveParameter] array $credentials): string {return $credentials['token'];} },
    static fn(AccountBinding $b,array $c)=>new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(balance:new Money('100.00','USD')))));
app()->instance(AccountBindings::class,new AccountBindings($registry));
$account=Account::where('server_id',$tlds[0]->server_id)->sole();$binding=CatalogueAcceptances::binding($account);
$catalogue=CatalogueAcceptances::load($binding);
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$catalogue->revision,$catalogue->endings,'https://resellertest.enom.com/interface.asp',25)));
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.transfer'=>true]);
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
expect(EnomTestRegistration::enabled($binding),'Real TEST registration policy on');
$open=fn($op)=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
// Stored pre-claim checks share automatic recovery, including pause words and stale-action rejection.
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
foreach(['usd_balance_required','enom_test_transfer_usd_balance_required'] as $code){
    $op=$held(placeholderComFixtureName('truth-'.str_replace('_','-',$code).'-placeholder.com'));
    $op->update(['state'=>'held','safe_code'=>$code]);
    $open($op)->assertSee('Not sent. Orders are off for this account.')->assertDontSee('check the registrar balance first')
        ->assertDontSee('Check balance')->assertDontSee('Open account')->assertActionHidden('release');
    $summary=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($op->domain->fresh());
    expect(str_contains($summary['step']??'','Not sent. Orders are off for this account.'),'Domain list shares the paused words for '.$code);
    commerceReject(fn()=>$run($op,'release'));
    same('held',$engine->execute($op->id)->state,'Paused stored hold cannot send');
    DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
    $open($op)->assertSee('Not sent. It will retry automatically.')->assertDontSee('Open account')->assertActionHidden('release');
    commerceReject(fn()=>$run($op,'release'));
    DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
}
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
// Real preflight shortfall, automatic funding retry, retained claim, then no duplicate send.
$low=$new(placeholderComFixtureName('truth-low-placeholder.com'));
config(['domains.enom_writes.registration'=>false]);
$query=OperationClaims::query($low,$low->domain);$funds='1.00';$readers=[];$available=true;
$reader=static function(AccountBinding $b,array $c)use(&$query,$clock,&$funds,&$readers,&$available):FakeRegistrar {
    $quote=new QuoteEvidence($query,new Money('8.95','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
    $r=new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
        new Evidence(quote:$quote,balance:$funds===null?null:new Money($funds,'USD'),available:$available,premium:false)));
    $readers[]=$r;return $r;
};
$lowRegistry=new RegistrarRegistry;
$lowRegistry->register('enom',['token'],new class implements AccountVerifier {public function verify(Environment $e,#[SensitiveParameter] array $c):string{return $c['token'];}},$reader);
$lowBindings=new AccountBindings($lowRegistry);
$lowEngine=new DomainOperations($claims,new RegistrationPreflight($lowBindings,new WriteFeatures(registration:true),new WriteAcceptance($binding,true,true,25)),$writer,$clock);$writer->engine=$lowEngine;
$before=$writer->calls;$result=$lowEngine->execute($low->id);
same(['held','registrar_balance_insufficient','absent'],[$result->state,$result->safe_code,$result->attempt['seal']],'Low funds holds before the seal');
same($before,$writer->calls,'Low funds cannot send');
$open($result)->assertSee('Not sent: registrar balance is too low. Add funds.')->assertSee('It will send automatically once funds are available.')->assertActionHidden('release')->assertDontSee('Check balance');
expect(str_contains(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($result->domain->fresh())['step']??'','It will send automatically once funds are available.'),'Domain list shares automatic retry words');
commerceReject(fn()=>$run($result,'release'));
same('held',$result->fresh()->state,'A stale staff action cannot bypass automatic backoff');
$originalClaim=$result->attempt['claim_key'];$funds='100.00';
$clock->instant=$clock->now()->modify('+1 day');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
// An unrelated refusal still needs a person; automatic retry grants no blanket recovery.
$available=false;$result=$lowEngine->execute($low->id);
same(['held','name_unavailable','absent'],[$result->state,$result->safe_code,$result->attempt['seal']],'Changed availability keeps its own unsent hold');
same($before,$writer->calls,'Changed availability sends nothing');
$noAudit=clone $result;$noAudit->policy=array_diff_key($result->policy,['balance_retry'=>true,'staff_actions'=>true]);
expect(!OperationClaims::balanceRefusalRetryable($noAudit),'An unrelated unsent claim needs its original recovery path');
$wrongReason=clone $result;$wrongReason->safe_code='claim_authority_invalid';
expect(!OperationClaims::balanceRefusalRetryable($wrongReason),'Balance history cannot bypass an authority refusal');
$available=true;$funds='100.00';$clock->instant=$clock->now()->modify('+600 seconds');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$open($result)->assertActionVisible('release')->callAction('release')->assertNotified('Hold released');
// Processing can pause between runtime admission and claim validation, without stranding the claim.
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
$result=$lowEngine->execute($low->id);
same(['held','processing'],[$result->state,$result->safe_code],'Processing pause retains an unsent recovery hold');
same($before,$writer->calls,'Paused recovery cannot send');
config(['domains.enom_writes.registration'=>true]);
$open($result)->assertActionHidden('release')->assertSee('Not sent. Orders are off for this account.');
expect(str_contains(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($result->domain->fresh())['step']??'','Not sent. Orders are off for this account.'),'Domain list shares the paused retry words');
$clock->instant=$clock->now()->modify('+600 seconds');
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$open($result)->assertActionHidden('release')->assertSee('Not sent. Orders are off for this account.');
$clock->instant=$clock->now()->modify('+1800 seconds');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
config(['domains.enom_writes.registration'=>false]);
$result=$lowEngine->execute($low->id);
same($originalClaim,$result->attempt['claim_key'],'Balance recovery preserves the original unsent claim');
same(['availability','quote'],end($readers)->calls,'Funded retry runs fresh availability and price after its first balance read');
same($before+1,$writer->calls,'Funded readmission sends once');
$lowEngine->execute($low->id);same($before+1,$writer->calls,'Repeated execution never sends twice');
expect(!OperationClaims::balanceRefusalRetryable($result),'Sealed or uncertain outcome cannot enter balance recovery');
// A send recovered a day later must still reconcile its own order, charge and billing anchor.
HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_registration_v1'));
$observation->name=$low->domain->domain;$observation->billDate=$clock->now()->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('m/d/Y g:i:s A');$observation->calls=[];
$clock->instant=$clock->now()->modify('+301 seconds');
$result=$reconcile->run($low->id);
same('succeeded',$result->state,'One-day balance recovery completes through original-attempt reconciliation');
same('8.95',$result->cost['actual']['amount'],'Recovered purchase retains its proven charge');
expect(RenewalAuthority::completed($result,$result->domain,$binding),'Recovered purchase has a valid billing anchor');
same($originalClaim,$result->attempt['claim_key'],'Completion keeps the original claim identity');
$lowEngine->execute($low->id);same($before+1,$writer->calls,'Completion and duplicate execution never resend');
$foreign=clone $result;$policy=$foreign->policy;$policy['balance_retry']['generation']++;
$foreign->policy=$policy;
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($foreign,$foreign->domain,$binding));
$outside=clone $result;$policy=$outside->policy;
$policy['balance_retry']['window_at']=(new DateTimeImmutable($result->attempt['dispatch_attempted_at']))->modify('+1 second')->format(DATE_ATOM);
$outside->policy=$policy;
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($outside,$outside->domain,$binding));
// Changed proof stops sending; scheduled local checks resume only after the exact original proof matches.
app()->instance(OperationClaims::class,$claims);
config(['domains.enom_writes.transfer'=>false]); // No built-in runtime purchase composition in this fixture.
$reviewWork=new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts);
foreach(['payment','missing_claim','authority','legacy_authority','configuration','setup'] as $fault){
    $clock->instant=$clock->now()->modify('+61 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    config(['domains.enom_writes.registration'=>true]);$changed=$new(placeholderComFixtureName('retry-'.str_replace('_','-',$fault).'-placeholder.com'));
    config(['domains.enom_writes.registration'=>false]);$funds='1.00';$available=true;$query=OperationClaims::query($changed,$changed->domain);
    // Rebind the registrar edge's query for this independent native order.
    $changeRegistry=new RegistrarRegistry;
    $changeRegistry->register('enom',['token'],new class implements AccountVerifier {public function verify(Environment $e,#[SensitiveParameter] array $c):string{return $c['token'];}},
        static fn($b,$c)=>new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,
            new Evidence(quote:new QuoteEvidence($query,new Money('8.95','USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds')),balance:new Money('1.00','USD'),available:true,premium:false))));
    $changeEngine=new DomainOperations($claims,new RegistrationPreflight(new AccountBindings($changeRegistry),new WriteFeatures(registration:true),new WriteAcceptance($binding,true,true,25)),$writer,$clock);
    if($fault==='legacy_authority'){[$claimed]=$claims->claim((int)$changed->id);$changed=$claims->finish($claimed);$changed->update(['safe_code'=>'usd_balance_required']);}
    else{$changed=$changeEngine->execute($changed->id);}
    $saved=$changed->funding;$savedPayload=$changed->payload;$before=$writer->calls;
    if($fault==='payment'){App\Models\InvoiceTransaction::create(['invoice_id'=>$changed->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);}
    if($fault==='missing_claim'){$f=$saved;unset($f['claim']);DB::table('domains_operations')->where('id',$changed->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($f))]);}
    if(in_array($fault,['authority','legacy_authority'],true)){DB::table('domains_domains')->where('id',$changed->domain_id)->update(['billing_managed'=>false]);}
    if($fault==='configuration'){App\Models\Server::whereKey($changed->server_id)->update(['enabled'=>false]);}
    if($fault==='setup'){\Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$changed->update(['payload'=>[...$savedPayload,'setup_required'=>true]]));}
    $changed=$changed->fresh();
    expect(!\Paymenter\Extensions\Servers\Domains\Services\BalanceRetries::candidate($changed),'Changed '.$fault.' proof is not an automatic-send candidate');
    $clock->instant=$clock->now()->modify('+900 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $reads=count(FakeRegistrar::$readClaims);$reviewWork->operation((int)$changed->id);$changed=$changed->fresh();
    $code=match($fault){'payment','missing_claim'=>'balance_retry_funding_changed','authority','legacy_authority','configuration'=>'balance_retry_authority_changed','setup'=>'balance_retry_setup_changed'};
    same($code,$changed->safe_code,'Changed '.$fault.' has an actionable hold');
    $retryDue=(int)DB::table('domains_runtime_operations')->where('operation_id',$changed->id)->value('due_at');
    expect($retryDue>$clock->now()->getTimestamp()&&$retryDue<=$clock->now()->getTimestamp()+21600,'Refused proof has a bounded later local check');
    $reviewWork->operation((int)$changed->id);
    same($reads,count(FakeRegistrar::$readClaims),'Diagnosing or checking refused proof performs no provider reads');
    same($before,$writer->calls,'Changed proof sends nothing');
    if($fault!=='missing_claim'){same($saved,$changed->funding,'Review never replaces original funding proof');}
    Illuminate\Support\Facades\Auth::setUser($staff);$notice=RequestFacts::notice($changed);expect(!str_contains(json_encode($notice),'automatically'),'Review never promises an automatic send');
    $open($changed)->assertActionHidden('release')->assertActionVisible('refund')->assertDontSee('It will send automatically');
    if($fault==='payment'||$fault==='missing_claim'){
        same('invoice',$notice['link'],'Payment review opens the invoice');
        $refunds=app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class);$balanceBefore=$credit();
        $refunds->refundHeld($staff,(int)$changed->id,'changed_payment_proof');
        same('rejected',$changed->fresh()->state,'Changed payment can close through the existing safe refund');
        same((float)$balanceBefore+(float)$changed->amount,(float)$credit(),'Changed payment adds the paid order credit once');
        $refunds->creditRejected((int)$changed->id);same((float)$balanceBefore+(float)$changed->amount,(float)$credit(),'Repeated credit cannot pay twice');
    }else{
        if(in_array($fault,['authority','legacy_authority'],true)){DB::table('domains_domains')->where('id',$changed->domain_id)->update(['billing_managed'=>true]);}
        elseif($fault==='configuration'){App\Models\Server::whereKey($changed->server_id)->update(['enabled'=>true]);}
        else{\Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$changed->update(['payload'=>$savedPayload]));}
        $open($changed->fresh())->assertActionHidden('release')->assertSee('Not sent. It will retry automatically.');
        $reviewWork->operation((int)$changed->id);same('held',$changed->fresh()->state,'Repair does not skip the local check backoff');
        $clock->instant=$clock->now()->setTimestamp($retryDue);Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
        Illuminate\Support\Facades\Queue::fake();$reviewWork->tickOperations();
        $job=Illuminate\Support\Facades\Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->first(fn($job)=>$job->operationId===$changed->id);
        expect($job!==null,'Repaired claimed hold enters scheduled work without a staff action');
        $job->handle($reviewWork);$restored=$changed->fresh();
        same('queued',$restored->state,'Exact restored '.$fault.' proof readmits automatically: '.$restored->state.'/'.$restored->safe_code);
        same($reads,count(FakeRegistrar::$readClaims),'Local readmission needs no provider reads');
        // This fixture has no accepted runtime ports; the normal executor must still freshly check funds.
        $retried=$changeEngine->execute((int)$changed->id);same('registrar_balance_insufficient',$retried->safe_code,'Restored proof gets a validated fresh preflight window');
        same($saved['claim'],$retried->funding['claim'],'Recovery retains the original funding claim');
        same($changed->policy['staff_actions']??[],$retried->policy['staff_actions']??[],'Automatic repair invents no staff event');
    }
}
// A pre-claim row has no old financial claim to replace: repair of current funding needs no staff release.
config(['domains.enom_writes.registration'=>true]);$unclaimed=$new(placeholderComFixtureName('retry-preclaim-payment-placeholder.com'));
config(['domains.enom_writes.registration'=>false]);$unclaimed->update(['state'=>'held','safe_code'=>'usd_balance_required']);
App\Models\Invoice::whereKey($unclaimed->invoice_id)->update(['status'=>'pending']);
expect(!\Paymenter\Extensions\Servers\Domains\Services\BalanceRetries::candidate($unclaimed->fresh()),'Unpaid pre-claim row never promises an automatic send');
$claims->readmitBalance((int)$unclaimed->id);
expect((int)DB::table('domains_runtime_operations')->where('operation_id',$unclaimed->id)->value('due_at')>$clock->now()->getTimestamp(),'Unpaid pre-claim retry defers instead of remaining overdue');
Illuminate\Support\Facades\Auth::setUser($staff);$notice=RequestFacts::notice($unclaimed->fresh());
same('invoice',$notice['link'],'Pre-claim payment issue opens the invoice');expect(!str_contains(json_encode($notice),'automatically'),'Unpaid pre-claim notice names the payment issue');
App\Models\Invoice::whereKey($unclaimed->invoice_id)->update(['status'=>'paid']);
$clock->instant=$clock->now()->modify('+900 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
expect($claims->readmitBalance((int)$unclaimed->id),'Restored current funding readmits the unclaimed row without a click');
same('queued',$unclaimed->fresh()->state,'Pre-claim repair returns to the existing queue');
// A retry that is coming hides Release; once retries stop, the page names the real problem and Release works.
$step=static function()use($clock):void {$clock->instant=$clock->now()->modify('+21600 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());};
$step();config(['domains.enom_writes.registration'=>true]);$failed=$new(placeholderComFixtureName('truth-read-placeholder.com'));config(['domains.enom_writes.registration'=>false]);
$query=OperationClaims::query($failed,$failed->domain);$funds='100.00';$available=null;$before=$writer->calls;
$result=$lowEngine->execute($failed->id);$claim=$result->attempt['claim_key'];$paid=$result->funding['claim'];
same(['held','registrar_read_failed','absent'],[$result->state,$result->safe_code,$result->attempt['seal']],'A failed read is proven unsent');
$open($result)->assertSee("Not sent: couldn't read from the registrar. It will retry automatically.")->assertActionHidden('release');
commerceReject(fn()=>$run($result,'release'));
foreach(range(2,BalanceRetries::READ_LIMIT) as $i){$step();$result=$lowEngine->execute($failed->id);}
same(['held','registrar_read_failed',BalanceRetries::READ_LIMIT],[$result->state,$result->safe_code,$result->policy['read_retries']],'Retries stop at the limit');
same($before,$writer->calls,'Retried reads never send');
$open($result)->assertSee("Not sent: couldn't read from the registrar")->assertDontSee('It will retry automatically')->assertSee(BalanceRetries::READ_STOPPED)->assertActionVisible('release');
$step();$available=true;$open($result)->callAction('release')->assertNotified('Hold released');
$result=$lowEngine->execute($failed->id);
expect($before!==$writer->calls,'Release after exhausted retries sends');
same([$claim,$paid],[$result->attempt['claim_key'],$result->funding['claim']],'Release keeps the original claim audit');
// A stored check may be admitted before an acceptance gate closes; it still needs no purchase claim to cancel.
config(['domains.enom_writes.registration'=>true]);$legacy=$new(placeholderComFixtureName('retry-preclaim-refund-placeholder.com'));
config(['domains.enom_writes.registration'=>false]);$legacy->update(['state'=>'held','safe_code'=>'usd_balance_required']);
same(false,$claims->readmitBalance((int)$legacy->id),'Stored pre-claim check gets the normal backoff');
$clock->instant=$clock->now()->modify('+900 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
// This is the real pre-claim deployment gate, not a synthetic terminal row.
config(['domains.enom_writes.registration'=>true]);
$current=CatalogueAcceptances::load($binding);$endings=$current->endings;$endings['com']['register']=[3];
DB::transaction(static fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$current->revision,$endings,'https://resellertest.enom.com/interface.asp',25)));
$legacy=$lowEngine->execute((int)$legacy->id);
same(['held','order_not_supported',null,[]],[$legacy->state,$legacy->safe_code,$legacy->started_at,$legacy->attempt??[]],'Acceptance gate stops before a claim');
$retry=$legacy->policy['balance_retry'];same(null,$retry['claim_key'],'Retained retry has no purchase claim');
$beforeCredit=$credit();$beforeSend=$writer->calls;$refunds=app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class);
$refunds->refundHeld($staff,(int)$legacy->id,'acceptance_unavailable');$legacy=$legacy->fresh();
same('rejected',$legacy->state,'Pre-claim retry can close without enabling purchases');
expect(\Paymenter\Extensions\Servers\Domains\Services\OperationTerminalProof::safe($legacy,$legacy->domain),'Existing terminal unsent proof remains valid');
same($retry,$legacy->policy['balance_retry'],'Closing retains immutable retry history');
same((float)$beforeCredit+(float)$legacy->amount,(float)$credit(),'Pre-claim cancellation credits the paid order');
$refunds->creditRejected((int)$legacy->id);same((float)$beforeCredit+(float)$legacy->amount,(float)$credit(),'Pre-claim credit is idempotent');
$lowEngine->execute((int)$legacy->id);same($beforeSend,$writer->calls,'Closed retry cannot send');
$foreign=clone $legacy;$attempt=$foreign->attempt;$attempt['terminal']['generation']++;$foreign->attempt=$attempt;
commerceReject(fn()=>$foreign->save());
$dirty=clone $legacy;$dirty->cost=[...$dirty->cost,'actual'=>null];commerceReject(fn()=>$dirty->save());
echo "Automatic balance recovery, manual acceptance recovery and shared reconciliation windows passed.\n";
$nativeSuccessful=true;
