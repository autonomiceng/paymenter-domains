<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Data\DomainSnapshot;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Collection fence only. A missing holding is not proof to retire debt or release its name. */
final class RenewalOwnership
{
    public static function blocked(Domain $d): bool
    {
        if (!in_array($d->status, ['active', 'expired', 'grace'], true)) { return false; }
        $proof=$d->observation['renewal_ownership']??null;
        if (is_array($proof)&&($proof['identity']??null)===ObservationRecords::identity($d)) { return ($proof['owned']??null)===false; }
        // Existing stopped observers already retain definite not-in-account evidence.
        return HoldingReadRetry::absent($d);
    }
    /** Caller holds the observation's identity/stamp locks. Unknown reads retain the last definite answer. */
    public static function observe(Domain $d, ?DomainSnapshot $facts, \DateTimeImmutable $now): void
    {
        $owned=$facts?->owned; $at=$now->format(DATE_ATOM);
        // A positive read of a different provider identity cannot lift retained absence.
        if ($owned===true&&$d->provider_domain_id!==null&&$facts->providerReference!==$d->provider_domain_id) {
            if (!self::blocked($d)) { return; }
            $owned=false; $at=$d->observation['renewal_ownership']['at']??$d->observation['record_failure']['at']??$at;
        }
        if ($owned===null||!in_array($d->status, ['active', 'expired', 'grace'], true)) { return; }
        $d->update(['observation'=>[...($d->observation??[]), 'renewal_ownership'=>[
            'identity'=>ObservationRecords::identity($d), 'owned'=>$owned, 'at'=>$at]]]);
        self::review($d,$now->getTimestamp());
    }
    /** Staff must verify departure; absence alone cannot safely settle existing money or release a name. */
    public static function review(Domain $d, int $now): ?string
    {
        if (!Schema::hasTable('domains_obligation_alerts')) { return null; }
        if (self::blocked($d)) { return ObligationAlerts::record($d,'holding_absent',$now); }
        DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','holding_absent')
            ->whereNull('resolved_at')->update(['resolved_at'=>$now]);
        return null;
    }
}
