<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** A read abandoned by a worker is a failed attempt, with no replay inside that exchange. */
final class PublicCheckRetry
{
    public static function abandoned(object $work,int $id,string $kind,int $now): array
    {
        $attempts=min(30,(int)$work->attempts+1);$delay=[900,3600,21600,86400][min(3,$attempts-1)];
        $due=$now+$delay+WatchRegistryChecks::jitter($id,$kind.'|'.$attempts,intdiv($delay,10));
        return ['claim'=>null,'lease_until'=>null,'attempts'=>$attempts,'due_at'=>$due,'backoff_until'=>$due,'safe_code'=>'deadline'];
    }
}
