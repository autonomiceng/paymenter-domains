<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-namesilo-test.php'; $nativeSuccessful = false;
require_once __DIR__.'/Support/namesilo-account-guard.php';
nameSiloGuardExpected($binding,'expected@example.com');
require __DIR__.'/Support/lifecycle-scenarios.php';
use App\Models\Role;
use Illuminate\Support\Facades\{Cache, DB, Queue};
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance, Money};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, Price, SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainBilling, HistoryAcceptances, HolderPricing, HoldingAdoptionSchema, HoldingProvenanceSchema, NativeDomainGuard, RenewalAcceptance, RenewalAdmission, RenewalAuthority, RenewalCompletion, RenewalCycles, RenewalExecution, RenewalWriter, WriteFeatures};
HoldingProvenanceSchema::install(); HoldingAdoptionSchema::install(); Queue::fake();
$renewBindings = new AccountBindings($registry); $authority = new RenewalAuthority($renewBindings, $clock);
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot();
$billing = new DomainBilling($bindings, $guard, $clock); $cycles = new RenewalCycles($bindings, $clock); $holding = $result->domain->fresh();
$accepted = new RenewalAcceptance($binding, true, true, 25); $features = new WriteFeatures(renewal: true);
$engine = new RenewalExecution($renewBindings, $authority, new RenewalAdmission($authority, $renewBindings, $accepted, $clock), $accepted, $features, $clock);
$accountGuard=(object)['email'=>'expected@example.com','id'=>'900','default'=>'1','mode'=>'singleton','reads'=>0];
$writes = 0; $loseResponse = false; $duringSend = null; $sendSwitches = 0;
$writer = new RenewalWriter($binding, $features, $accepted, fn () => $engine, static function ($origin, $command, $params, $timeout) use (&$writes, &$loseResponse, &$duringSend, $accountGuard) {
    same(0, DB::transactionLevel(), 'Renewal send outside locks'); expect($timeout > 0 && $timeout <= 25000, 'Bounded send');
    if($command==='contactList'&&$params===[]){return nameSiloGuardWire($accountGuard);}
    same('https://ote.namesilo.com/apibatch', $origin, 'Original NameSilo route'); same('renewDomain', $command, 'Only holder renewal');
    same(['domain' => 'native-history-placeholder.com', 'years' => 1, 'auto_renew' => 0], $params, 'Fixed annual term with explicit auto-renew off'); $writes++;
    if ($duringSend) { $hook = $duringSend; $duringSend = null; $hook(); }
    if ($loseResponse) { throw new RuntimeException('Synthetic response loss'); }
    return json_encode(['request' => ['operation' => $command], 'reply' => ['code' => 300, 'detail' => 'synthetic', 'domain' => 'native-history-placeholder.com', 'order_amount' => '8.95']]);
});
$completion = new RenewalCompletion($renewBindings, $clock);
$holding->service->product->server->settings()->updateOrCreate(['key' => 'domains_manual_renewals_enabled'], ['value' => true, 'type' => 'boolean']);
Price::where(['server_id' => $binding->serverId, 'action' => 'renew'])->sole()->update(['wholesale' => '8.95', 'revision' => 2]);
$staff = commerceUser(); $role = Role::create(['name' => 'Cycle funding', 'permissions' => ['admin.domains.manage', 'admin.domains.fund']]); $staff->update(['role_id' => $role->id]);
foreach (['paid', 'cap-exact-unknown', 'cap-over'] as $cycle => $case) {
    $year = 2027 + $cycle; $clock->instant = new DateTimeImmutable($year.'-09-27T12:00:00Z');
    Price::query()->update(['observed_at' => $clock->now(), 'fresh_until' => $clock->now()->modify('+1 year')]);
    scenarioRetained($holding, static fn () => scenarioSwitch($sellers, $clock, $tlds[0]->server_id));
    $bill = $billing->invoice($holding->id, $user); $op = Operation::where('invoice_id', $bill->id)->sole();
    same($tlds[0]->server_id, SalesPolicy::where('tld', 'com')->sole()->server_id, 'Enom sells while NameSilo renews');
    same($tlds[1]->server_id, $op->server_id, 'Debt binds original NameSilo account');
    if ($case === 'paid') { scenarioFund($op); }
    else {
        $cycles->retireUnpaid($op->id);
        $op = $cycles->complimentary($holding->id, $staff, 1, new Money($case === 'cap-over' ? '8.94' : '8.95', 'USD'), 'staff_reviewed');
        same(null, $op->invoice_id, 'Complimentary cycle creates no replacement debt');
    }
    $clock->instant = new DateTimeImmutable($year.'-10-16T12:00:00Z'); Cache::flush();
    $loseResponse = $case === 'cap-exact-unknown'; $before = $writes; $money = scenarioMoney();
    $switchesBefore = $sendSwitches;
    $duringSend = static function () use (&$sendSwitches, $sellers, $clock, $tlds): void {
        same($tlds[0]->server_id, SalesPolicy::where('tld', 'com')->sole()->server_id, 'Enom still sells at NameSilo send entry');
        scenarioSwitch($sellers, $clock, $tlds[1]->server_id); $sendSwitches++;
    };
    $sent = $engine->run($op->id, $writer); $loseResponse = false;
    same($switchesBefore + ($case === 'cap-over' ? 0 : 1), $sendSwitches, 'Seller callback runs exactly at an admitted write: '.$sent->state.' '.$sent->safe_code);
    $duringSend = null;
    same($case === 'cap-over' ? 'held' : ($case === 'paid' ? 'pending' : 'uncertain'), $sent->state, 'Non-default NameSilo '.$case.' result: '.$sent->state.' '.$sent->safe_code);
    same($before + ($case === 'cap-over' ? 0 : 1), $writes, 'One capped NameSilo send');
    same($money, scenarioMoney(), 'No supplemental debt or duplicate payment');
    $calls = $wire->calls;
    foreach ([0, null, 1, 0] as $seller) {
        scenarioRetained($holding, static function () use ($sellers, $clock, $tlds, $seller, $engine, $writer, $op): void {
            scenarioSwitch($sellers, $clock, $seller === null ? null : $tlds[$seller]->server_id); $engine->run($op->id, $writer);
        });
    }
    same($calls, $wire->calls, 'Pending/unknown/held cycles never reread or replay on seller change');
    same($before + ($case === 'cap-over' ? 0 : 1), $writes, 'Unknown response cannot purchase another year');
    same($money, scenarioMoney(), 'Held, pending and unknown replay preserves every financial row');
    if ($case === 'cap-over') { continue; }
    $wire->historyId = (string) (902 + $cycle); $wire->expiry = ($year + 1).'-10-18'; $wire->historyAction = 'renewal'; $wire->historyDate = $year.'-10-16 05:00:01';
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'namesilo_renewal_v1'));
    $clock->instant = $clock->now()->modify('+601 seconds');
    same('succeeded', $completion->run($op->id)->state, 'Original history resolves paid or complimentary unknown without replay');
    same(($year + 1).'-10-18', $holding->fresh()->provider_expires_at->format('Y-m-d'), 'One verified annual advance');
    same(null, $holding->service->fresh()->expires_at, 'Native expiry stays null'); same($money, scenarioMoney(), 'History completion preserves native finance');
}
same(['namesilo'], Domain::query()->distinct()->pluck('registrar_key')->all(), 'Only NameSilo holdings throughout');
echo "NameSilo non-default paid/complimentary renewals, cap refusal and unknown recovery passed\n"; $nativeSuccessful = true;
