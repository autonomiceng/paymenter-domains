<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Fair durable selection only. Consumers must independently authorize timeout/credit under holding locks. */
final class ObservationCandidates
{
    public static function next(string $kind, int $accounts=8, int $limit=25): array
    {
        OperationClaims::outside();
        if (!in_array($kind,['refund','timeout'],true)||$accounts<1||$accounts>32||$limit<1||$limit>100) { throw new \InvalidArgumentException('Invalid candidate batch.'); }
        return DB::transaction(function () use ($kind,$accounts,$limit): array {
            $q=DB::table('domains_observation_queues'); $rows=(clone $q)->orderBy($kind.'_turn')->orderBy('server_id')->limit($accounts)->lockForUpdate()->get();
            $turn=(int)$q->max($kind.'_turn'); $result=[];
            foreach ($rows as $row) {
                $cursor=$kind.'_cursor'; $ops=Operation::where('server_id',$row->server_id)->where('account_fingerprint',$row->account_fingerprint)->where('id','>',$row->$cursor);
                if ($kind==='refund') { RefundCandidates::query($ops); }
                else { $ops->where('action','transfer')->whereIn('state',['awaiting_code','awaiting_payment','held']); }
                $ids=$ops->orderBy('id')->limit($limit)->pluck('id'); $result[$row->server_id]=$ids->all();
                (clone $q)->where('id',$row->id)->update([$kind.'_turn'=>++$turn,$cursor=>$ids->count()===$limit?$ids->last():0]);
            }
            return $result;
        });
    }
}
