<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\AccountSpendingHolds;
/** Read-only projection, never a settlement or spending-release authority. */
final class PaidUncertainty
{
    /** Paid and the registrar result is not known yet: the request notice title. */
    public static function summary(Operation $op, ?StaffGrants $grants = null): ?string
    {
        $grants ??= Staff::requireView();
        if ($op->funding_basis!=='paid_invoice' || (!in_array($op->state,['running','pending','uncertain'],true)
            && !in_array($op->safe_code,['transfer_provider_rejected','transfer_needs_action','management_provider_refused'],true))) { return null; }
        return self::title($op);
    }
    /** The notice line while this request pauses new orders on its registrar account. */
    public static function holdSummary(Operation $op, ?StaffGrants $grants = null): ?string
    {
        $grants ??= Staff::requireView();
        if (!AccountSpendingHolds::matches($op)) { return null; }
        $at=$op->started_at??$op->created_at;
        $hours=$at ? max(0,(int)floor((time()-$at->getTimestamp())/3600)) : null;
        $age=match($hours){null=>'',0=>' (under 1 hour so far)',1=>' (1 hour so far)',default=>' ('.$hours.' hours so far)'};
        return 'New orders on this '.self::registrar($op).' account are paused until the result is known'.$age.'.';
    }
    public static function title(Operation $op): string
    { return self::registrar($op).' has not confirmed this '.(['register'=>'registration','transfer'=>'transfer','renew'=>'renewal'][$op->action]??'change').'.'; }
    public static function registrar(Operation $op): string { return ['enom'=>'Enom','namesilo'=>'NameSilo'][$op->registrar_key]??'Registrar'; }
    /** Requests pausing new orders on this domain's registrar account. */
    public static function for(Domain $d): array
    {
        $grants=Staff::requireView();
        return self::rows(AccountSpendingHolds::query(Operation::where('account_fingerprint',$d->account_fingerprint)),fn($op)=>self::holdSummary($op,$grants)?'Request #'.$op->id.' unconfirmed':null);
    }
    /** Paid requests on this domain's registrar account whose result is not known. */
    public static function reviews(Domain $d): array
    {
        $grants=Staff::requireView();
        return self::rows(OperationQueries::paidUncertainty(Operation::where('account_fingerprint',$d->account_fingerprint))->whereNotIn('state',['running','pending','uncertain']),fn($op)=>self::summary($op,$grants)?'Request #'.$op->id.' unconfirmed':null);
    }
    private static function rows(\Illuminate\Database\Eloquent\Builder $query,\Closure $summary): array
    {
        $rows=$query->orderBy('id')->limit(26)->get();$out=$rows->take(25)->map($summary)->filter()->values()->all();
        if($rows->count()>25){$out[]='More requests are under review.';}return $out;
    }
}
