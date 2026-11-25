<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Data\DomainSnapshot;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;
/** Collection fence only. A missing holding is not proof to retire debt or release its name. */
final class RenewalOwnership
{
    /** Shared holding review for billing protection and the later departure workflow. */
    public const REVIEW='departure_review';
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
        self::save($d,$owned,$at,$now);
    }
    /** Caller holds observation locks. Preserve legacy absence before record/failure writers replace its diagnostic. */
    public static function retainAbsence(Domain $d, \DateTimeImmutable $now, bool $definite=false): void
    {
        if (!in_array($d->status,['active','expired','grace'],true)||(!$definite&&!self::blocked($d))) { return; }
        $proof=$d->observation['renewal_ownership']??[];
        $prior=($proof['identity']??null)===ObservationRecords::identity($d)?($proof['at']??null):($d->observation['record_failure']['at']??null);
        $at=(!$definite&&is_string($prior))?$prior:$now->format(DATE_ATOM);
        self::save($d,false,$at,$now);
    }
    private static function save(Domain $d, bool $owned, string $at, \DateTimeImmutable $now): void
    {
        $d->update(['observation'=>[...($d->observation??[]), 'renewal_ownership'=>[
            'identity'=>ObservationRecords::identity($d), 'owned'=>$owned, 'at'=>$at]]]);
        self::review($d,$now->getTimestamp());
    }
    /** Staff must verify departure; absence alone cannot safely settle existing money or release a name. */
    public static function review(Domain $d, int $now): ?string
    {
        if (!Schema::hasTable('domains_obligation_alerts')) { return null; }
        if (HoldingReadRetry::absent($d) && $d->status !== 'departed') {
            $open=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',self::REVIEW)->whereNull('resolved_at')->exists();
            $key=ObligationAlerts::record($d,self::REVIEW,$now);
            // One bell notice when the review opens; the receipt keeps a repeated or replayed opening silent.
            if (!$open) { $id=(int)$d->id; $body=$d->domain.' is not in our registrar account.';
                DB::afterCommit(static fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send('Domain under review',$body,'filament.admin.resources.domains.view',
                    \Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'departure:'.$key.':'.$now,['record'=>$id])); }
            return $key;
        }
        DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',self::REVIEW)
            ->whereNull('resolved_at')->update(['resolved_at'=>$now]);
        return null;
    }
}
