<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchAudit};
/** Local billing preference. Issued invoices and registrar settings retain their own authority. */
final readonly class OwnerAutoRenew
{
    public function __construct(private Clock $clock) {}
    public static function available(array $l): bool
    {
        $d=$l['domain'];$service=$l['service']??$d->service;
        return !$d->adoptionPending() && $d->billing_managed && $d->status==='active'
            && $d->provider_expires_at!==null && $service?->status==='active' && !RenewalStop::blocked($l);
    }
    public function set(User $actor,int $id,bool $enabled): void
    {
        OperationClaims::outside();$seed=Domain::findOrFail($id);
        DB::transaction(function()use($actor,$seed,$enabled):void {
            $l=HoldingLocks::take($seed);$d=$l['domain'];
            if((int)$actor->id!==(int)$l['user']->id || (int)$actor->id!==(int)$d->user_id) {
                throw new \DomainException('Domain renewal is unavailable.');
            }
            if(!self::available($l)){throw new \DomainException('Auto-renew cannot be changed for this domain.');}
            if($enabled===!RenewalStop::ownerOff($l)){return;}
            if(!$d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'owner_auto_renew'=>$enabled]])){throw new \DomainException('Auto-renew could not be saved.');}
            $audit=WatchAudit::create(['subject_type'=>'domain','subject_id'=>$d->id,'user_id'=>$d->user_id,'actor_id'=>$actor->id,
                'event'=>'owner_auto_renew','before_revision'=>$enabled?'off':'on','after_revision'=>$enabled?'on':'off',
                'data'=>['enabled'=>$enabled],'created_at'=>$this->clock->now()]);
            if(!$audit->exists){throw new \DomainException('Auto-renew audit could not be saved.');}
        });
    }
}
