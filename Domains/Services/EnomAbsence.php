<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, Evidence, HistoryScan, RequestContext};
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
    /** Turns that may try the one late read. A local read-limit deferral or a failed read leaves it for a later turn. */
    public const READ_TRIES = 3;
    /** The normal read limit can run out before a day has passed since the send. Then one more scheduled read runs at least a
     * day after the send, so the no-order check needs no staff restart. It is used up once it completes, and at most
     * READ_TRIES turns try it. Called under the check's lock in place of the read limit's stop; true claims a try. Reads only. */
    public static function claimRead(Operation $op, AttemptEvidence $attempt, \DateTimeImmutable $now): bool
    {
        $policy = $op->policy ?? []; $claim = self::claim($op, $policy);
        // A claim saved before tries were counted was used up when it was made.
        $tries = $claim === null ? 0 : (is_int($claim['tries'] ?? null) ? $claim['tries'] : self::READ_TRIES);
        if ($attempt->binding->registrarKey !== 'enom' || !in_array($attempt->query->action, [Action::Register, Action::Renew], true)
            || !$attempt->replyLost() || $attempt->executionFacts !== null || isset($op->attempt['reconciliation'])
            || ($claim['done'] ?? false) === true || $tries >= self::READ_TRIES
            || $now->getTimestamp() < $attempt->windowEnd->getTimestamp() + self::MARGIN
            || !($attempt->historyAcceptance?->covers($attempt, $now) ?? false)) { return false; }
        $policy['enom_absence_read'] = ['identity'=>RegistrarAnswers::identity($op), 'at'=>$now->format(DATE_ATOM), 'tries'=>$tries + 1, 'done'=>false];
        $op->update(['policy'=>$policy]);
        return true;
    }
    /** A read completed: it returned in time and every request in it got an Enom reply. This attempt's late read is used up.
     * Called in the read's own locked save, so the evidence and the used-up mark are saved together. */
    public static function completed(array $policy, Operation $op): array
    {
        if (self::claim($op, $policy) !== null) { $policy['enom_absence_read']['done'] = true; }
        return $policy;
    }
    /** The same mark in its own write, under the check's lock, for a completed read whose save did not run. */
    public static function useUp(Operation $op): void
    {
        $claim = self::claim($op, $op->policy ?? []);
        if ($claim !== null && ($claim['done'] ?? false) !== true) { $op->update(['policy'=>self::completed($op->policy, $op)]); }
    }
    /** True when the read made at least one request and got an Enom reply to each. */
    public static function readCompleted(RequestContext $context): bool
    { return $context->exchanges() > 0 && $context->failedExchanges() === 0; }
    private static function claim(Operation $op, array $policy): ?array
    {
        $claim = $policy['enom_absence_read'] ?? null;
        return is_array($claim) && is_string($claim['identity'] ?? null) && hash_equals($claim['identity'], RegistrarAnswers::identity($op)) ? $claim : null;
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
