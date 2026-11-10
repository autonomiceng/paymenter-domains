<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,RenewalCode};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A paid Enom renewal waiting out Enom's 24-hour repeat-renewal refusal. The time it may go is fixed when the wait
 * starts: the last finished renewal of the same domain plus 24 hours. Staff and customers read the same words. */
final class RenewalRepeatWait
{
    public const HOURS = 24;
    /** Why it waits, in customer words. */
    public const WHY = 'The registrar accepts one renewal of a domain every 24 hours.';
    /** Ends with the time, which each page draws in the viewer's time zone. */
    public const GOES = self::WHY.' The renewal goes to the registrar automatically at';
    public const NOTHING = 'Nothing more to pay or do.';
    /** Staff only: why it waits. Lists show it as the request's reason. */
    public const STAFF = 'Waiting: Enom refuses a second renewal of the same domain within 24 hours';
    /** Ends with the time: the Renew dialog says it before an invoice is made. */
    public const NEXT = self::WHY.' If you renew now, the renewal goes to the registrar automatically at';
    /** When a new renewal of this Enom domain may go: the latest completed renewal plus 24 hours, or null when none
     * finished that recently. Execution holds a paid renewal on it; the Renew dialog says it before payment. */
    public static function next(Domain $d, iterable $operations, \DateTimeImmutable $now, AccountBinding $binding): ?\DateTimeImmutable
    {
        if ($d->registrar_key !== 'enom') { return null; }
        $until = null;
        foreach ($operations as $prior) {
            if ($prior->action !== 'renew' || $prior->completed_at === null) { continue; }
            $ends = \DateTimeImmutable::createFromInterface($prior->completed_at)->modify('+'.self::HOURS.' hours');
            if ($ends > $now && ($until === null || $ends > $until) && RenewalAuthority::completed($prior, $d, $binding)) { $until = $ends; }
        }
        return $until;
    }
    /** next() for a page: reads the domain's renewals and account. Null when anything is missing. */
    public static function forDomain(Domain $d, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($d->registrar_key !== 'enom') { return null; }
        try {
            $ops = Operation::where('domain_id', $d->id)->where('action', 'renew')->whereNotNull('completed_at')->get();
            return $ops->isEmpty() ? null : self::next($d, $ops, $now, AccountBindings::binding(Account::where('server_id', $d->server_id)->sole()));
        } catch (\Throwable) { return null; }
    }
    /** The fixed time a waiting renewal may go, or null when it is not waiting on this. */
    public static function until(Operation $op): ?\DateTimeImmutable
    {
        if ($op->action !== 'renew' || $op->state !== 'queued' || $op->safe_code !== RenewalCode::Repeat->value) { return null; }
        $at = $op->policy['renewal_execution']['eligible_at'] ?? null;
        if (!is_string($at)) { return null; }
        try { return new \DateTimeImmutable($at); } catch (\Throwable) { return null; }
    }
}
