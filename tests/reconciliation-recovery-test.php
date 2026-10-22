<?php
// Slice21 double-persistence residual; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reconciliation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\HistoryAcceptances;
$acceptedWriter = new class($writer) implements \Paymenter\Extensions\Servers\Domains\Contracts\AdmittedRegistrarDriver {
    public function __construct(private $inner) {}
    public function register($admitted, $context): \Paymenter\Extensions\Servers\Domains\Data\DriverResult {
        $this->inner->register($admitted, $context);
        return new \Paymenter\Extensions\Servers\Domains\Data\DriverResult($admitted->binding,
            \Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted, \Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,
            \Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new \Paymenter\Extensions\Servers\Domains\Data\Evidence(registration: new \Paymenter\Extensions\Servers\Domains\Data\RegistrationEvidence($admitted->query, '9001', null, null, [])));
    }
    public function renew($op, $ctx): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
    public function transferIn($op, $ctx): \Paymenter\Extensions\Servers\Domains\Data\DriverResult { throw new LogicException; }
};
$crashEngine = new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims, $preflight, $acceptedWriter, $clock); $writer->engine = $crashEngine;
foreach (['unique', 'zero', 'multiple'] as $case) {
    $clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z');
    $op = $new(placeholderComFixtureName('lost-completion-'.$case.'-placeholder.com')); $failures = 0;
    Operation::updating(static function ($row) use ($op, &$failures): void {
        if ($row->id === $op->id && array_key_exists('execution', $row->attempt ?? [])) {
            $failures++; throw new RuntimeException('Synthetic returned-result persistence failure');
        }
    });
    $before = $writer->calls;
    try { $crashEngine->execute($op->id); expect(false, 'Both completion writes fail'); } catch (RuntimeException) {}
    same(2, $failures, 'One same-result persistence retry, without another registrar send');
    same($before + 1, $writer->calls, 'Exactly one original send');
    $op = $op->fresh(); same('sealed', $op->attempt['seal'], 'Original seal survives both rollbacks');
    expect(!array_key_exists('execution', $op->attempt), 'No returned execution facts persisted');
    $binding = \Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $op->server_id)->sole());
    HistoryAcceptances::save(new HistoryAcceptance($binding, 'America/Los_Angeles', 0, 86400, 'enom_registration_v1'));
    $observation->name = $op->domain->domain; $observation->fault = $case === 'unique' ? '' : $case;
    $observation->calls = []; $clock->instant = new DateTimeImmutable('2026-10-18T12:06:01Z');
    $result = $reconcile->run($op->id);
    same($case === 'unique' ? 'succeeded' : 'uncertain', $result->state, 'Provider-only original-attempt recovery requires one proven charge');
    same($before + 1, $writer->calls, 'Recovery never replays original send');
    same(false, array_key_exists('execution', $result->attempt), 'Recovery preserves absence of returned result');
    if ($case !== 'unique') { same(null, $result->domain->provider_expires_at, 'Ambiguity never anchors ownership'); }
}
echo "Double completion-persistence failure recovers only unique original provider charge without replay\n"; $nativeSuccessful = true;
