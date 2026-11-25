<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Models\{Invoice, InvoiceItem, Order, Product, Server, Service, ServiceCancellation};
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\{Money, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, HistoryLink, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HoldingAnchor, HoldingLocks, RenewalAuthority, RenewalCycles, RenewalFunding, RenewalReminders, RenewalStop, RuntimeDispatch};
use Paymenter\Extensions\Servers\Domains\Holding\Adoption\AdoptionEnrollment;
// Confirmed adoption cases share one committed-fixture preparation; each case restores rows, schema, listeners,
// container, config, clocks and fake ports before the next.
extract($fixture=adoptionCommitFixture());
// Atomic adoption history, rollback, end-of-period cancellation and all renewal stops.
$cases['consequences']=static function()use($fixture):void {extract($fixture);
require dirname(__DIR__).'/Domains/routes/owned.php'; app('router')->getRoutes()->refreshNameLookups();
$server = $product->server;
foreach (['domains_billing_enabled', 'domains_manual_renewals_enabled'] as $key) { $server->settings()->updateOrCreate(['key' => $key], ['value' => true, 'type' => 'boolean']); }
$invoices = [];
foreach (['paid', 'cancelled', 'pending'] as $status) {
    $invoice = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => $status]);
    $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'reference_type' => $service->getMorphClass(), 'reference_id' => $service->id,
        'description' => 'Historical '.$status.' registration invoice', 'quantity' => 1, 'price' => '10.00']);
    $invoices[$status] = [$invoice, $item];
}
$context = new StaffContext($staff->id, 'Confirmed original customer service and historical relations', true);
same('unresolved_debt', $reader->preview($target, $context)->code, 'Dispatchable unpaid native debt blocks even with do-not-renew');
DB::table('invoice_items')->where('id', $invoices['pending'][1]->id)->delete(); DB::table('invoices')->where('id', $invoices['pending'][0]->id)->delete();
$review = $reader->preview($target, $context); same('ready', $review->code, 'Paid/cancelled exact same-owner history eligible');
$a = Adoption::findOrFail($review->previewId); $confirm = fn () => $commit->confirm($a->request_key, $a->request_digest, $staff);
DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode(array_values(array_diff($permissions, ['admin.invoices.view'])))]);
$denied = false; try { $confirm(); } catch (DomainException) { $denied = true; } expect($denied, 'History requires fresh invoice-view permission');
DB::table('roles')->where('id', $staff->role_id)->update(['permissions' => json_encode($permissions)]);
$money = scenarioMoney(); $native = $service->fresh()->getAttributes();
$vetoAt = 0; $linkAttempt = 0;
HistoryLink::creating(static function () use (&$vetoAt, &$linkAttempt) { if ($vetoAt > 0 && ++$linkAttempt === $vetoAt) { return false; } });
$failure = 'cancellation';
ServiceCancellation::created(static function () use (&$failure): void { if ($failure === 'cancellation') { $failure = ''; throw new DomainException('Injected cancellation failure'); } });
HistoryLink::creating(static function () use (&$failure): void { if ($failure === 'history') { $failure = ''; throw new DomainException('Injected history failure'); } });
foreach (['cancellation', 'history'] as $kind) {
    $failure = $kind; $refused = false; try { $confirm(); } catch (DomainException $e) { $refused = true; }
    expect($refused, 'Injected '.$kind.' failure refused');
    same(['preview', 0, 0, 0], [$a->fresh()->state, Domain::count(), ServiceCancellation::count(), HistoryLink::count()], 'Whole transaction rolled back after '.$kind.' failure');
    same($native, $service->fresh()->getAttributes(), 'Native projection rolls back after '.$kind.' failure'); same($money, scenarioMoney(), 'Failure leaves finances byte-identical');
}
foreach ([1, 2] as $veto) {
    $vetoAt = $veto; $linkAttempt = 0; $yes = false; try { $confirm(); } catch (DomainException) { $yes = true; }
    expect($yes, 'History link save veto on item '.$veto.' aborts');
    same(['preview', 0, 0, 0], [$a->fresh()->state, Domain::count(), ServiceCancellation::count(), HistoryLink::count()], 'History veto rolls all links/cancellation/holding back'); same($money, scenarioMoney(), 'History veto no financial changes');
}
$vetoAt = 0;
$lateHoldingRead=false;$cancellationOrder=[];
DB::listen(static function($query)use(&$lateHoldingRead):void{
    if(DB::transactionLevel()!==1){return;}
    $sql=strtolower($query->sql);
    if(str_starts_with($sql,'select * from "users"')){$lateHoldingRead=false;}
    if(str_starts_with($sql,'select ')&&(str_contains($sql,'from "domains_domains"')||str_contains($sql,'from "domains_adoptions"'))){$lateHoldingRead=true;}
});
App\Models\ServiceCancellation::creating(static function()use(&$cancellationOrder,&$lateHoldingRead):void{$cancellationOrder[]=$lateHoldingRead;});
$d = $confirm(); $a->refresh(); $c = ServiceCancellation::sole();
same([false],$cancellationOrder,'New holding cancellation precedes domain/adoption reads under the native prefix');
same('end_of_period', $c->type, 'Adoption uses end-of-period cancellation'); same($service->id, (int) $c->service_id, 'Cancellation is on domain service');
same($c->id, (int) $a->cancellation_id, 'Durable cancellation identity'); same('active', $service->fresh()->status, 'Display stays active'); same(null, $service->fresh()->expires_at, 'Native expiry remains null');
same(2, HistoryLink::count(), 'Paid/cancelled history links created once');
foreach (HistoryLink::all() as $link) {
    same([$d->id, $service->id, $owner->id, 'native_service', $staff->id], [(int) $link->domain_id, (int) $link->service_id, (int) $link->user_id, $link->source, (int) $link->linked_by], 'History exact identity');
    same($a->id, $link->evidence['adoption_id'], 'History retains adoption audit');
}
// A retained billing-window alert must never contradict original renewal-off intent.
staffPanel(); \Illuminate\Support\Facades\Auth::login($staff);
$d->update(['observation' => [...($d->observation ?? []), 'adoption_review' => ['inside_issue_window' => true, 'near_due' => true]]]);
$facts = \Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($d->fresh());
same(['Off', 'Set at import'], [$facts['Renewal']['value'], $facts['Renewal']['sub']], 'Renewal-off staff fact reflects original intent');
same(null, \Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($d->fresh()), 'Renewal off at import: no cutoff notice');
\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class, ['record' => $d->id])
    ->assertSee('Set at import')->assertDontSee('Imported after the invoice date');
same($money, scenarioMoney(), 'Adoption history never rewrites invoice/payment/reference/credit'); same(0, Operation::count(), 'History never fabricates operation/funding/refund candidates');
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($c));
same('active', $service->fresh()->status, 'Native end-of-period listener cannot terminate adoption'); same($money, scenarioMoney(), 'Native cancellation listener does not cancel history');
$authority = scenarioAuthority($d); $links = HistoryLink::all()->map(fn ($l) => $l->getRawOriginal())->all();
$clock->time = $clock->time->modify('+1 day'); same($d->id, $confirm()->id, 'Duplicate returns original adopted holding');
same(1, ServiceCancellation::count(), 'Duplicate reuses original cancellation'); same($links, HistoryLink::all()->map(fn ($l) => $l->getRawOriginal())->all(), 'Duplicate preserves exact links'); same($authority, scenarioAuthority($d), 'Duplicate preserves authority bytes');
$l = DB::transaction(fn () => HoldingLocks::take($d)); same('adoption', $l['identity_source']['kind'], 'DNR has structural adoption identity');
same('adoption', HoldingAnchor::resolve($l)->kind, 'DNR read-only anchor available'); expect(RenewalStop::blocksRenewal($l), 'Original DNR blocks issued as well as future authority');
$clock->time = new DateTimeImmutable('2027-09-29T00:00:00Z'); $billing = new DomainBilling($bindings, $guard, $clock,catalogue: $GLOBALS['caps']??null);
same(null, $billing->invoice($d->id), 'Scheduled adoption renewal-off creates no invoice');
$refuse = static function (callable $call, string $label): void { $yes = false; try { $call(); } catch (DomainException|LogicException $e) { $yes = true; } expect($yes, $label); };
$refuse(fn () => $billing->invoice($d->id, $owner), 'Manual renewal-off refused');
$refuse(fn () => (new RenewalCycles($bindings, $clock))->complimentary($d->id, $staff, 1, new Money('8.00', 'USD'), 'reviewed'), 'Complimentary renewal-off refused');
$alerts = []; $schedule = (object) ['before_days' => '[30]', 'after_days' => '[1]'];
$clock->time = new DateTimeImmutable('2027-09-20T00:00:00Z');
same(null, RenewalReminders::plan($l, $schedule, $clock->now(), $alerts, $bindings), 'Adopted renewal-off suppresses pre-expiry reminders');
$clock->time = new DateTimeImmutable('2027-10-21T00:00:00Z');
same(null, RenewalReminders::plan($l, $schedule, $clock->now(), $alerts, $bindings), 'Adopted renewal-off suppresses expired renewal reminders');
$ports = new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts();
$presenter = new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock, $ports);
same('Expired', $presenter->present($d, false)->status, 'Null-native-expiry adopted display becomes expired without writes');
same('active', $d->fresh()->status, 'Expired display never terminates ledger holding'); same('example.com', $d->fresh()->reservation_name, 'Expired holding retains name');
same($money, scenarioMoney(), 'Every renewal-off entry leaves all financial history unchanged'); same(0, Operation::count(), 'No manual or complimentary operation');
// A fully funded synthetic externally inserted issued row must not bypass the stop.
$issued = Invoice::create(['user_id' => $owner->id, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => '2027-10-13']);
$issued->send_create_email = false;
$line = $issued->items()->create(['reference_type' => null, 'reference_id' => null, 'description' => 'Synthetic already issued renewal', 'quantity' => 1, 'price' => '10.00']);
App\Helpers\ExtensionHelper::addPayment($issued, null, '10.00');
$money = scenarioMoney();
$op = Operation::create(['domain_id' => $d->id, 'service_id' => $d->service_id, 'server_id' => $d->server_id, 'registrar_key' => $d->registrar_key,
    'account_fingerprint' => $d->account_fingerprint, 'authority_generation' => $d->authority_generation, 'operation_key' => RenewalCycles::key($d),
    'invoice_id' => $issued->id, 'invoice_item_id' => $line->id, 'action' => 'renew', 'term' => 1, 'state' => 'queued', 'funding_basis' => 'paid_invoice', 'currency' => 'USD', 'amount' => '10.00',
    'base_expires_at' => $d->provider_expires_at, 'expected_expires_at' => $d->provider_expires_at->addYear(), 'payload' => [],
    'policy' => ['calendar' => ['due' => '2027-10-13'], 'renewal' => ['confirmed_revision' => $d->confirmed_revision, 'provider_domain_id' => $d->provider_domain_id, 'term' => 1,
        'product_id' => (int) $service->product_id, 'annual_plan_id' => (int) $service->plan_id, 'user_id' => (int) $service->user_id, 'order_id' => (int) $service->order_id]]]);
$l = DB::transaction(fn () => HoldingLocks::take($d));
$counterfactual = $l; $counterfactual['adoption'] = clone $a; $counterfactual['adoption']->do_not_renew = false; $counterfactual['cancel'] = collect();
expect(RenewalFunding::requireCurrent($op, $counterfactual)['basis'] === 'paid_invoice', 'Funded fixture has no independent funding refusal masking DNR');
try {
    RenewalFunding::requireCurrent($op, $l);
    throw new LogicException('Funded issued execution admitted a stopped renewal');
} catch (DomainException $stop) {
    $stopReason = $stop->getMessage();
}
$refuse(fn () => RenewalFunding::requireAuthority($op, $l), 'Issued funding authority respects original DNR');
$refuse(fn () => (new RenewalAuthority($bindings, $clock,offers: $GLOBALS['caps']??null))->capture(new RenewalRequest($d->id, $d->authority_generation, $d->confirmed_revision, $d->provider_expires_at->format('Y-m-d'), $d->provider_domain_id, 1, $op->operation_key)), 'Issued admission respects original DNR');
$dispatch = new RuntimeDispatch($clock); same(false, $dispatch->current($op), 'Installed renewal dispatch entry suppresses DNR');
$queued = count(Queue::pushedJobs()); $dispatch->enqueue($op->id, true); same($queued, count(Queue::pushedJobs()), 'DNR enqueue emits no job hint');
$accepted = new \Paymenter\Extensions\Servers\Domains\Services\RenewalAcceptance($binding, true, true, 25);
$features = new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(renewal: true);
$authorityPort = new RenewalAuthority($bindings, $clock,offers: $GLOBALS['caps']??null);
$admission = new \Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAdmission($authorityPort, $bindings, $accepted, $clock);
$engine = new \Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalExecution($bindings, $authorityPort, $admission, $accepted, $features, $clock);
$writes = 0; $writer = new \Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\RenewalWriter($binding, $features, $accepted, fn () => $engine, static function () use (&$writes): never { $writes++; throw new LogicException('Forbidden renewal send'); });
$calls = count($wire->calls);
same('held', $engine->run($op->id, $writer)->state, 'Direct installed executor holds funded DNR before admission');
same('renewal_funding_or_authority', $op->fresh()->safe_code, 'Executor reaches current funding/authority refusal');
same([$calls, 0], [count($wire->calls), $writes], 'No read or monetary command from DNR executor');
// Inject an exact live grant to exercise consume independently of executor admission.
$descriptor = new \Paymenter\Extensions\Servers\Domains\Data\AdmittedOperation($binding,
    new \Paymenter\Extensions\Servers\Domains\Data\Query($d->domain, \Paymenter\Extensions\Servers\Domains\Data\Action::Renew, 1),
    $op->operation_key, 'synthetic-claim', $d->authority_generation, 'synthetic-funding', str_repeat('a', 64), new Money('8.00', 'USD'),
    new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($d->provider_expires_at->format('Y-m-d'), \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'renewal_base'));
$ctx = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding, \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,
    $clock->now()->modify('+60 seconds'), new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1), $clock);
$grantMap = (new ReflectionProperty($engine, 'grants'))->getValue($engine);
$grantMap[$descriptor] = ['context' => $ctx, 'stamp' => $l['stamp'], 'id' => $op->id,
    'revision' => $op->fresh()->transition_revision, 'mode' => 'ordinary', 'writer' => $writer,
    'binding' => $binding, 'query' => $descriptor->query, 'claim' => 'synthetic-claim', 'digest' => str_repeat('a', 64)];
try {
    $engine->consume($descriptor, $ctx, $writer);
    throw new LogicException('Last local consume boundary admitted a stopped renewal');
} catch (\Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\RenewalUnsent $refusal) {
    expect($refusal->getPrevious()?->getMessage() === $stopReason, 'Final consume preserves the funding authority refusal');
}
// The refused grant is spent: the writer now has no grant and refuses before the wire.
try { $writer->renew($descriptor, $ctx); throw new LogicException('Writer sent without a live grant'); }
catch (DomainException $missing) { same('No live renewal grant.', $missing->getMessage(), 'Writer refuses a descriptor without a live grant'); }
same(0, $writes, 'Final consume never reaches writer');
// Sent original attempts still need outcome reconciliation; no second execution is authorized.
foreach (['pending', 'uncertain', 'running'] as $sentState) {
    DB::table('domains_operations')->where('id', $op->id)->update(['state' => $sentState]);
    expect($dispatch->current($op->fresh()), 'DNR retains original '.$sentState.' outcome reconciliation');
}
$retainedCancellation=$c->getRawOriginal();$retainedAdoption=$a->getRawOriginal();
foreach(['type'=>'immediate','service_id'=>$service->id+999,'reason'=>'Changed original renewal stop']as$field=>$value){
    $candidate=$c->fresh();$refused=false;
    try{$candidate->update([$field=>$value]);}catch(Illuminate\Validation\ValidationException $e){
        same('This cancellation stopped renewal when the domain was imported, so it has to stay.',$e->errors()['reason'][0]??null,'Retained cancellation update has its exact refusal');$refused=true;
    }
    expect($refused,'Original cancellation '.$field.' update is guarded');
    same($retainedCancellation,$c->fresh()->getRawOriginal(),'Update refusal retains cancellation bytes');
    same($retainedAdoption,$a->fresh()->getRawOriginal(),'Update refusal retains adoption identity');
    same($money,scenarioMoney(),'Update refusal retains financial history');
}
$refusedDelete = false; try { $c->delete(); } catch (Illuminate\Validation\ValidationException) { $refusedDelete = true; }
expect($refusedDelete, 'Original adoption cancellation deletion is guarded');
same(1, ServiceCancellation::count(), 'Original renewal stop retained');
DB::table('service_cancellations')->where('id', $c->id)->delete();
expect(RenewalStop::stopped(RenewalStop::read($d)), 'Removing native cancellation never means renewal on');
$refuse(fn () => $billing->invoice($d->id, $owner), 'Missing original cancellation requires review, never grants renewal');
same($money, scenarioMoney(), 'Synthetic issued-row probing never changes history finances');
};
// Adoption registrar enrollment and repair.
$cases['enrollment']=static function()use($fixture):void {extract($fixture);
$now=$clock->now()->getTimestamp();
$hostingServer = Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
$hostingProduct = Product::create(['name'=>'Hosting','slug'=>'adoption-hosting','category_id'=>$product->category_id,'server_id'=>$hostingServer->id,'hidden'=>true,'allow_quantity'=>'combined']);
$hostingPlan = $hostingProduct->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$hostingOrder = Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$hosting = Service::create(['user_id'=>$owner->id,'order_id'=>$hostingOrder->id,'product_id'=>$hostingProduct->id,'plan_id'=>$hostingPlan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
DB::statement("CREATE TRIGGER refuse_initial_queue BEFORE INSERT ON domains_observation_queues BEGIN SELECT RAISE(IGNORE); END");
$r=$reader->preview($target,$context); same('ready',$r->code,'Adoption preview is ready');$a=Adoption::findOrFail($r->previewId);$money=scenarioMoney();$calls=count($wire->calls);
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
same('committed',$a->fresh()->state,'Enrollment failure never rolls back committed adoption');
DB::statement('DROP TRIGGER refuse_initial_queue');
$enrollment=new AdoptionEnrollment($clock);
// Simulate a database ignoring a required work insert without raising an exception.
foreach([['domains_observation_queues','1'],['domains_observation_work','1']] as [$table,$condition]){
    DB::statement('CREATE TRIGGER refuse_enrollment BEFORE INSERT ON "'.$table.'" WHEN '.$condition.' BEGIN SELECT RAISE(IGNORE); END');
    $refused=false;
    try{$enrollment->repair($d->id);}catch(DomainException){$refused=true;}
    finally{DB::statement('DROP TRIGGER refuse_enrollment');}
    expect($refused,'Missing required '.$table.' '.$condition.' must refuse enrollment');
    expect(!isset($d->fresh()->observation['adoption_enrollment']),'Missing work never commits enrollment marker');
}
// Retained rows with the right unique key but another identity also refuse.
foreach(['domains_observation_queues','domains_observation_work'] as $table){
    $row=match($table){
        'domains_observation_queues'=>['server_id'=>$d->server_id,'account_fingerprint'=>str_repeat('f',64)],
        'domains_observation_work'=>['server_id'=>$d->server_id,'account_fingerprint'=>str_repeat('f',64),'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding','due_at'=>$now],
    };
    $id=DB::table($table)->insertGetId($row);$retained=DB::table($table)->where('id',$id)->first();$refused=false;
    try{$enrollment->repair($d->id);}catch(DomainException){$refused=true;}
    expect($refused,'Conflicting retained '.$table.' identity must refuse');
    expect(!isset($d->fresh()->observation['adoption_enrollment']),'Conflicting work cannot consume marker');
    same((array)$retained,(array)DB::table($table)->where('id',$id)->first(),'Conflicting work is retained without repair');
    DB::table($table)->where('id',$id)->delete();
}
// A late save veto occurs only after all required work has been staged.
$staged=[];$failMarker=true;
Domain::updating(static function(Domain $row)use(&$staged,&$failMarker){
    if($failMarker&&isset($row->observation['adoption_enrollment'])){
        $staged=[DB::table('domains_observation_queues')->count(),DB::table('domains_observation_work')->count()];
        return false;
    }
});
$refused=false;try{$enrollment->repair($d->id);}catch(DomainException $e){same('Adoption enrollment marker was not retained.',$e->getMessage(),'Exact late-save refusal');$refused=true;}
expect($refused,'Late enrollment marker veto refuses');same([1,1],$staged,'Marker veto follows every work insert');
same([0,0],[DB::table('domains_observation_queues')->count(),DB::table('domains_observation_work')->count()],'Veto rolls back registrar enrollment');
expect(!isset($d->fresh()->observation['adoption_enrollment']),'Late veto cannot consume enrollment marker');
$failMarker=false;
// The account cursor repairs the adoption after both early and late enrollment failures.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(AdoptionEnrollment::class,$enrollment);
$neighbor=Domain::create(['user_id'=>$other->id,'server_id'=>$d->server_id,'service_id'=>$hosting->id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'domain'=>placeholderComFixtureName('neighbor-placeholder.com'),'sld'=>'neighbor-placeholder','tld'=>'com','status'=>'cancelled']);
DB::table('domains_runtime_turns')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'domain_cursor'=>$neighbor->id,'due_at'=>0]);
$runtime=new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts);
$runtime->tick();same(0,(int)DB::table('domains_runtime_turns')->value('domain_cursor'),'Exhausted cursor wraps for earlier adopted row');
expect(!isset($d->fresh()->observation['adoption_enrollment']),'First turn only wraps cursor');
DB::table('domains_runtime_turns')->update(['due_at'=>0]);$runtime->tick();
same(null,$neighbor->fresh()->observation,'Non-adopted neighbor never acquires adoption marker');
same(1,DB::table('domains_observation_work')->where('domain_id',$d->id)->count(),'Registrar observation enrolled');
$marker=$d->fresh()->observation;
$queries=[];DB::listen(static function($q)use(&$queries):void{$queries[]=$q->sql;});
$enrollment->repair($d->id);
same(1,count($queries),'Current seed marker skips discovery and all native locks');
// Each mismatched seed field reaches discovery, but the locked current marker still wins.
$staleField=null;
Domain::retrieved(static function(Domain $row)use(&$staleField,$d):void{
    if($staleField!==null&&DB::transactionLevel()===0&&(int)$row->id===(int)$d->id){
        $prior=$row->observation;$prior['adoption_enrollment'][$staleField]=-1;$row->observation=$prior;$staleField=null;
    }
});
foreach(['adoption_id','generation']as$field){$staleField=$field;$queries=[];$enrollment->repair($d->id);expect(count($queries)>1,'Changed seed '.$field.' cannot skip locked recheck');same($marker,$d->fresh()->observation,'Locked marker retains original enrollment');}
$commit->confirm($a->request_key,$a->request_digest,$staff);
same($marker,$d->fresh()->observation,'Replay preserves enrollment');same($calls,count($wire->calls),'Repair performs no network reads');same($money,scenarioMoney(),'Repair preserves finances');
};
// Retained cancellation reuse, immediate conflict and exact-object grant isolation.
$cases['intent']=static function()use($fixture):void {extract($fixture);
// Privileged fixtures represent retained native intent, not a new adoption writer.
$id = DB::table('service_cancellations')->insertGetId(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Conflicting old cancellation', 'created_at' => now(), 'updated_at' => now()]);
$r = $reader->preview($target, $context); $money = scenarioMoney();
expect($r->code !== 'ready', 'Immediate cancellation conflict refuses preview'); same(0, Domain::count(), 'Conflicting cancellation creates no holding');
DB::table('service_cancellations')->where('id', $id)->update(['type' => 'end_of_period']);
$r = $reader->preview($target, $context); same('ready', $r->code, 'Existing end-of-period can be retained');
$a = Adoption::findOrFail($r->previewId); same(true, $a->do_not_renew, 'Existing stop cannot become renewal on');
$d = $commit->confirm($a->request_key, $a->request_digest, $staff);
same($id, (int) $a->fresh()->cancellation_id, 'Original cancellation ID reused'); same(1, ServiceCancellation::count(), 'No duplicate cancellation');
same('end_of_period', ServiceCancellation::sole()->type, 'Retained type never immediate'); same($money, scenarioMoney(), 'Existing intent does not rewrite history');
// Event-time clones and unrelated guarded objects never inherit the exact-object grant.
DB::table('domains_domains')->where('id', $d->id)->delete(); DB::table('domains_adoptions')->where('id', $a->id)->delete();
DB::table('service_cancellations')->where('id', $id)->delete(); DB::table('services')->where('id', $service->id)->update(['status' => 'pending']);
DB::table('invoices')->delete();
$seen = false; ServiceCancellation::creating(static function (ServiceCancellation $c) use (&$seen): void {
    if ($seen) { return; } $seen = true; $clone = clone $c; $clone->save();
    same('immediate', $clone->fresh()->type, 'Clone uses ordinary unpaid-cancellation guard, not adoption grant');
    throw new DomainException('Injected cross-object cancellation save');
});
$r = $reader->preview($target, new Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext($staff->id, 'Verify isolated grant', true));
$a = Adoption::findOrFail($r->previewId); $yes = false;
try { $commit->confirm($a->request_key, $a->request_digest, $staff); } catch (DomainException) { $yes = true; }
expect($seen && $yes, 'Nested other-object cancellation aborts adoption'); same(0, Domain::count(), 'Cross-object callback rolls holding back'); same(0, ServiceCancellation::count(), 'Cross-object callback rolls cancellation back');
};
nativeFixtureCases($cases,$fixture);
$nativeSuccessful=true;
