<?php
// Refund of a lost Enom reply that Enom provably never processed; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, HistoryAcceptance, HistoryScan, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomAnswers, HistoryAcceptances, OperationReconciliation, ReadDeferred, RegistrarRegistry, Runtime, StaffSettingsReview as Review, StaffSettingsWrites};

// Order history around the send: three orders for other domains, all complete. 'unsupported' leaves one detail unreadable,
// $match lists the renewal fixture's own order for this renewal instead, $late runs once when the order list is read, and
// $fail makes every order list request fail before Enom replies. $orders is how many orders the day lists.
$history = new class implements Transport {
    public ?Transport $domain = null; public ?DateTimeImmutable $sent = null; public string $fault = ''; public array $forms = []; public bool $match = false; public $late = null; public bool $fail = false; public int $orders = 3; public ?ReadCommand $failedCommand = null; public $detailTick = null; public ?string $blockedName = null;
    public function send($env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        if ($command === $this->failedCommand) { throw new RuntimeException('Connection reset before a flag reply'); }
        if ($this->match || !in_array($command, [ReadCommand::Orders, ReadCommand::Order], true)) { return $this->domain->send($env, $command, $parameters, $uid, $token, $timeoutMs); }
        if ($this->fail && $command === ReadCommand::Orders) { throw new RuntimeException('Connection reset before a reply'); }
        $this->forms[] = $command; $at = fn (int $i) => $this->sent->modify('-'.(10 * ($i + 1)).' minutes')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        if ($command === ReadCommand::Orders) {
            if ($this->late) { $hook = $this->late; $this->late = null; $hook(); }
            $rows = array_map(fn ($i) => ['id' => (string)(8001 + $i), 'date' => $at($i)], range(0, $this->orders - 1));
            $page = enomHistoryPage(array_slice($rows, $parameters['Start'] - 1, 25), $parameters['Start'], count($rows));
            return enomHistoryEnvelope($this->blockedName === null ? $page : str_replace('<StatusDesc>Order complete', '<StatusDesc>Order canceled', $page));
        }
        if ($this->detailTick) { ($this->detailTick)($timeoutMs); }
        $i = (int)$parameters['OrderID'] - 8001;
        $name = $i === $this->orders - 1 && $this->blockedName !== null ? $this->blockedName : 'other-'.$i.'-placeholder.com';
        $body = enomHistoryDetail($parameters['OrderID'], $name, $at($i), '2028-10-18', '8.95', 1, $at($i));
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
    $history->domain = $wire; $history->sent = new DateTimeImmutable($op->attempt['dispatch_attempted_at']); $history->fault = ''; $history->match = false; $history->late = null; $history->fail = false; $history->orders = 3;
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
// Evidence lasts only until the next read starts. A later read that fails before the order history keeps nothing.
[$op] = $register('absence-later-failed-placeholder.com');
$read($op, '+25 hours'); expect(isset($op->fresh()->policy['enom_absence']), 'Evidence from the first read');
$observation->fault = ''; // Enom finished the order late: the domain is now in our account.
$history->late = static function () use ($clock): void { $clock->instant = $clock->instant->modify('+300 seconds'); }; // The read runs out of time.
$read($op, '+26 hours');
$refused($op, 'A later read that saw the domain in our account and then ran out of time');

// Staff cannot use evidence while a newer read is running.
[$op] = $register('absence-during-read-placeholder.com');
$read($op, '+25 hours'); expect(isset($op->fresh()->policy['enom_absence']), 'Evidence from the first read');
$during = 'not tried';
$observation->during = static function () use ($refused, $op, &$during): void {
    try { $refused($op, 'A no-order answer while a newer read runs'); $during = true; } catch (Throwable $e) { $during = $e->getMessage(); }
};
$read($op, '+26 hours'); same(true, $during, 'A no-order answer while a newer read runs is refused'.(is_string($during) ? ' ('.$during.')' : ''));
$allowed($op, 'The newer read finished and agrees again');

// The day counts from when the read started, not when it finished.
[$op, $binding] = $register('absence-boundary-placeholder.com');
$end = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op->fresh(), $op->domain()->firstOrFail(), $binding)->windowEnd;
$clock->instant = $end->modify('+86370 seconds'); Cache::flush();
$observation->during = static function () use ($clock): void { $clock->instant = $clock->instant->modify('+60 seconds'); }; // The read crosses the day mark.
same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open');
$clock->instant = $history->sent->modify('+25 hours');
$refused($op, 'A read that started before the day mark and finished after it');

// A later read that sees the domain in our account clears earlier evidence.
[$op] = $register('absence-later-present-placeholder.com');
$read($op, '+25 hours'); expect(isset($op->fresh()->policy['enom_absence']), 'Evidence from the first read');
$observation->fault = '';
$read($op, '+26 hours');
$refused($op, 'A later read found the domain in our account');
// The normal read limit runs out hours before a day has passed. One more scheduled read still runs a day after the send,
// saves the evidence, and never runs again for this attempt.
$late = static function (callable $run, callable $calls, string $why) use ($clock, $history): void {
    $reads = static function (string $after) use ($run, $calls, $clock, $history): int { $clock->instant = $history->sent->modify($after); Cache::flush(); $calls(true); $run(); return $calls(false); };
    $hour = 0; while ($reads('+'.(++$hour).' hours') > 0) { expect($hour < 20, $why.': the normal read limit ends'); }
    same(0, $reads('+23 hours'), $why.': no read before the day mark once the limit is reached');
    expect($reads('+25 hours') > 0, $why.': one read runs a day after the send');
    same([0, 0], [$reads('+26 hours'), $reads('+48 hours')], $why.': that read never runs again for this attempt');
};
[$op] = $register('absence-late-read-placeholder.com');
$late(fn () => same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open'),
    function (bool $reset) use ($observation): int { $n = count($observation->calls); if ($reset) { $observation->calls = []; } return $n; }, 'Registration');
$allowed($op, 'Registration: the one late read recorded the evidence');
// That late read is used up only once it completes. A local read-limit deferral or a failed read tries again on a later turn,
// at most three turns, and a completed read never runs again.
$deferAll = new class implements ReadLimiter {
    public function acquire(AccountBinding $binding, RequestOrigin $origin): string { throw new ReadDeferred('Local read limit'); }
    public function release(AccountBinding $binding, RequestOrigin $origin, string $token): void {}
};
$retried = static function (callable $run, callable $calls, callable $evidence, string $why) use ($clock, $history, $deferAll): void {
    $reads = static function (string $after) use ($run, $calls, $clock, $history): int { $clock->instant = $history->sent->modify($after); Cache::flush(); $calls(true); $run(); return $calls(false); };
    $hour = 0; while ($reads('+'.(++$hour).' hours') > 0) { expect($hour < 20, $why.': the normal read limit ends'); }
    app()->instance(Runtime::class, new Runtime); app()->instance(ReadLimiter::class, $deferAll);
    try { same(0, $reads('+25 hours'), $why.': the local read limit defers the late read before any request'); } finally { app()->offsetUnset(Runtime::class); }
    same(false, $evidence(), $why.': a deferred read records nothing');
    expect($reads('+26 hours') > 0, $why.': the deferred late read runs on a later turn');
    same(true, $evidence(), $why.': the late read that completed recorded the evidence');
    same([0, 0], [$reads('+27 hours'), $reads('+48 hours')], $why.': a completed late read never runs again');
};
$failed = static function (callable $run, callable $calls, callable $evidence, string $why) use ($clock, $history): void {
    $reads = static function (string $after) use ($run, $calls, $clock, $history): int { $clock->instant = $history->sent->modify($after); Cache::flush(); $calls(true); $run(); return $calls(false); };
    $hour = 0; while ($reads('+'.(++$hour).' hours') > 0) { expect($hour < 20, $why.': the normal read limit ends'); }
    $history->fail = true;
    foreach (['+25 hours', '+26 hours', '+27 hours'] as $try => $after) { expect($reads($after) > 0, $why.': failed late read try '.($try + 1).' runs'); }
    same([0, 0], [$reads('+28 hours'), $reads('+48 hours')], $why.': three failed tries stop the late read');
    $history->fail = false;
    same(false, $evidence(), $why.': failed reads record nothing');
};
$registrationCalls = function (bool $reset) use ($observation): int { $n = count($observation->calls); if ($reset) { $observation->calls = []; } return $n; };
[$op] = $register('absence-late-deferred-placeholder.com');
$retried(fn () => same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open'), $registrationCalls,
    fn () => isset($op->fresh()->policy['enom_absence']), 'Registration');
$allowed($op, 'Registration: the late read after a deferral recorded the evidence');
[$op] = $register('absence-late-failed-placeholder.com');
$failed(fn () => same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open'), $registrationCalls,
    fn () => isset($op->fresh()->policy['enom_absence']), 'Registration');
// Revalidation of the same unrelated open orders is not new progress: a blocked read must count toward escalation.
[$op] = $register('absence-blocked-progress-placeholder.com');
$history->blockedName = $op->domain->domain;
$read($op, '+1 hours');
$read($op, '+2 hours');
same([false, 1, false], [$op->fresh()->policy['history_scan']['state']['complete'], $op->fresh()->policy['reconciliation']['polls'] ?? 0,
    isset($op->fresh()->policy['enom_absence'])], 'The second unchanged blocked turn spends one poll and authorizes no absence');
$history->blockedName = null;
// Deadline-reaching rechecks retain old exclusions, so identical slow reads still escalate.
[$op] = $register('absence-slow-blocked-placeholder.com');
$history->orders = 13; $history->blockedName = $op->domain->domain;
$read($op, '+1 hours');
$history->detailTick = static function (int $ms) use ($clock): void { $clock->instant = $clock->instant->modify('+'.min(24000, $ms).' milliseconds'); };
foreach (range(1, 3) as $turn) {
    $read($op, '+'.($turn + 1).' hours');
    $saved = $op->fresh()->policy;
    same([$turn, false, false, null], [$saved['reconciliation']['polls'] ?? 0, $saved['history_scan']['state']['complete'],
        isset($saved['enom_absence']), $op->fresh()->funding['refund_authorization'] ?? null],
        'Every identical slow blocked turn raises the poll count without financial authority');
}
same(12, $saved['history_scan']['state']['resolved'], 'Deadline-reaching and unreached rechecks both retain their earlier exclusions');
$history->orders = 3; $history->blockedName = null; $history->detailTick = null;
// Validated detail progress survives the deadline; the reply that reaches it supplies no financial facts.
$deadlineScan = static function ($op, callable $run, string $why) use ($clock, $history, $refused): void {
    $history->orders = 11;
    $history->detailTick = static function (int $ms) use ($clock): void { $clock->instant = $clock->instant->modify('+'.min(24000, $ms).' milliseconds'); };
    $clock->instant = $history->sent->modify('+25 hours'); Cache::flush(); $run();
    same([10, false, false, null], [$op->fresh()->policy['history_scan']['state']['resolved'] ?? null,
        $op->fresh()->policy['history_scan']['state']['complete'] ?? null, isset($op->fresh()->policy['enom_absence']),
        $op->fresh()->funding['refund_authorization'] ?? null], $why.': only the ten in-time details survive, with no completion or refund evidence');
    $refused($op, $why.': an expired partial scan');
    $history->detailTick = null; $history->forms = [];
    $clock->instant = $history->sent->modify('+26 hours'); Cache::flush(); $run();
    same([11, true, 1], [$op->fresh()->policy['history_scan']['state']['resolved'], $op->fresh()->policy['history_scan']['state']['complete'],
        count(array_filter($history->forms, fn ($command) => $command === ReadCommand::Order))], $why.': the next turn reuses the saved progress and reads only the late order');
    $history->orders = 3;
};
[$op] = $register('absence-deadline-progress-placeholder.com');
$deadlineScan($op, fn () => same('uncertain', $scheduled->run($op->id)->state, 'Deadline registration stays unresolved'), 'Registration');
// A covered history cannot finish the late claim when one domain flag exchange failed.
[$op, $binding] = $register('absence-covered-failure-placeholder.com', '');
$op->update(['policy' => [...$op->policy, 'reconciliation' => ['polls' => OperationReconciliation::POLL_LIMIT],
    'history_resumption' => ['revision' => HistoryScan::revision(HistoryAcceptances::load($binding))]]]);
$history->failedCommand = ReadCommand::Renewal;
$read($op, '+25 hours');
same([true, false, null], [$op->fresh()->policy['history_scan']['state']['complete'], $op->fresh()->policy['enom_absence_read']['done'],
    $op->fresh()->funding['refund_authorization'] ?? null], 'Covered history with a failed flag exchange leaves the late claim open and authorizes no refund');
$history->failedCommand = null;
$read($op, '+26 hours');
expect(count($history->forms) > 0, 'The next scheduled turn retries the covered read after the failed exchange');
same(true, $op->fresh()->policy['enom_absence_read']['done'], 'The successful retry finishes the late claim');
// A late read that ends with a partial order scan is not used up: the scan continues on a later turn, within the three tries.
$partial = static function ($op, callable $run, callable $calls, string $why) use ($clock, $history): void {
    $reads = static function (string $after) use ($run, $calls, $clock, $history): int { $clock->instant = $history->sent->modify($after); Cache::flush(); $calls(true); $run(); return $calls(false); };
    $hour = 0; while ($reads('+'.(++$hour).' hours') > 0) { expect($hour < 20, $why.': the normal read limit ends'); }
    // The scan restarts after the limit, for example when the account's bill zone is measured. 23 orders need two reads.
    $op->refresh(); $op->update(['policy' => array_diff_key($op->policy, ['history_scan' => true])]);
    expect($reads('+25 hours') > 0, $why.': the late read runs');
    same([false, false], [$op->fresh()->policy['history_scan']['state']['complete'], isset($op->fresh()->policy['enom_absence'])], $why.': the late read ends with a partial scan and no evidence');
    expect($reads('+26 hours') > 0, $why.': the partial late read continues on the next turn');
    same(true, isset($op->fresh()->policy['enom_absence']), $why.': the late read that finished its scan recorded the evidence');
    same([0, 0], [$reads('+27 hours'), $reads('+48 hours')], $why.': a completed late read never runs again');
};
[$op] = $register('absence-late-partial-placeholder.com'); $history->orders = 23;
$partial($op, fn () => same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open'), $registrationCalls, 'Registration');
$allowed($op, 'Registration: the late read that finished a partial scan recorded the evidence');
$history->orders = 3;

// A completed read takes no extra lock unless an Enom late read is still open.
$transactions = 0; \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$transactions): void { $transactions++; });
$turn = static function ($op, string $after) use ($scheduled, $clock, $history, &$transactions): int {
    $clock->instant = $history->sent->modify($after); Cache::flush(); $transactions = 0;
    same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open'); return $transactions;
};
[$op] = $register('absence-lock-placeholder.com');
$turn($op, '+1 hours');
$history->fail = true; $failedTurn = $turn($op, '+2 hours'); $history->fail = false;
same($failedTurn, $turn($op, '+3 hours'), 'A completed read without an open late read takes no more locks than a failed read');
// Something else changes the operation while the late read runs, so the read's own save is skipped. The read is still used
// up, and a failure while marking it never breaks the turn.
$registrationReads = static function ($op, string $after) use ($scheduled, $clock, $history, $registrationCalls): int {
    $clock->instant = $history->sent->modify($after); Cache::flush(); $registrationCalls(true);
    same('uncertain', $scheduled->run($op->id)->state, 'A scheduled read leaves the lost reply open'); return $registrationCalls(false);
};
$changed = static fn ($op) => DB::table($op->getTable())->where('id', $op->id)->update(['updated_at' => '2001-01-01 00:00:00']);
[$op] = $register('absence-mark-placeholder.com');
$hour = 0; while ($registrationReads($op, '+'.(++$hour).' hours') > 0) { expect($hour < 20, 'Mark: the normal read limit ends'); }
$observation->during = static fn () => $changed($op);
expect($registrationReads($op, '+25 hours') > 0, 'Mark: the late read runs');
same(true, $op->fresh()->policy['enom_absence_read']['done'] ?? null, 'A completed late read whose own save was skipped is still used up');
[$op] = $register('absence-mark-failure-placeholder.com');
$hour = 0; while ($registrationReads($op, '+'.(++$hour).' hours') > 0) { expect($hour < 20, 'Mark failure: the normal read limit ends'); }
$armed = false;
\Paymenter\Extensions\Servers\Domains\Models\Operation::updating(function ($model) use (&$armed): void {
    if ($armed && ($model->policy['enom_absence_read']['done'] ?? null) === true) { throw new RuntimeException('Lock lost while marking the late read'); }
});
$observation->during = static function () use ($changed, $op, &$armed): void { $changed($op); $armed = true; };
$clock->instant = $history->sent->modify('+25 hours'); Cache::flush(); $broken = null;
try { $turnState = $scheduled->run($op->id)->state; } catch (Throwable $e) { $broken = $e->getMessage(); }
$armed = false;
same(null, $broken, 'A failure while marking the late read used up never breaks the turn');
same(['uncertain', false], [$turnState ?? null, $op->fresh()->policy['enom_absence_read']['done'] ?? null], 'The turn finishes and the late read stays open');
same($before, $writer->calls, 'No registration is sent again');
DB::transaction(fn () => HistoryAcceptances::revokeLocked($binding, Action::Register));

// Renewal: lost Extend reply; the domain's expiry decides.
$renew = static function (string $name, string $after, ?string $info = null) use ($makeRenewal, $compose, $state, $history, $transport) {
    [$op, , $binding] = $makeRenewal(placeholderComFixtureName($name));
    [$engine, $renewWriter] = $compose($binding); $state->lost = true;
    same('uncertain', $engine->run($op->id, $renewWriter)->state, 'The renewal reply is lost');
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 60, 2592000, 'enom_renewal_v1'));
    $history->domain = $transport; $history->sent = new DateTimeImmutable($op->fresh()->attempt['dispatch_attempted_at']); $history->fault = ''; $history->match = false; $history->late = null; $history->fail = false; $history->orders = 3;
    $state->after = $after; $state->lost = false; if ($info !== null) { $state->expiry = $info; } // $info: the expiry domain info reports.
    return [$op->fresh(), $binding];
};
$completion = new \Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion($historyBindings, $clock);
$renewRead = static function ($op, string $after = '+25 hours') use ($completion, $clock, $history): void {
    $clock->instant = $history->sent->modify($after); Cache::flush();
    same('uncertain', $completion->run($op->id)->state, 'A scheduled renewal read leaves the lost reply open');
};
$writes = $state->writes;
// Repeated deadline progress must finish a dense history without spending the failed-read allowance.
[$op, $binding] = $renew('absence-renew-slow-dense-placeholder.com', '2027-10-18');
$op->update(['policy' => [...$op->policy, 'history_resumption' => ['revision' => HistoryScan::revision(HistoryAcceptances::load($binding, Action::Renew))]]]);
$history->orders = 150;
$history->detailTick = static function (int $ms) use ($clock): void { $clock->instant = $clock->instant->modify('+'.min(24000, $ms).' milliseconds'); };
foreach (range(1, 15) as $turn) {
    $clock->instant = $history->sent->modify('+'.(24 + $turn).' hours'); Cache::flush();
    same('uncertain', $completion->run($op->id)->state, 'Slow history never completes or resends the renewal');
    $saved = $op->fresh()->policy;
    same(10 * $turn, $saved['history_scan']['state']['resolved'] ?? null, 'Each slow turn preserves ten more validated outcomes');
    if ($turn < 15) {
        same([0, false, false], [$saved['renewal_polls'] ?? 0, $saved['history_scan']['state']['complete'], isset($saved['enom_absence'])],
            'Slow renewal progress never spends the failed-read allowance or authorizes absence');
    } else {
        same([true, true, null], [$saved['history_scan']['state']['complete'], isset($saved['enom_absence']), $op->fresh()->funding['refund_authorization'] ?? null],
            'All fifteen slow turns finish the no-order scan without authorizing a refund');
    }
}
$history->detailTick = null; $history->orders = 3;

[$op] = $renew('absence-renew-deadline-placeholder.com', '2027-10-18');
$deadlineScan($op, fn () => same('uncertain', $completion->run($op->id)->state, 'Deadline renewal stays unresolved'), 'Renewal');
[$op, $binding] = $renew('absence-renew-unchanged-placeholder.com', '2027-10-18');
$renewRead($op);
same(true, $op->fresh()->policy['history_scan']['state']['complete'], 'The renewal scan finishes with no match');
$allowed($op, 'Renewal: no order and the expiry is unchanged');
[$op, $binding] = $renew('absence-renew-moved-placeholder.com', '2028-10-18');
$renewRead($op);
$refused($op, 'Renewal: the expiry moved');
// Domain info says the expiry moved while the expiry read still shows the old date: the reads disagree, so no evidence.
[$op, $binding] = $renew('absence-renew-contradiction-placeholder.com', '2027-10-18', '10/18/2028 5:00:00 AM');
$renewRead($op);
same(true, $op->fresh()->policy['history_scan']['state']['complete'], 'The renewal scan finishes with no match');
$refused($op, 'Renewal: domain info and the expiry read disagree');
// A later read finds an order for this renewal: earlier evidence is cleared and the refund is refused.
[$op, $binding] = $renew('absence-renew-match-placeholder.com', '2027-10-18');
$renewRead($op); expect(isset($op->fresh()->policy['enom_absence']), 'Evidence from the first renewal read');
$history->match = true;
$renewRead($op, '+26 hours');
expect(in_array(true, array_map(fn ($o) => str_starts_with($o, 'c:'), $op->fresh()->policy['history_scan']['state']['orders']), true), 'The later read found an order for this renewal');
$refused($op, 'Renewal: a later read found a matching order');
[$op] = $renew('absence-renew-late-read-placeholder.com', '2027-10-18');
$late(fn () => same('uncertain', $completion->run($op->id)->state, 'A scheduled renewal read leaves the lost reply open'),
    function (bool $reset) use ($state): int { $n = count($state->calls); if ($reset) { $state->calls = []; } return $n; }, 'Renewal');
$allowed($op, 'Renewal: the one late read recorded the evidence');
$renewalCalls = function (bool $reset) use ($state): int { $n = count($state->calls); if ($reset) { $state->calls = []; } return $n; };
[$op] = $renew('absence-renew-late-deferred-placeholder.com', '2027-10-18');
$retried(fn () => same('uncertain', $completion->run($op->id)->state, 'A scheduled renewal read leaves the lost reply open'), $renewalCalls,
    fn () => isset($op->fresh()->policy['enom_absence']), 'Renewal');
$allowed($op, 'Renewal: the late read after a deferral recorded the evidence');
[$op] = $renew('absence-renew-late-failed-placeholder.com', '2027-10-18');
$failed(fn () => same('uncertain', $completion->run($op->id)->state, 'A scheduled renewal read leaves the lost reply open'), $renewalCalls,
    fn () => isset($op->fresh()->policy['enom_absence']), 'Renewal');
[$op] = $renew('absence-renew-late-partial-placeholder.com', '2027-10-18'); $history->orders = 23;
$partial($op, fn () => same('uncertain', $completion->run($op->id)->state, 'A scheduled renewal read leaves the lost reply open'), $renewalCalls, 'Renewal');
$allowed($op, 'Renewal: the late read that finished a partial scan recorded the evidence');
same($writes + 10, $state->writes, 'Only the ten original Extends were sent');
echo "A lost Enom reply is refundable without a case number only when the scheduled read proves Enom has no order and the domain is unchanged.\n"; $nativeSuccessful = true;
