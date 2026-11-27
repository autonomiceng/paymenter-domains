<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionResult;
/** Checks the independent input cutoff before an acquisition can claim or recover. */
final class ExecutionWindow
{
    public const BALANCE_HOLDS=[...BalanceRetries::INITIAL_CODES,...BalanceRetries::REVIEW_CODES,...WriteHeld::UNSENT_REFUSALS,'write_acceptance_required','processing','write_disabled',
        'write_rate_limited','nameservers_required','registry_details_unsupported','enom_test_setup_required','enom_test_transfer_setup_required'];
    public static function pastTransferCutoff(Operation $op, \DateTimeImmutable $now): bool
    {
        if ($op->action !== 'transfer') { return false; }
        $cutoff = AcquisitionResult::window($op)?->inputCutoff ?? TransferInputs::deadline($op, $now);
        return $cutoff !== null && $now >= $cutoff;
    }

}
