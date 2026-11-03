<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Resume sync on a domain with no request to hold the review. Same locks, permission and writer as StaffOperationReview, with the row in the domain's staff history. */
final class StaffSyncReview
{
    private const ACTION='resume_observation';
    public function __construct(public readonly int $actorId, private readonly int $domainId, private readonly string $stamp, private readonly string $reason, private readonly Clock $clock) {}
    public static function stamp(Domain $d): string { return hash('sha256',json_encode($d->getRawOriginal(),JSON_THROW_ON_ERROR)); }
    public function check(array $l): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Staff review requires the outer writer transaction.');}
        $d=$l['domain']; $staff=$l['staff']??null;
        if((int)$staff?->id!==$this->actorId){throw new StaffReviewRefused("You don't have permission to do this.");}
        StaffOperationReview::requirePermission($staff,self::ACTION,null);
        StaffReason::require($this->reason);
        if((int)$d->id!==$this->domainId||!hash_equals($this->stamp,self::stamp($d))||!is_array($d->renewal_policy['staff_holdings']??[])){throw new StaffReviewRefused('This domain changed. Reload the page and try again.');}
        if(count($d->renewal_policy['staff_holdings']??[])>=100){throw new StaffReviewRefused('History for this domain is full. Ask your site admin.');}
    }
    public function record(array $l): void
    {
        $d=$l['domain']; $policy=$d->renewal_policy??[];
        $d->update(['renewal_policy'=>[...$policy,'staff_holdings'=>[...($policy['staff_holdings']??[]),
            ['actor_id'=>$this->actorId,'action'=>self::ACTION,'reason'=>$this->reason,'at'=>$this->clock->now()->format(DATE_ATOM)]]]]);
    }
}
