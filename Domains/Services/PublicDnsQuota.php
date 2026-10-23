<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
/** Shared public pool only. Admission is durable and never waits or borrows registrar quotas. */
final class PublicDnsQuota
{
    public const TABLE = 'domains_public_dns_pool';
    public static function seed(int $now): void
    { DB::table(self::TABLE)->insertOrIgnore(['id'=>1,'window_at'=>$now,'used'=>0,'active'=>'[]']); }
    public function acquire(int $now): ?string
    {
        if (DB::transactionLevel() !== 0 || $now < 0) { return null; }
        return DB::transaction(function()use($now):?string {
            self::seed($now); $row=DB::table(self::TABLE)->where('id',1)->lockForUpdate()->first();
            $active=array_filter(json_decode($row->active,true,512,JSON_THROW_ON_ERROR),static fn($v)=>$v['until']>$now);
            if (count($active)>=2) { return null; }
            $token=bin2hex(random_bytes(32)); $active[$token]=['until'=>$now+120];
            DB::table(self::TABLE)->where('id',1)->update(['active'=>json_encode($active,JSON_THROW_ON_ERROR)]); return $token;
        });
    }
    public function reserve(string $token, int $now): bool
    {
        if (DB::transactionLevel() !== 0) { return false; }
        return DB::transaction(function()use($token,$now):bool {
            $q=DB::table(self::TABLE)->where('id',1); $row=$q->lockForUpdate()->first();
            if (!$row) { return false; } $active=json_decode($row->active,true,512,JSON_THROW_ON_ERROR);
            if (!isset($active[$token]) || $active[$token]['until']<=$now || $now<(int)$row->window_at) { return false; }
            $reset=$now>=(int)$row->window_at+60; $used=$reset?0:(int)$row->used;
            if ($used>=60) { return false; }
            $q->update(['used'=>$used+1,'window_at'=>$reset?$now:$row->window_at]); return true;
        });
    }
    public function release(string $token): void
    {
        DB::transaction(function()use($token):void {
            $q=DB::table(self::TABLE)->where('id',1); $row=$q->lockForUpdate()->first(); if (!$row) { return; }
            $active=json_decode($row->active,true,512,JSON_THROW_ON_ERROR); unset($active[$token]);
            $q->update(['active'=>json_encode($active,JSON_THROW_ON_ERROR)]);
        });
    }
}
