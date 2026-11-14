<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, HistoryScan};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A lost Enom reply whose reads ran out before an accepted history contract could finish its scan reads again, once per
 * contract revision. Reads only: it never sends, refunds or changes the sealed attempt. */
final class HistoryResumption
{
    public const REASON = 'history_contract_changed';
    /** Called under the operation's read locks before its read limit check. True when the read count was reset. */
    public static function apply(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $now): bool
    {
        $renew = $op->action === 'renew'; $policy = $op->policy ?? [];
        $polls = (int)($renew ? ($policy['renewal_polls'] ?? 0) : ($policy['reconciliation']['polls'] ?? 0));
        if (!in_array($op->action, ['register', 'renew'], true) || $attempt->binding->registrarKey !== 'enom'
            || $polls < OperationReconciliation::POLL_LIMIT || !$attempt->replyLost() || $attempt->executionFacts !== null
            || isset($op->attempt['reconciliation']) || !($attempt->historyAcceptance?->covers($attempt, $now) ?? false)) { return false; }
        $revision = HistoryScan::revision($attempt->historyAcceptance);
        if (($policy['history_resumption']['revision'] ?? null) === $revision) { return false; }
        $policy['history_resumption'] = ['revision' => $revision, 'reason' => self::REASON, 'at' => $now->format(DATE_ATOM), 'prior_polls' => $polls];
        if ($renew) { $policy['renewal_polls'] = 0; } else { $policy['reconciliation']['polls'] = 0; }
        $op->update(['policy' => $policy]);
        return true;
    }
}
