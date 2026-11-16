<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AttemptEvidence, Evidence};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** An Enom renewal that returned an order number, whose completed reads never find that order. Enom TEST, for one, has no
 * order detail. Each such read counts toward the read limit, so checks stop, and the last one saves what it saw of the
 * domain. Once checks stop staff record Enom support's answer. It never refunds, resends or completes anything by itself. */
final class OrderUnproven
{
    /** What one in-time read saw of the domain, or null when it is not such a read or the order or domain read did not complete. */
    public static function observe(AttemptEvidence $attempt, Evidence $facts): ?array
    {
        $d = $facts->domain;
        // Only a completed order detail read that found no matching order can say the order was not found. A failed one is a
        // read failure, and one that found the order (charged, even with the expiry not yet moved) is never unproven.
        if ($attempt->binding->registrarKey !== 'enom' || $attempt->query->action !== Action::Renew || $attempt->executionFacts === null
            || $attempt->providerReference === null || !$facts->orderRead || $facts->candidates !== [] || !$d || !$d->binding->matches($attempt->binding) || $d->name !== $attempt->query->name
            || ($d->owned === true && $d->expiry === null)) { return null; }
        return ['order_reference' => $attempt->providerReference, 'owned' => $d->owned, 'expiry' => $d->owned ? substr($d->expiry->value, 0, 10) : null,
            'expiry_reads' => $facts->expiryReads];
    }
    /** Saved in the read's own locked step. A newer read replaces it. */
    public static function apply(array $policy, Operation $op, array $seen, \DateTimeImmutable $now): array
    { $policy['order_unproven'] = ['identity' => RegistrarAnswers::identity($op), 'read_at' => $now->format(DATE_ATOM), ...$seen]; return $policy; }
    /** Called under the check's lock just before a new read: a read that fails leaves no older evidence behind. */
    public static function clear(Operation $op): void
    {
        if (!array_key_exists('order_unproven', $op->policy ?? [])) { return; }
        $policy = $op->policy; unset($policy['order_unproven']); $op->update(['policy' => $policy]);
    }
    /** The renewal kept Enom's order number, nothing proved or charged it, and its reads stopped at the limit. Staff may then
     * record either answer. A staff restart resets the count, so the answer waits for those reads to stop again. */
    public static function applies(Operation $op): bool
    {
        if (!RegistrarAnswers::readable($op)) { return false; }
        $a = $op->attempt ?? []; $id = $op->provider_order_id;
        return $op->registrar_key === 'enom' && $op->action === 'renew' && is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id)
            && ($a['renewal']['order_reference'] ?? null) === $id && ($a['execution']['send_state'] ?? null) === 'sent'
            && in_array($a['execution']['disposition'] ?? null, ['accepted', 'accepted_with_discrepancy'], true)
            && !isset($a['reconciliation']) && !isset($op->cost['actual']) && $op->confirmed_expires_at === null
            && !RenewalCompletion::capturedChargeHeld($op)
            && (int)($op->policy['renewal_polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT;
    }
    /** The last read of this attempt found the domain in our account with every expiry it read still the one saved before
     * the renewal. Only then can Enom's "not processed" answer return the payment. */
    public static function unchanged(Operation $op, AttemptEvidence $attempt): ?array
    {
        $e = $op->policy['order_unproven'] ?? null;
        $before = $attempt->beforeExpiry ? substr($attempt->beforeExpiry->value, 0, 10) : null;
        if (!is_array($e) || !is_string($e['identity'] ?? null) || !hash_equals(RegistrarAnswers::identity($op), $e['identity'])
            || ($e['order_reference'] ?? null) !== $op->provider_order_id || $before === null || ($e['owned'] ?? null) !== true
            || ($e['expiry'] ?? null) !== $before || !is_array($e['expiry_reads'] ?? null) || count($e['expiry_reads']) < 3) { return null; }
        foreach ($e['expiry_reads'] as $read) { if ($read !== $before) { return null; } }
        return $e;
    }
}
