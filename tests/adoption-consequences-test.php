<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
use App\Models\{Invoice, InvoiceItem, ServiceCancellation};
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\{Money, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\StaffContext;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, HistoryLink, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HoldingAnchor, HoldingLocks, RenewalAuthority, RenewalCycles, RenewalFunding, RenewalReminders, RenewalStop, RuntimeDispatch};
extract(adoptionCommitFixture()); require dirname(__DIR__).'/Domains/routes/owned.php'; app('router')->getRoutes()->refreshNameLookups();
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
same(['Off', 'Set at import'], [$facts['Auto-renew']['value'], $facts['Auto-renew']['sub']], 'Renewal-off staff fact reflects original intent');
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
$clock->time = new DateTimeImmutable('2027-09-29T00:00:00Z'); $billing = new DomainBilling($bindings, $guard, $clock);
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
$refuse(fn () => RenewalFunding::requireCurrent($op, $l), 'Funded issued execution respects DNR');
$refuse(fn () => RenewalFunding::requireAuthority($op, $l), 'Issued funding authority respects original DNR');
$refuse(fn () => (new RenewalAuthority($bindings, $clock))->capture(new RenewalRequest($d->id, $d->authority_generation, $d->confirmed_revision, $d->provider_expires_at->format('Y-m-d'), $d->provider_domain_id, 1, $op->operation_key)), 'Issued admission respects original DNR');
$dispatch = new RuntimeDispatch($clock); same(false, $dispatch->current($op), 'Installed renewal dispatch entry suppresses DNR');
$queued = count(Queue::pushedJobs()); $dispatch->enqueue($op->id, true); same($queued, count(Queue::pushedJobs()), 'DNR enqueue emits no job hint');
$accepted = new \Paymenter\Extensions\Servers\Domains\Services\RenewalAcceptance($binding, true, true, 25);
$features = new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(renewal: true);
$authorityPort = new RenewalAuthority($bindings, $clock);
$admission = new \Paymenter\Extensions\Servers\Domains\Services\RenewalAdmission($authorityPort, $bindings, $accepted, $clock);
$engine = new \Paymenter\Extensions\Servers\Domains\Services\RenewalExecution($bindings, $authorityPort, $admission, $accepted, $features, $clock);
$writes = 0; $writer = new \Paymenter\Extensions\Servers\Domains\Services\RenewalWriter($binding, $features, $accepted, fn () => $engine, static function () use (&$writes): never { $writes++; throw new LogicException('Forbidden renewal send'); });
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
$grantMap = (new ReflectionProperty($engine, 'grants'))->getValue($engine); $grantMap[$descriptor] = [$ctx, $l['stamp'], $op->id, 'ordinary', $writer];
$refuse(fn () => $engine->consume($descriptor, $ctx, $writer), 'Last local consume boundary refuses DNR even with injected live grant');
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
$nativeSuccessful = true;
echo "Atomic adoption history, rollback, end-of-period cancellation and all renewal stops passed\n";
