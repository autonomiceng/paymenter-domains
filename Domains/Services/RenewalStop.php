<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\ServiceCancellation;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Pure predicates; write callers hold HoldingLocks. Issued obligations retain authority. */
final class RenewalStop
{
    /** Read-only presentation state; writers use the locked cancellation set. */
    public static function read(Domain $d): array
    {return ['domain'=>$d,'cancel'=>NativeCancellation::withIntents(ServiceCancellation::where('service_id',$d->service_id)->get(),[(int)$d->service_id],true)];}
    public static function stopped(array $l): bool
    {return (bool)($l['domain']->renewal_policy['renewals_stopped']??false)||$l['cancel']->contains('service_id',$l['domain']->service_id);}
    public static function blocksExisting(array $l): bool
    {return $l['cancel']->where('service_id',$l['domain']->service_id)->contains(fn($r)=>!($r instanceof ServiceCancellation)||$r->type!=='end_of_period');}
}
