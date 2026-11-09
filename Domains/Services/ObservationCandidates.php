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
                if ($kind==='refund') { RefundCandidates::paid($ops); }
                else { RuntimeCandidates::transfer($ops)->whereIn('state',['awaiting_code','awaiting_payment','held']); }
                $ids=$ops->orderBy('id')->limit($limit)->pluck('id'); $result[$row->server_id]=$ids->all();
                $update=[$kind.'_turn'=>++$turn,$cursor=>$ids->count()===$limit?$ids->last():0];
                if ($kind==='refund') { [$closures,$next]=self::closures($row,$limit); $result[$row->server_id]=[...$ids->all(),...$closures]; if ($next!==null) { $update[ClosureCursorSchema::COLUMN]=$next; } }
                (clone $q)->where('id',$row->id)->update($update);
            }
            return $result;
        });
    }
    /** Complimentary refusals move no money, and an unproven one stays unproven until its evidence changes.
     * They take a separate page with their own cursor on the locked queue row, so they never delay a paid return and every page gets a turn.
     * Before the cursor column is installed the page starts over each turn: [ids, next cursor or null]. */
    private static function closures(object $row, int $limit): array
    {
        $stored=property_exists($row,ClosureCursorSchema::COLUMN); $cursor=$stored?(int)$row->{ClosureCursorSchema::COLUMN}:0;
        $ids=RefundCandidates::complimentary(Operation::where('server_id',$row->server_id)->where('account_fingerprint',$row->account_fingerprint)
            ->where('id','>',$cursor))->orderBy('id')->limit($limit)->pluck('id');
        return [$ids->all(),$stored?($ids->count()===$limit?$ids->last():0):null];
    }
}
