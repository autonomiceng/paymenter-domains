<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationReconciliation, PricingRule, RegistrarAnswers, StaffSettingsReview as Review, StaffSettingsWrites};
app()->instance(Clock::class, $clock);
app()->instance(AccountBindings::class, $renewBindings);
$staff = commerceUser();
$staff->update(['role_id' => App\Models\Role::create(['name' => 'Renewal money review', 'permissions' => [
    'admin.domains.view', 'admin.domains.manage-policy', 'admin.domains.settings.enom_answer', 'admin.domains.manage', 'admin.invoices.update',
]])->id]);
$settings = new StaffSettingsWrites($native, $sellers, $renewBindings);
$record = static function ($op) use ($staff, $settings, $clock): bool {
    $op = $op->fresh();
    $review = new Review($staff->id, $op->server_id, null, 'enom_answer',
        Review::ticket($staff->id, $op->server_id, null, 'enom_answer', Review::stamp($op->server_id, null)), 'Record renewal answer');
    try {
        $settings->run($review, ['operation_id' => $op->id, 'attempt_hash' => RegistrarAnswers::identity($op),
            'outcome' => 'not_processed', 'order_reference' => '', 'case_reference' => '',
            'evidence' => 'Registrar support checked the exact account and renewal attempt.', 'confirmed' => true], $clock->now());
    } catch (DomainException) { return true; }
    return false;
};
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$stop = static function ($op, $completion) use ($clock): void {
    for ($i = 0; $i < OperationReconciliation::POLL_LIMIT; $i++) {
        Cache::flush(); $completion->run($op->id); $clock->instant = $clock->now()->modify('+10 minutes');
    }
    expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'The stopped renewal offers a staff answer');
};

use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryAcceptances, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalCompletion;
app()->instance(AccountBindings::class, $bindings);
[$op, $d, $b] = $makeRenewal(placeholderComFixtureName('history-deadline-proof-placeholder.com'));
[$engine, $writer] = $compose($b); $state->lost = true;
same('uncertain', $engine->run($op->id, $writer)->state, 'The original sent renewal loses its reply');
$sent = renewalSentAt($op);
HistoryAcceptances::save(new HistoryAcceptance($b, 'America/Los_Angeles', 0, 2592000, 'enom_renewal_v1'));
$wire = new class($transport, $state, $clock, $sent) implements Transport {
    public bool $unavailable = false; public int $details = 0;
    public function __construct(private Transport $domain, private object $state, private Clock $clock, private DateTimeImmutable $sent) {}
    public function send($env, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string {
        if (!in_array($command, [ReadCommand::Orders, ReadCommand::Order], true)) {
            return $this->domain->send($env, $command, $parameters, $uid, $token, $timeoutMs);
        }
        if ($this->unavailable) { throw new RuntimeException('Synthetic unavailable order history'); }
        $placed = $this->sent->modify('+1 second')->setTimezone(new DateTimeZone('America/Denver'))->format('n/j/Y g:i:s A');
        if ($command === ReadCommand::Orders) { return enomHistoryEnvelope(enomHistoryList(['9002'], $placed)); }
        $this->details++;
        $reply = enomHistoryDetail('9002', $this->state->name, $placed, '2028-10-18', '8.00', 1, $placed);
        $this->clock->instant = $this->clock->now()->modify('+300 seconds');
        return $reply;
    }
};
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], $verify, fn ($binding, $credentials) => new Driver(new Client($binding, 'account-enom', $credentials['token'], $wire), ['com']));
$historyBindings = new AccountBindings($registry); $completion = new RenewalCompletion($historyBindings, $clock);
$state->after = '2027-10-18'; $clock->instant = $sent->modify('+25 hours'); Cache::flush();
$before = $credit(); $writes = $state->writes;
$completion->run($op->id);
same([1, 'uncertain', null], [$wire->details, $op->fresh()->state, $op->fresh()->attempt['reconciliation'] ?? null], 'A matching charge arrived too late to complete the renewal');
$proof = renewalObservation($op);
$wire->unavailable = true;
for ($i = 1; $i < OperationReconciliation::POLL_LIMIT; $i++) {
    $clock->instant = $clock->now()->modify('+10 minutes'); Cache::flush(); $completion->run($op->id);
}
expect(RegistrarAnswers::ready($op->fresh(), $clock->now()), 'Unavailable later history stops at the read limit');
app()->instance(AccountBindings::class, $historyBindings);
$refused = $record($op); $fresh = $op->fresh();
$actual = [$credit() - $before, $fresh->state, $fresh->funding['refund_authorization'] ?? null,
    $fresh->policy['enom_answer'] ?? null, $refused, is_array($proof) && ($proof['identity'] ?? null) === RegistrarAnswers::identity($fresh), $state->writes - $writes];
same([0, 'uncertain', null, null, true, true, 0], $actual,
    'A matching history charge at the deadline remains proof and prevents returning its payment: '.json_encode($actual));
$saved = renewalObservation($fresh);
same([$proof, true], [$saved, is_array($saved) && is_string($saved['identity'] ?? null) && hash_equals(RegistrarAnswers::identity($fresh), $saved['identity'])],
    'After the refused answer, the saved proof is unchanged and still names the current attempt');
echo "Late matching history charge protects renewal money.\n"; $nativeSuccessful = true;
