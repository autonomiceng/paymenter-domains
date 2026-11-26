<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\OperationSchema;

/** Corrupt one persisted acquisition evidence row, bypassing the production writer only in the fixture. */
function changeAcquisitionEvidence(Operation $op, string $kind, Closure $change): Closure
{
    $query = DB::table(OperationSchema::EVIDENCE)->where('operation_id', $op->id)->where('kind', $kind);
    $row = $query->sole();
    $facts = json_decode(Crypt::decryptString($row->evidence), true, 32, JSON_THROW_ON_ERROR);
    $target = DB::table(OperationSchema::EVIDENCE)->where('id', $row->id);
    $target->update(['evidence' => Crypt::encryptString(json_encode($change($facts), JSON_THROW_ON_ERROR))]);
    return static fn () => $target->update(['evidence' => $row->evidence]);
}

/** Detached legacy evidence for advisory checks that alter one immutable field at a time. */
function legacyAcquisitionFixture(Operation $op): Operation
{
    $legacy = clone $op;
    $legacy->setRawAttributes(array_diff_key($op->getAttributes(), ['id' => true]), true);
    $legacy->forceFill(['attempt' => $op->attempt, 'funding' => $op->funding, 'policy' => $op->policy,
        'started_at' => $op->started_at, 'request_fingerprint' => $op->request_fingerprint, 'lease_expires_at' => $op->lease_expires_at]);
    return $legacy;
}

/** Reset the complete claim state of one disposable order between independent fixture cases. */
function restoreAcquisitionFixture(array $raw): void
{
    DB::transaction(static function () use ($raw): void {
        $steps = DB::table(OperationSchema::STEPS)->where('operation_id', $raw['id']);
        DB::table(OperationSchema::EVIDENCE)->where('operation_id', $raw['id'])->delete();
        DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', (clone $steps)->select('id'))->delete();
        $steps->delete();
        DB::table('domains_operations')->where('id', $raw['id'])->update($raw);
    });
}
