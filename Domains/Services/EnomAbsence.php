<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, Evidence, HistoryScan};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Evidence that Enom never processed a lost Register or Renew reply. The scheduled reconciliation records it from its own
 * complete order history scan and its own domain read; a staff request never creates it. It lets staff record the
 * no-order answer without an Enom case number. It never refunds, resends or completes anything by itself. */
final class EnomAbsence
{
    /** Order lists can lag the send. The scan and domain read must start at least a day after the send window ends. */
    public const MARGIN = 86400;
    /** Built from one scheduled read. Null unless the scan and the domain read both corroborate. The margin counts from when
     * this read started, before its first registrar request, so no reply in it can predate the margin. */
    public static function observe(AttemptEvidence $attempt, Evidence $facts, \DateTimeImmutable $started, \DateTimeImmutable $now): ?array
    {
        $scan = $facts->historyScan; $domain = self::domain($attempt, $facts);
        if (!$scan || !$facts->historyComplete || $facts->candidates !== [] || $domain === null || $started > $now
            || !self::scanned($attempt, $scan, $now) || $started->getTimestamp() < $attempt->windowEnd->getTimestamp() + self::MARGIN) { return null; }
        return ['scan_key'=>$scan->key, 'read_at'=>$started->format(DATE_ATOM), 'domain'=>$domain,
            'listed'=>(int)($scan->state['listed'] ?? 0), 'resolved'=>(int)($scan->state['resolved'] ?? 0)];
    }
    /** Called under the check's lock just before a new registrar read. Older evidence never outlives a newer read: the read
     * either saves its own evidence or leaves none, so a failed or unfinished read cannot fall back to an earlier one. */
    public static function clear(Operation $op): bool
    {
        if (!array_key_exists('enom_absence', $op->policy ?? [])) { return false; }
        $policy = $op->policy; unset($policy['enom_absence']); $op->update(['policy'=>$policy]);
        return true;
    }
    /** Every scheduled read that saves scan progress replaces the evidence, so a read that no longer corroborates clears it. */
    public static function apply(array $policy, Operation $op, ?array $evidence): array
    {
        unset($policy['enom_absence']);
        if ($evidence !== null) { $policy['enom_absence'] = ['identity'=>RegistrarAnswers::identity($op), ...$evidence]; }
        return $policy;
    }
    /** The saved evidence, when it still names this attempt, its current scan and the current history contract. */
    public static function holds(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $now): ?array
    {
        $e = $op->policy['enom_absence'] ?? null; $scan = $attempt->historyScan;
        if (!is_array($e) || !is_string($e['identity'] ?? null) || !hash_equals(RegistrarAnswers::identity($op), $e['identity'])
            || !$scan || ($e['scan_key'] ?? null) !== $scan->key || !self::scanned($attempt, $scan, $now)) { return null; }
        try { $read = new \DateTimeImmutable(is_string($e['read_at'] ?? null) ? $e['read_at'] : 'invalid'); } catch (\Throwable) { return null; }
        if ($read > $now || $read->getTimestamp() < $attempt->windowEnd->getTimestamp() + self::MARGIN
            || !is_array($e['domain'] ?? null) || $e['domain'] !== self::expected($attempt)) { return null; }
        return $e;
    }
    /** A complete scan of this exact attempt under a contract that still covers it, with every listed order a validated non-match. */
    private static function scanned(AttemptEvidence $attempt, HistoryScan $scan, \DateTimeImmutable $now): bool
    {
        if ($attempt->binding->registrarKey !== 'enom' || !in_array($attempt->query->action, [Action::Register, Action::Renew], true)
            || $attempt->executionFacts !== null || $attempt->providerReference !== null || $scan->key !== HistoryScan::key($attempt)
            || !($attempt->historyAcceptance?->covers($attempt, $now) ?? false)) { return false; }
        $orders = $scan->state['orders'] ?? null;
        if (($scan->state['complete'] ?? null) !== true || !is_array($orders)) { return false; }
        foreach ($orders as $outcome) { if (!is_string($outcome) || !str_starts_with($outcome, 'n:')) { return false; } }
        return true;
    }
    /** Registration: the domain is not in our Enom account. Renewal: every expiry this read saw is the one saved before the attempt. */
    private static function domain(AttemptEvidence $attempt, Evidence $facts): ?array
    {
        $d = $facts->domain;
        if (!$d || !$d->binding->matches($attempt->binding) || $d->name !== $attempt->query->name) { return null; }
        if ($attempt->query->action === Action::Register) { return $d->owned === false ? self::expected($attempt) : null; }
        $expected = self::expected($attempt);
        if ($expected === null || $d->owned !== true || $d->expiry === null || substr($d->expiry->value, 0, 10) !== $expected['expiry']) { return null; }
        // Two domain info reads and the expiry read each report an expiry. Any missing or different one refuses.
        if (count($facts->expiryReads) < 3) { return null; }
        foreach ($facts->expiryReads as $read) { if ($read !== $expected['expiry']) { return null; } }
        return $expected;
    }
    private static function expected(AttemptEvidence $attempt): ?array
    {
        if ($attempt->query->action === Action::Register) { return ['owned'=>false]; }
        $before = $attempt->beforeExpiry ? substr($attempt->beforeExpiry->value, 0, 10) : null;
        return $before === null ? null : ['owned'=>true, 'expiry'=>$before, 'before_expiry'=>$before];
    }
}
