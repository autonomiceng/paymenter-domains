<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Account;
/**
 * One limit per Enom TEST login: a rolling 24-hour ceiling and a per-minute rate.
 * Checked once right before each physical send. Every admitted attempt stays counted, sent or not.
 */
final class EnomTestSendLimit
{
    public const PER_DAY='domains.enom_test_sends_per_day';
    public const PER_MINUTE='domains.enom_test_sends_per_minute';
    /** @return array{day:?int,minute:?int} null when the setting is invalid */
    public static function limits(): array
    { return ['day'=>self::setting(self::PER_DAY,1000),'minute'=>self::setting(self::PER_MINUTE,60)]; }
    private static function setting(string $key,int $default): ?int
    { $value=config($key,$default);return is_int($value)&&$value>=0&&$value<=100000?$value:null; }
    /** The saved login, or null when it is missing or cannot be decrypted. */
    public static function login(Account $account): ?string
    { try{$uid=$account->stable_identity;return is_string($uid)&&$uid!==''?$uid:null;}catch(\Throwable){return null;} }
    /** The same key LIVE uses: server rows that share a login share a limit. */
    public static function account(string $uid): string { return hash('sha256',strtolower($uid)); }
    public static function admit(Environment $environment,string $uid,string $command): void
    {
        if($environment!==Environment::Test){return;}
        try {
            // Inside an outer transaction a rollback could refund the attempt.
            OperationClaims::outside();
            ['day'=>$perDay,'minute'=>$perMinute]=self::limits();
            if(!$perDay||!$perMinute||$uid===''||!preg_match('/^[A-Za-z_]{1,32}$/D',$command)){throw new \DomainException;}
            $account=self::account($uid);
            // A deadlock rolls back with nothing written, so the whole check is retried.
            DB::transaction(static function()use($account,$command,$perDay,$perMinute):void {
                $row=DB::table(EnomTestSendLimitSchema::ACCOUNTS)->where('account',$account);
                // SQLite writes first to take its write lock before any count is read. MariaDB locks the row
                // first: an insert that finds the row takes a shared lock, and two of those deadlock.
                if(DB::getDriverName()==='sqlite'||!(clone $row)->lockForUpdate()->exists()){
                    DB::table(EnomTestSendLimitSchema::ACCOUNTS)->insertOrIgnore(['account'=>$account]);
                }
                (clone $row)->lockForUpdate()->sole();
                $now=self::now();$sends=fn(int $seconds)=>DB::table(EnomTestSendLimitSchema::SENDS)->where('account',$account)->where('sent_at','>',$now-$seconds)->count();
                if($sends(86400)>=$perDay||$sends(60)>=$perMinute){throw new \DomainException;}
                DB::table(EnomTestSendLimitSchema::SENDS)->insert(['account'=>$account,'command'=>$command,'sent_at'=>$now]);
            },3);
        } catch(\Throwable) { EnomTestCalls::refuse(); }
    }
    private static function now(): int { return app(Clock::class)->now()->getTimestamp(); }
    /** @return array{day:?int,day_limit:?int,minute:?int,minute_limit:?int} counts are null when unreadable */
    public static function usage(string $uid): array
    {
        ['day'=>$perDay,'minute'=>$perMinute]=self::limits();
        try {
            $now=self::now();$sends=fn(int $seconds)=>DB::table(EnomTestSendLimitSchema::SENDS)->where('account',self::account($uid))->where('sent_at','>',$now-$seconds)->count();
            [$day,$minute]=[$sends(86400),$sends(60)];
        } catch(\Throwable) { [$day,$minute]=[null,null]; }
        return ['day'=>$day,'day_limit'=>$perDay,'minute'=>$minute,'minute_limit'=>$perMinute];
    }
    /** Plain staff facts for one login, or only the limits when the login is not known yet. */
    public static function facts(?string $uid): string
    {
        $u=self::usage((string)$uid);
        if($u['day_limit']===null||$u['minute_limit']===null){return 'The Enom test send limit in server config is invalid, so no Enom test requests are sent. Ask your server administrator to fix it.';}
        if($u['day_limit']===0||$u['minute_limit']===0){return 'The Enom test send limit is 0, so no Enom test requests are sent.';}
        if($uid===null||$uid===''){return 'Enom test requests are limited per login to '.$u['day_limit'].' in any 24 hours and '.$u['minute_limit'].' per minute.';}
        if($u['day']===null){return "Couldn't read the Enom test send count, so Enom test requests are refused. Try again.";}
        $summary=$u['day'].' of '.$u['day_limit'].' sends in the last 24 hours and '.$u['minute'].' of '.$u['minute_limit'].' in the last minute for this login. Failed sends count too.';
        return $u['day']>=$u['day_limit']||$u['minute']>=$u['minute_limit']?$summary.' Limit reached. Try again later.':$summary;
    }
}
