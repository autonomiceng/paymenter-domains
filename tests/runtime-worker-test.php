<?php
// Installed fake composition, payment dispatch, fair recovery and paired renewal mail.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/runtime-presenter-test.php';$nativeSuccessful=false;require __DIR__.'/Support/reminder-mail.php';require __DIR__.'/Support/FakeWriter.php';
use Illuminate\Support\Facades\{DB,Mail,Queue};
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimeWork,RuntimePorts,OperationClaims,OperationReconciliation,DomainOperations,DomainManagement,RenewalAuthority,RenewalAcceptance,RenewalAdmission,RenewalExecution,RenewalWriter,RenewalCompletion,WriteFeatures,CatalogueAcceptances,RegistrarRegistry,AccountBindings,ReminderSchedules,Reminders,DomainBilling,StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Data\{Capabilities,Capability,Support,DispatchReadiness,RequestContext,RequestOrigin,RequestBudget,Money,DriverResult,Disposition,SendState,ResultReason,Evidence,RegistrationEvidence,DateEvidence,ExpiryPrecision,DomainSnapshot,OrderEvidence};
use Paymenter\Extensions\Servers\Domains\Contracts\{OperationPreflight,AccountVerifier};
use Paymenter\Extensions\Servers\Domains\Tests\{FakeRegistrar,FakeWriter};
$ports=app(RuntimePorts::class);$claims=new OperationClaims($bindings,$clock);
$preflight=new class($clock) implements OperationPreflight {
    public function __construct(private $clock){}
    public function inspect(Operation $op,Domain $d,RequestContext $c): DispatchReadiness {return new DispatchReadiness($c->binding,OperationClaims::query($op,$d),new Capabilities($c->binding,['register'=>new Capability(Support::Supported,[1])]),new Money('6.00','USD'),true,false,$this->clock->now(),$this->clock->now()->modify('+120 seconds'),OperationClaims::payloadDigest($op));}
};
$sent=[];$mailPairs=[];$expiryDebts=[];
foreach(Domain::orderBy('id')->get() as $i=>$d){
    $clock->instant=$now;$a=Account::where('server_id',$d->server_id)->sole();$b=CatalogueAcceptances::binding($a);$op=$d->operations()->sole();$op->update(['state'=>'awaiting_payment']);
    $registry=new RegistrarRegistry;$verify=new class implements AccountVerifier {public function verify($e,array $c):string{return $c['token'];}};
    $query=OperationClaims::query($op,$d);$expiry=new DateEvidence('2027-10-18',ExpiryPrecision::Date,'synthetic');
    $facts=new Evidence(domain:new DomainSnapshot($b,$d->domain,true,$expiry,false,false,true,'456',true),candidates:[new OrderEvidence($b,$query,new Money('6.00','USD'),'9001',new DateEvidence('2026-10-18T12:00:01+00:00',ExpiryPrecision::Instant,'synthetic','UTC'),$expiry)]);
    $registry->register($b->registrarKey,['token'],$verify,fn($binding,$credentials)=>new FakeRegistrar($binding,new Capabilities($binding,[]),new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,$facts)));
    $reader=new AccountBindings($registry);$reconcile=new OperationReconciliation($claims,$reader,$clock);$features=new WriteFeatures(true,true,true,true);
    $writer=new FakeWriter($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Unknown,SendState::PossiblySent,ResultReason::Unresolved));$engine=new DomainOperations($claims,$preflight,$writer,$clock);
    $authority=new RenewalAuthority($bindings,$clock);$accept=new RenewalAcceptance($b,true,true,25);$renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$accept,$clock),$accept,$features,$clock);
    $protocolClass='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($b->registrarKey==='enom'?'Enom':'NameSilo').'\\Management';$protocol=new $protocolClass($b,static fn()=>throw new LogicException('No management transport in lifecycle acceptance'));
    $noExchange=static fn()=>throw new LogicException('No acquisition exchange in reconciliation fixture');
    $profiles=new \Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles($b,$claims,$clock,$noExchange);
    $writeAccept=new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($b,true,true,25,$profiles);
    $namespace='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($b->registrarKey==='enom'?'Enom':'NameSilo').'\\';
    $registration=$b->registrarKey==='enom'?new ($namespace.'Registration')($b,$noExchange):new ($namespace.'Registration')($b,$profiles,$noExchange);
    $transfer=$b->registrarKey==='enom'?new ($namespace.'Transfer')($b,$noExchange):new ($namespace.'Transfer')($b,$profiles,$noExchange);
    $composition=\Paymenter\Extensions\Servers\Domains\Services\RuntimeComposition::make($b,$bindings,$clock,$features,$writeAccept,$accept,
        new \Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck(new \Paymenter\Extensions\Servers\Domains\Services\Rdap($noExchange)),
        $registration,$transfer,$noExchange,$protocol,true,$profiles);
    $composition['reconciliation']=$reconcile;
    $ports->register($b->fingerprint,fn()=>$composition);expect($ports->accepted($a),'Complete synthetic composition accepted');same($composition['management'],Runtime::management($d,null),'Owner actions use accepted current engine');
    $ports->operation($op);same('awaiting_payment',$op->fresh()->state,'Unpaid job cannot permanently hold acquisition');
    $settingWriter=new StaffSettingsWrites($native,$sellers,$bindings);$bindings->setProcessing($a->server_id,false);
    config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);
    $processingReview=static function()use($staff,$a):Review{$stamp=Review::stamp($a->server_id,null);return new Review($staff->id,$a->server_id,null,'processing',Review::ticket($staff->id,$a->server_id,null,'processing',$stamp),'Reject unsupported runtime settings');};
    foreach([
        ['cache.default'=>'array'],['session.driver'=>'array'],['queue.default'=>'sync'],['queue.connections.database.retry_after'=>899],
    ] as $unsupported){
        config($unsupported);$badReview=$processingReview();
        try{$settingWriter->run($badReview,['enabled'=>true,'confirmed'=>true]);expect(false,'Unsupported runtime acceptance must refuse');}
        catch(DomainException $error){same('Runtime acceptance and shared workers are required.',$error->getMessage(),'Unsupported runtime refusal reaches its intended guard');}
        same(false,$a->fresh()->processing_enabled,'Unsupported shared worker setting keeps processing off');
        config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database','queue.connections.database.retry_after'=>900]);
    }
    $review=new Review($staff->id,$a->server_id,null,'processing',Review::ticket($staff->id,$a->server_id,null,'processing',Review::stamp($a->server_id,null)),'Review synthetic runtime acceptance');
    $settingWriter->run($review,['enabled'=>true,'confirmed'=>true]);$sameState=new Review($staff->id,$a->server_id,null,'processing',Review::ticket($staff->id,$a->server_id,null,'processing',Review::stamp($a->server_id,null)),'Reject duplicate processing change');
    try{$settingWriter->run($sameState,['enabled'=>true,'confirmed'=>true]);expect(false,'Unchanged processing state must refuse');}
    catch(DomainException $error){same('Processing state unchanged.',$error->getMessage(),'Duplicate processing request reaches the state check');}
    expect($a->fresh()->processing_enabled,'Confirmed accepted activation succeeds');config(['cache.default'=>'array','session.driver'=>'array','queue.default'=>'sync']);
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
    $bindings->setProcessing($a->server_id,false);app(RuntimeWork::class)->operation($op->id);same('awaiting_payment',$op->fresh()->state,'Paused processing never executes paid queued acquisition');$bindings->setProcessing($a->server_id,true,static fn()=>null);
    $claimResult=$claims->claim($op->id);expect($claimResult!==null,'Installed claim available: '.$op->fresh()->state.' / '.$op->fresh()->safe_code);[$claimed,$domain]=$claimResult;$ctx=new RequestContext($b,RequestOrigin::Background,$now->modify('+300 seconds'),new RequestBudget(10),$clock);
    $sealed=$claims->seal($claimed,$preflight->inspect($claimed,$domain,$ctx),$clock->now());expect($sealed!==null,'Installed acquisition seals with real native funding');
    $claims->finish($sealed,new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(registration:new RegistrationEvidence($query,'9001',$expiry,null,[]))));
    $bindings->setProcessing($a->server_id,false);app(RuntimeWork::class)->operation($op->id);same('succeeded',$op->fresh()->state,'Paused runtime reconciliation completes original fake order');$bindings->setProcessing($a->server_id,true,static fn()=>null);$d->refresh();
    ReminderSchedules::enroll($a->server_id);$schedule=DB::table('domains_reminder_schedules')->where('server_id',$a->server_id)->sole();ReminderSchedules::save($staff,$a->server_id,$schedule->revision,true,'60,30,7,1','3','Review installed reminders');
    App\Models\Server::find($a->server_id)->settings()->create(['key'=>'domains_manual_renewals_enabled','type'=>'boolean','value'=>true]);$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
    $d->service->product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['type'=>'boolean','value'=>true]);
    $invoice=app(DomainBilling::class)->invoice($d->id,$i===0?$user:null);$expiryDebts[]=[$d,$invoice];$renew=Operation::where('invoice_id',$invoice->id)->sole();
    $dto=$presenter->service($user,$d->service_id);same('2027-10-11',$dto->renewalDue,'Renewal payment date separate from expiry');same('2027-10-18',$dto->expiry,'Confirmed expiry unchanged');expect(isset($presenter->invoice($user,$invoice->id)[$renew->invoice_item_id]),'Retained renewal line uses invoice binding');
    $invoiceLine=static fn($debt,$itemId)=>$presenter->invoice($user,$debt->id)[$itemId]??throw new RuntimeException('Expected bound invoice line');
    same('2027-10-11',$invoiceLine($invoice,$renew->invoice_item_id)->renewalDue,'Pending current renewal line shows its due date');
    $registrationInvoice=App\Models\Invoice::findOrFail($op->invoice_id);
    same(null,$invoiceLine($registrationInvoice,$op->invoice_item_id)->renewalDue,'Paid registration line hides a later renewal debt');
    $registrationHtml=view('domains::invoice-line',['domain'=>$invoiceLine($registrationInvoice,$op->invoice_item_id)])->render();
    expect(!str_contains($registrationHtml,'Renewal payment due'),'Registration invoice never renders the renewal payment label');
    foreach(['paid','cancelled']as$status){
        DB::table('invoices')->where('id',$invoice->id)->update(['status'=>$status]);
        same(null,$invoiceLine($invoice,$renew->invoice_item_id)->renewalDue,'Nonpending renewal line hides payment due: '.$status);
    }
    DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
    $oldInvoice=$invoice->getRawOriginal();unset($oldInvoice['id']);$oldInvoice['number']=null;
    $oldInvoiceId=DB::table('invoices')->insertGetId($oldInvoice);
    $oldItem=(array)DB::table('invoice_items')->where('id',$renew->invoice_item_id)->sole();unset($oldItem['id']);$oldItem['invoice_id']=$oldInvoiceId;
    $oldItemId=DB::table('invoice_items')->insertGetId($oldItem);
    $oldDebt=$renew->getRawOriginal();unset($oldDebt['id']);$oldDebt['invoice_id']=$oldInvoiceId;$oldDebt['invoice_item_id']=$oldItemId;$oldDebt['operation_key']='retained-other-cycle-'.$i;
    $oldDebtId=DB::table('domains_operations')->insertGetId($oldDebt);
    $old=App\Models\Invoice::findOrFail($oldInvoiceId);
    same(null,$invoiceLine($old,$oldItemId)->renewalDue,'Another pending invoice with the same due date is not the current cycle');
    same('2027-10-11',$invoiceLine($invoice,$renew->invoice_item_id)->renewalDue,'Other retained debt does not hide the current pending line');
    DB::table('domains_operations')->where('id',$oldDebtId)->delete();DB::table('invoice_items')->where('id',$oldItemId)->delete();DB::table('invoices')->where('id',$oldInvoiceId)->delete();
    $clock->instant=new DateTimeImmutable('2027-10-11T12:00:00Z');expect(app(Reminders::class)->remind($d->id),'Installed issued-invoice notice sends');$mail=Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last();$mailPairs[$i][]=str_replace([$d->domain,route('invoices.show',$invoice),url('/domains/'.$d->id)],['example.com','INVOICE_URL','DOMAIN_URL'],$mail->render());
    $clock->instant=new DateTimeImmutable('2027-10-21T12:00:00Z');expect(app(Reminders::class)->remind($d->id),'Installed expired notice sends');$mail=Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last();$mailPairs[$i][]=str_replace([$d->domain,url('/domains/'.$d->id)],['example.com','DOMAIN_URL'],$mail->render());
}
same($mailPairs[0],$mailPairs[1],'Actual installed holder planners produce identical normalized renewal/expired emails');
Queue::fake();$clock->instant=$now;
$balances=app(\Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances::class);$balanceOrder=[];
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances::class,new class($balanceOrder) {
    public function __construct(private array &$seen){}
    public function daily($account): void {
        Queue::assertPushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class);
        same(2,DB::table('domains_runtime_turns')->whereNotNull('finished_at')->count(),'Every account dispatch finishes before a balance read');
        $this->seen[]=(int)$account->server_id;
        if(count($this->seen)===1){throw new RuntimeException('PRIVATE-balance-detail');}
    }
});
try{app(RuntimeWork::class)->tick();same(2,count(array_unique($balanceOrder)),'A failed first balance does not block the next account');}
finally{app()->instance(\Paymenter\Extensions\Servers\Domains\Services\RegistrarBalances::class,$balances);}
$turns=DB::table('domains_runtime_turns')->orderBy('turn')->get();same(2,$turns->count(),'Every account enrolled');Queue::assertPushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class);expect($turns[0]->turn>0&&$turns[1]->turn>0,'Independent durable turns');
$queued=Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count();foreach(Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class) as $job){app(\Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->enqueue($job->operationId);}same($queued,Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class)->count(),'Durable operation backoff suppresses repeated dispatch');
$before=$turns->pluck('turn')->all();app(RuntimeWork::class)->tick();same($before,DB::table('domains_runtime_turns')->orderBy('turn')->pluck('turn')->all(),'No early repeated account turn');
$clock->instant=$now->modify('+61 seconds');app(RuntimeWork::class)->tick();expect(DB::table('domains_runtime_turns')->min('turn')>max($before),'Interrupted/due turns continue rotation');
// Event-suppressed maintenance has no deleted event; the existing durable cursor detects its orphan.
$orphan=Domain::create(['user_id'=>$user->id,'service_id'=>987654,'domain'=>'sweep-orphan-placeholder.com','sld'=>'sweep-orphan-placeholder','tld'=>'com',
    'server_id'=>$tlds[0]->server_id,'registrar_key'=>'enom','account_fingerprint'=>$tlds[0]->account_fingerprint,'status'=>'registration_pending']);
$clock->instant=$now->modify('+122 seconds');app(RuntimeWork::class)->tick();
expect(DB::table('domains_obligation_alerts')->where('domain_id',$orphan->id)->where('code','native_identity_missing')->whereNull('resolved_at')->exists(),'Durable turn records missed deletion discrepancy');
$clock->instant=$now->modify('+2 hours');$b=CatalogueAcceptances::binding(Account::where('server_id',$tlds[0]->server_id)->sole());$limiter=app(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class);
for($n=0;$n<48;$n++){$token=$limiter->acquire($b,RequestOrigin::Background);$limiter->release($b,RequestOrigin::Background,$token);}
try{$limiter->acquire($b,RequestOrigin::Background);expect(false,'Background headroom enforced');}catch(\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
for($n=0;$n<12;$n++){$token=$limiter->acquire($b,RequestOrigin::Interactive);$limiter->release($b,RequestOrigin::Interactive,$token);}
try{$limiter->acquire($b,RequestOrigin::Interactive);expect(false,'Shared ceiling enforced');}catch(\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
// The existing domain cursor cancels both manual and scheduled debts, even when renewal is Off.
foreach($expiryDebts as [$holding,$debt]){$holding->update(['renewal_policy'=>[...($holding->renewal_policy??[]),'owner_auto_renew'=>false]]);}
// The same sweep closes an early renewal nobody paid. Its billing window here opens after this tick.
[,$earlyDebt]=array_pop($expiryDebts);$earlyRenewal=Operation::where('invoice_id',$earlyDebt->id)->sole();
DB::table('domains_operations')->where('id',$earlyRenewal->id)->update(['policy'=>encrypt(json_encode([...$earlyRenewal->policy,'calendar'=>[...$earlyRenewal->policy['calendar'],'issue'=>'2027-12-01']]),false)]);
$clock->instant=new DateTimeImmutable('2027-10-17T23:58:00Z');app(RuntimeWork::class)->tick();
foreach($expiryDebts as [$holding,$debt]){same('pending',$debt->fresh()->status,'Scheduled cleanup waits until expiry');}
same(['cancelled','Early renewal not paid'],[$earlyDebt->fresh()->status,$earlyRenewal->fresh()->policy['unpaid_retirement']['reason']??null],'Scheduled work closes the early renewal');
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');app(RuntimeWork::class)->tick();
foreach($expiryDebts as [$holding,$debt]){
    same('cancelled',$debt->fresh()->status,'Existing scheduled work cancels expired renewal debt');
    $retired=Operation::where('invoice_id',$debt->id)->sole();same('renewal_cancelled_unpaid',$retired->safe_code,'Scheduled work retains terminal proof');
    same('Domain expired',$retired->policy['unpaid_retirement']['reason'],'Scheduled cancellation is audited');
}
$closed=serialize(array_map(fn($pair)=>[$pair[1]->fresh()->getRawOriginal(),Operation::where('invoice_id',$pair[1]->id)->sole()->getRawOriginal()],$expiryDebts));
$clock->instant=new DateTimeImmutable('2027-10-18T00:02:00Z');app(RuntimeWork::class)->tick();
same($closed,serialize(array_map(fn($pair)=>[$pair[1]->fresh()->getRawOriginal(),Operation::where('invoice_id',$pair[1]->id)->sole()->getRawOriginal()],$expiryDebts)),'Repeated scheduled cancel is a no-op');
echo "Installed activation, original-order reconciliation, paired renewal/expired notices and durable worker rotation passed.\n";$nativeSuccessful=true;
