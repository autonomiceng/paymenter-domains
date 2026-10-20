<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,SalesPolicy,WatchName};
/** Owner-scoped local projection. Read evidence is obtained separately, never on GET. */
final class WatchTransfer
{
    public function __construct(private Storefront $storefront,private DomainSummaries $summaries) {}
    public function current(User $owner,int $id,int $now): array
    {
        $watch=WatchName::whereKey($id)->where('user_id',$owner->id)->firstOrFail();$offer=null;
        $summary=$this->summaries->one($owner,'watch',$id,$now);
        if (config('domains.watch_transfer.enabled',false) && $watch->state==='active' && $watch->registration==='external' && $summary
            && !($summary->freshness['registry']['stale']??true) && WatchTransferPolicy::window(substr($summary->expiry??'',0,10),$now)
            && !Domain::where('reservation_name',$watch->canonical_name)->exists()) {
            try {
                $ending=CanonicalName::parse($watch->canonical_name,SalesPolicy::pluck('tld')->all())['tld'];
                $offer=$this->storefront->offer($ending,'transfer',1);TransferPrecheck::rules($offer['selection']);
            } catch (\Throwable) { $offer=null; }
        }
        return ['watch'=>$watch,'offer'=>$offer,'stamp'=>$offer?hash('sha256',json_encode([$watch->canonical_name,(int)$watch->revision,
            get_object_vars($offer['selection']),$offer['price'],$offer['renew'],$summary->expiry,$summary->freshness['registry']['stale'],$summary->freshness['registry']['checkedAt']],JSON_THROW_ON_ERROR)):null];
    }
}
