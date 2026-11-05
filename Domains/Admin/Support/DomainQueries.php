<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
/** Exact holding binding, independent of today's seller. No financial interpretation of state labels. */
final class DomainQueries
{
    public static function operations(Domain $d): Builder
    {
        return $d->operations()->getQuery()->where('service_id',$d->service_id)->where('server_id',$d->server_id)
            ->where('registrar_key',$d->registrar_key)->where('account_fingerprint',$d->account_fingerprint)
            ->where('authority_generation',$d->authority_generation);
    }
    /** Rows whose status (DomainFacts::status) falls in one of the DomainStatus::SET keys.
     * The rule reads encrypted request data and has one copy, so rows are sorted in PHP, 200 at a time. */
    public static function status(Builder $q, array $keys): Builder
    {
        if (!$keys) { return $q; }
        return self::rows($q,fn(array $status)=>in_array($status['key'],$keys,true));
    }
    /** Alerting rows: an open alert that still applies, on a row that asks staff to act. OK, outdated and ended rows are not alerting.
     * The account page's count, the Open alerts filter and the menu badge all use this one rule. */
    public static function alerting(Builder $q): Builder
    {
        return self::rows(self::openAlerts($q),fn(array $status,array $inputs)=>$inputs['alerts']!==[]&&$status['act']);
    }
    /** Rows whose staff status (DomainFacts::status) and its inputs pass $keep. */
    private static function rows(Builder $q, \Closure $keep): Builder
    {
        $ids=[];
        (clone $q)->reorder()->with('latestAcquisition')->chunkById(200,function ($page) use (&$ids,$keep): void {
            $inputs=StaffDomainInputs::for($page);
            foreach ($page as $d) {
                if ($keep(DomainFacts::status($d,$inputs[$d->id]),$inputs[$d->id])) { $ids[]=$d->id; }
            }
        },'domains_domains.id','id');
        return $q->whereIntegerInRaw('domains_domains.id',$ids);
    }
    public static function attention(Builder $q, array $states = ['held','uncertain']): Builder
    {
        return self::related($q,fn($ops)=>$ops->whereIn('attention.state',$states));
    }
    /** Unresolved alerts, excluding staff releases through the detail list's shared read scope. */
    public static function openAlerts(Builder $q): Builder
    {
        return $q->whereExists(ObligationAlerts::open()->selectRaw('1')
            ->whereColumn('domains_obligation_alerts.domain_id','domains_domains.id'));
    }
    /** Domains whose row shows an alert. The SQL alert rows only pick the candidates. */
    public static function openAlertCount(Builder $q): int
    {
        return self::alerting($q)->count();
    }
    public static function creditReview(Builder $q): Builder
    {
        return self::related($q,fn($ops)=>RefundCandidates::query($ops,'attention.'));
    }
    public static function registrarRejected(Builder $q): Builder
    {
        return self::related($q,fn($ops)=>$ops->where('attention.action','transfer')->where('attention.state','rejected')
            ->where('attention.safe_code','transfer_provider_rejected'));
    }
    private static function related(Builder $q, \Closure $conditions): Builder
    {
        // Correlated SQL keeps filters bounded and includes NameSilo's legitimate no-ID operations.
        return $q->whereExists(function ($ops) use ($conditions) {
            $ops->selectRaw('1')->from('domains_operations as attention')
                ->whereColumn('attention.domain_id','domains_domains.id')
                ->whereColumn('attention.service_id','domains_domains.service_id')
                ->whereColumn('attention.server_id','domains_domains.server_id')
                ->whereColumn('attention.registrar_key','domains_domains.registrar_key')
                ->whereColumn('attention.account_fingerprint','domains_domains.account_fingerprint')
                ->whereColumn('attention.authority_generation','domains_domains.authority_generation');
            $conditions($ops);
        });
    }
}
