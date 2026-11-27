<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{ManagementEvidenceKind, OperationSchema};

/** A deployment predating the attempt ledger stored this envelope directly on its operation. */
function restoreLegacyManagement(Operation $op, array $attempt): void
{
    $op = $op->fresh();
    $started = $op->started_at;
    $lease = $op->lease_expires_at;
    $funding = $op->funding;
    DB::transaction(function () use ($op, $attempt, $started, $lease, $funding): void {
        $evidence = DB::table(OperationSchema::EVIDENCE)->where('operation_id', $op->id);
        $steps = DB::table(OperationSchema::STEPS)->where('operation_id', $op->id);
        $attempts = DB::table(OperationSchema::ATTEMPTS)->whereIn('step_id', (clone $steps)->select('id'));
        $evidence->delete();
        $attempts->delete();
        $steps->delete();
        DB::table('domains_operations')->where('id', $op->id)->update([
            'attempt'=>Crypt::encryptString(json_encode($attempt, JSON_THROW_ON_ERROR)),
            'funding'=>$funding ? Crypt::encryptString(json_encode($funding, JSON_THROW_ON_ERROR)) : null,
            'started_at'=>$started, 'lease_expires_at'=>$lease,
        ]);
    });
}

/** Fixture-only adjustment of saved, encrypted registrar evidence. */
function fixtureManagementReadback(Operation $op, array $entry): void
{
    $rows = DB::table(OperationSchema::EVIDENCE)->where(['operation_id'=>$op->id, 'kind'=>ManagementEvidenceKind::Readback->value]);
    $record = $rows->sole();
    $evidence = json_decode(Crypt::decryptString($record->evidence), true, 32, JSON_THROW_ON_ERROR);
    $rows->update(['evidence'=>Crypt::encryptString(json_encode([...$evidence, 'readback'=>$entry], JSON_THROW_ON_ERROR))]);
}
