<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\ServiceCancellation;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};
/** Pure predicates; write callers hold HoldingLocks. Issued obligations retain authority. */
final class RenewalStop
{
    /** Read-only presentation state; writers use the locked cancellation set. */
    public static function read(Domain $d): array
    {return ['domain'=>$d,'adoption'=>$d->adoption_id === null ? null : Adoption::find($d->adoption_id),'cancel'=>NativeCancellation::withIntents(ServiceCancellation::where('service_id',$d->service_id)->get(),[(int)$d->service_id],true)];}
    /** Original adoption intent remains a stop even if a native cancellation is later removed. */
    public static function adopted(array $l): bool
    {
        if ($l['domain']->provenance !== 'adopted') { return false; }
        $a = $l['adoption'] ?? null;
        return !$a || $a->do_not_renew;
    }
    /** All future automatic renewal stops, including the owner's reversible preference. */
    public static function stopped(array $l): bool
    {return self::blocked($l)||self::ownerOff($l);}
    /** Hard stops refuse new manual as well as scheduled renewal obligations. */
    public static function blocked(array $l): bool
    {return self::offReason($l)!==null && self::offReason($l)!=='owner';}
    public static function ownerOff(array $l): bool
    {return ($l['domain']->renewal_policy['owner_auto_renew']??true)===false;}
    /** Cancellation, staff and adoption intent take precedence over the owner preference. */
    public static function offReason(array $l): ?string
    {return match(true){
        $l['cancel']->contains('service_id',$l['domain']->service_id)=>'cancellation',
        (bool)($l['domain']->renewal_policy['renewals_stopped']??false)=>'staff',
        self::adopted($l)=>'adopted',self::ownerOff($l)=>'owner',default=>null};}
    /** Issued obligations survive future-only stops; adopted intent and immediate cancellation still block execution. */
    public static function blocksRenewal(array $l): bool
    {return self::adopted($l)||self::blocksExisting($l);}
    public static function blocksExisting(array $l): bool
    {return $l['cancel']->where('service_id',$l['domain']->service_id)->contains(fn($r)=>!($r instanceof ServiceCancellation)||$r->type!=='end_of_period');}
}
