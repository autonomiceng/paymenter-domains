<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\AccountSpendingHolds;
/** Read-only projection, never a settlement or spending-release authority. */
final class PaidUncertainty
{
    public static function summary(Operation $op): ?string
    {
        Staff::requireView();
        if ($op->funding_basis!=='paid_invoice' || (!in_array($op->state,['running','pending','uncertain'],true)
            && !in_array($op->safe_code,['transfer_provider_rejected','transfer_needs_action','management_provider_refused'],true))) { return null; }
        return 'Paid outcome review · '.self::details($op)
            .' · Missing: verified original outcome and charge or settlement evidence. No staff release or credit is available.';
    }
    public static function holdSummary(Operation $op): ?string
    {
        Staff::requireView();
        return AccountSpendingHolds::matches($op)?'Account spending hold · '.self::details($op)
            .' · Missing: verified original outcome'.($op->funding_basis==='paid_invoice'?' and charge or settlement evidence':'')
            .'. Re-check the original attempt; no staff release is available.':null;
    }
    private static function details(Operation $op): string
    {
        $at=$op->started_at??$op->created_at;
        $age=$at ? max(0,(int)floor((time()-$at->getTimestamp())/3600)).' hours' : 'Unknown age';
        return 'Request #'.$op->id.' · '.Labels::action($op->action).' · '.Labels::state($op->state)
            .' · '.($op->safe_code===null?'Original outcome unresolved':Labels::reason($op->safe_code))
            .' · '.$age.($at?' (since '.$at->format('Y-m-d H:i').' UTC)':'');
    }
    public static function for(Domain $d): array
    {
        Staff::requireView();
        return self::rows(AccountSpendingHolds::query(Operation::where('account_fingerprint',$d->account_fingerprint)),self::holdSummary(...));
    }
    public static function reviews(Domain $d): array
    {
        Staff::requireView();
        return self::rows(OperationQueries::paidUncertainty(Operation::where('account_fingerprint',$d->account_fingerprint))->whereNotIn('state',['running','pending','uncertain']),self::summary(...));
    }
    private static function rows(\Illuminate\Database\Eloquent\Builder $query,\Closure $summary): array
    {
        $rows=$query->orderBy('id')->limit(26)->get();$out=$rows->take(25)->map($summary)->filter()->values()->all();
        if($rows->count()>25){$out[]='More requests exist. Open Registrar requests for review.';}return $out;
    }
}
