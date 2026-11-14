<?php
// Refund of a lost Enom reply that Enom provably never processed; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Action, HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomAnswers, HistoryAcceptances, OperationReconciliation, RegistrarRegistry, StaffSettingsReview as Review, StaffSettingsWrites};

// Order history around the send: three orders for other domains, all complete. 'unsupported' leaves one detail unreadable.
$history = new class implements Transport {
    public ?Transport $domain = null; public ?DateTimeImmutable $sent = null; public string $fault = ''; public array $forms = [];
    public function send($env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        if (!in_array($command, [ReadCommand::Orders, ReadCommand::Order], true)) { return $this->domain->send($env, $command, $parameters, $uid, $token, $timeoutMs); }
        $this->forms[] = $command; $at = fn (int $i) => $this->sent->modify('-'.(10 * ($i + 1)).' minutes')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        if ($command === ReadCommand::Orders) {
            $rows = array_map(fn ($i) => ['id' => (string)(8001 + $i), 'date' => $at($i)], range(0, 2));
            return enomHistoryEnvelope(enomHistoryPage($rows, $parameters['Start'], count($rows)));
        }
        $i = (int)$parameters['OrderID'] - 8001;
        $body = enomHistoryDetail($parameters['OrderID'], 'other-'.$i.'-placeholder.com', $at($i), '2028-10-18', '8.95', 1, $at($i));
        return $i === 1 && $this->fault === 'unsupported' ? preg_replace('~<AdjustToAccount\s*/>~', '<AdjustToAccount>1.00</AdjustToAccount>', $body) : $body;
    }
};
$historyRegistry = new RegistrarRegistry;
$historyRegistry->register('enom', ['token'], $verify, fn ($b, $c) => new Driver(new Client($b, 'account-enom', $c['token'], $history), ['com']));
$historyBindings = new AccountBindings($historyRegistry);
$scheduled = new OperationReconciliation($claims, $historyBindings, $clock);

app()->instance(Clock::class, $clock); app()->instance(AccountBindings::class, $bindings);
$staff = commerceUser(); $permissions = ['admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage', 'admin.invoices.update'];
$role = \App\Models\Role::create(['name' => 'No-order answers', 'permissions' => $permissions]); $staff->update(['role_id' => $role->id]);
$settings = new StaffSettingsWrites($native, $sellers, $bindings);
$review = static fn ($op) => new Review($staff->id, $op->server_id, null, 'enom_answer', Review::ticket($staff->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record that Enom has no order');
// Staff still choose the answer and write what they checked; there is no Enom case number.
$noOrder = static fn ($op) => ['operation_id' => $op->id, 'attempt_hash' => EnomAnswers::identity($op), 'outcome' => 'not_processed', 'order_reference' => '',
    'case_reference' => '', 'evidence' => 'Order history has no order for this domain and the domain is unchanged at Enom.', 'confirmed' => true];
$refused = static function ($op, string $why, bool $recorded = false) use ($settings, $review, $noOrder, $clock): void {
    $op = $op->fresh();
    same($recorded, isset($op->policy['enom_absence']), $why.': the scheduled read '.($recorded ? 'recorded' : 'did not record').' no-order evidence'); $kept = $op->getRawOriginal(); $error = null;
    try { $settings->run($review($op), $noOrder($op), $clock->now()); } catch (DomainException $e) { $error = $e; }
    expect($error !== null, $why.': the no-order answer without a case number is refused');
    same([$kept, null, null], [$op->fresh()->getRawOriginal(), $op->fresh()->funding['refund_authorization'] ?? null, $op->fresh()->funding['refund'] ?? null], $why.': no refund is authorized and nothing changes');
};
$allowed = static function ($op, string $why) use ($settings, $review, $noOrder, $clock, $staff): void {
    expect(isset($op->fresh()->policy['enom_absence']), $why.': the scheduled read recorded no-order evidence');
    $settings->run($review($op->fresh()), $noOrder($op->fresh()), $clock->now()); $op = $op->fresh();
    same(['rejected', 'refund_approved', $staff->id, 'enom_not_processed'], [$op->state, $op->safe_code, $op->funding['refund_authorization']['actor_id'] ?? null, $op->funding['refund_authorization']['reason'] ?? null], $why.': staff authorize the refund');
    expect(isset($op->funding['refund']), $why.': the existing guarded payment return runs');
    same([null, true], [$op->policy['enom_answer']['case_reference'], isset($op->policy['enom_answer']['absence']['read_at'])], $why.': the answer keeps the recorded evidence instead of a case number');
};

// Registration: lost reply (no result saved), 30-day history contract.
$register = static function (string $name, string $domainFault = 'absent') use ($prepare, $observation, $history, $wire) {
    [$op, $binding] = $prepare(placeholderComFixtureName($name), 'null');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 60, 2592000, 'enom_registration_v1'));
    $history->domain = $wire; $history->sent = new DateTimeImmutable($op->attempt['dispatch_attempted_at']); $history->fault = '';
    $observation->fault = $domainFault; // 'absent': Enom's exact not-found reply; '': the domain is in our account.
    return [$op, $binding];
};
$read = static function ($op, string $after) use ($scheduled, $clock, $history): void {
    $clock->instant = $history->sent->modify($after); Cache::flush(); $history->forms = [];
    same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open');
};
$before = $writer->calls;

[$op, $binding] = $register('absence-corroborated-placeholder.com');
$read($op, '+25 hours');
same([true, 3], [$op->fresh()->policy['history_scan']['state']['complete'], $op->fresh()->policy['history_scan']['state']['resolved']], 'The scheduled read finishes a full scan with no match');
$allowed($op, 'Complete scan, no order, domain not in our account, read a day after the send');

[$op] = $register('absence-incomplete-placeholder.com'); $history->fault = 'unsupported';
$read($op, '+25 hours');
same(false, $op->fresh()->policy['history_scan']['state']['complete'], 'One unreadable order leaves the scan incomplete');
$refused($op, 'Incomplete scan');

[$op] = $register('absence-present-placeholder.com', '');
$read($op, '+25 hours');
$refused($op, 'Domain in our account');

[$op] = $register('absence-early-placeholder.com');
$read($op, '+2 hours'); $clock->instant = $history->sent->modify('+25 hours');
$refused($op, 'Scan and domain read less than a day after the send');
$read($op, '+26 hours');
$allowed($op, 'A later scheduled read a day after the send');

[$op, $binding] = $register('absence-stale-placeholder.com');
$read($op, '+25 hours');
HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 120, 2592000, 'enom_registration_v1'));
$refused($op, 'Scan saved under an earlier history contract', true);
$read($op, '+26 hours');
$allowed($op, 'A new scan under the current contract');
same($before, $writer->calls, 'No registration is sent again');
DB::transaction(fn () => HistoryAcceptances::revokeLocked($binding, Action::Register));

// Renewal: lost Extend reply; the domain's expiry decides.
$renew = static function (string $name, string $after) use ($makeRenewal, $compose, $state, $history, $transport) {
    [$op, , $binding] = $makeRenewal(placeholderComFixtureName($name));
    [$engine, $renewWriter] = $compose($binding); $state->lost = true;
    same('uncertain', $engine->run($op->id, $renewWriter)->state, 'The renewal reply is lost');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 60, 2592000, 'enom_renewal_v1'));
    $history->domain = $transport; $history->sent = new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']); $history->fault = '';
    $state->after = $after; $state->lost = false;
    return [$op->fresh(), $binding];
};
$completion = new \Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion($historyBindings, $clock);
$renewRead = static function ($op) use ($completion, $clock, $history): void {
    $clock->instant = $history->sent->modify('+25 hours'); Cache::flush();
    same('uncertain', $completion->run($op->id)->state, 'A scheduled renewal read leaves the lost reply open');
};
$writes = $state->writes;
[$op, $binding] = $renew('absence-renew-unchanged-placeholder.com', '2027-10-18');
$renewRead($op);
same(true, $op->fresh()->policy['history_scan']['state']['complete'], 'The renewal scan finishes with no match');
$allowed($op, 'Renewal: no order and the expiry is unchanged');
[$op, $binding] = $renew('absence-renew-moved-placeholder.com', '2028-10-18');
$renewRead($op);
$refused($op, 'Renewal: the expiry moved');
same($writes + 2, $state->writes, 'Only the two original Extends were sent');
echo "A lost Enom reply is refundable without a case number only when the scheduled read proves Enom has no order and the domain is unchanged.\n"; $nativeSuccessful = true;
