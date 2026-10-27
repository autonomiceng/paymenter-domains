<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Pure UTC episode policy. Inputs are normalized, current owner-validated public evidence only. */
final class WatchNoticePlan
{
    public const WINDOWS=[90,60,30,14,7,1,0];
    public static function day(int $time): int { return intdiv($time,86400); }
    public static function key(int $owner,string $name,string $lineage,string $episode,string $kind,?int $threshold=null,?string $channel=null): string
    { return hash('sha256',json_encode([$owner,$name,$lineage,$episode,$kind,$threshold,$channel],JSON_THROW_ON_ERROR)); }
    /** Returns retained state, immediate portal conditions, and at most one new expiry notice. */
    public static function expiry(array $state,?array $fact,int $now): array
    {
        $previous=max($state['evaluated_at']??0,$state['seen_at']??0);
        $out=['state'=>$state,'notices'=>[],'portal'=>self::expiryPortal($state,max($now,$previous))];
        if ($now<0 || $now<$previous) { $out['health']='clock_reversal';return $out; }
        $state['evaluated_at']=$now;$out['state']=$state;
        if (!self::fresh($fact,$now) || !self::date($fact['expiry']??null)) {
            $out['portal'][]='expiry_unavailable';return $out;
        }
        $date=$fact['expiry'];$days=self::day((int)strtotime($date.' UTC'))-self::day($now);
        $old=$state['expiry']??null;$first=$old===null;$changed=$old!==$date;
        $earlier=$old!==null && $date<$old;
        $state['seen_at']=$now;$state['expiry']=$date;
        if ($changed) { $state['correction_pending']=$earlier; }
        $state['keys']??=[];
        $out['portal']=$days<=0?['registry_expiry_reached']:[];
        if ($changed && $old!==null) { $out['resolve']=$old; }
        $window=null;
        foreach(self::WINDOWS as $candidate){
            if ($days<=$candidate && ($first || $changed || ($state['correction_pending']??false) || $days>=$candidate-2)) { $window=$candidate; }
        }
        if ($window===null) { $state['correction_pending']=false; }
        if ($changed && $days>0) { $state['expired_notified']=false; }
        if ($window===0 && ($state['expired_notified']??false)) { $window=null;$state['correction_pending']=false; }
        $key=$date.':'.$window;
        if ($window!==null && isset($state['keys'][$key])) { $state['correction_pending']=false; }
        if ($window!==null && !isset($state['keys'][$key]) && (!($earlier || ($state['correction_pending']??false)) || $now>=($state['last_notice_at']??0)+86400)) {
            $state['keys'][$key]=$now;$state['last_notice_at']=$now;$state['correction_pending']=false;
            if ($window===0) { $state['expired_notified']=true; }
            $out['notices'][]=['kind'=>$window===0?'expired':'expiring','episode'=>$date,'threshold'=>$window,
                'date'=>$date,'observation_id'=>$fact['id'],'priority'=>$window<=14?0:2];
        }
        $out['state']=$state;return $out;
    }
    /** Keep episode continuity across relink, without carrying any predecessor evidence. */
    public static function relinked(array $state): array
    { return array_intersect_key($state,array_flip(['lineage','episode','delivered'])); }
    /** ns_confirmed is the current observer's two-sample proof, never a predecessor pointer. */
    public static function dns(array $state,?array $fact,int $now,bool $expectedPlatform): array
    {
        $out=['state'=>$state,'notices'=>[],'portal'=>isset($state['episode'])?['ns_changed']:[]];
        if ($now<0 || $now<max($state['evaluated_at']??0,$state['seen_at']??0)) { $out['health']='clock_reversal';return $out; }
        $state['evaluated_at']=$now;$out['state']=$state;
        if (!$expectedPlatform) {
            if (isset($state['episode'])) { $out['resolve']=$state['episode']; }
            $out['state']=[];$out['portal']=[];return $out;
        }
        if (!self::fresh($fact,$now) || (!in_array($fact['dns']??null,['platform','external'],true) || !is_string($fact['lineage']??null) || $fact['lineage']==='')) {
            $out['portal'][]='dns_unavailable';return $out;
        }
        $state['seen_at']=$now;
        if (($state['lineage']??null)!==$fact['lineage']) {
            if (isset($state['episode'])) { $out['resolve']=$state['episode']; }
            $state=['seen_at'=>$now,'evaluated_at'=>$now,'lineage'=>$fact['lineage']];$out['portal']=[];
        }
        // Revisions restart confirmation in the observer, but never change episode identity.
        $confirmed=($fact['ns_confirmed']??false)===true;
        if ($fact['dns']==='external') {
            if ($confirmed && !isset($state['episode'])) {
                $episode=(string)$fact['at'];$state['episode']=$episode;$state['delivered']=[];
                $out['notices'][]=['kind'=>'ns_changed','episode'=>$episode,'threshold'=>null,
                    'date'=>gmdate('Y-m-d',$fact['at']),'observation_id'=>$fact['id'],'priority'=>1];
            }
            $out['portal']=[isset($state['episode'])?'ns_changed':'ns_pending'];
        } elseif ($confirmed && isset($state['episode'])) {
            $episode=$state['episode'];$out['resolve']=$episode;$out['portal']=[];
            if (!empty($state['delivered'])) {
                $out['notices'][]=['kind'=>'ns_restored','episode'=>$episode,'threshold'=>null,
                    'date'=>gmdate('Y-m-d',$fact['at']),'observation_id'=>$fact['id'],'priority'=>3,'channels'=>$state['delivered']];
            }
            unset($state['episode'],$state['delivered']);
        }
        if (isset($state['episode'])) { $out['portal']=['ns_changed']; }
        $out['state']=$state;return $out;
    }
    private static function expiryPortal(array $state,int $now): array
    { return self::date($state['expiry']??null) && self::day((int)strtotime($state['expiry'].' UTC'))<=self::day($now)?['registry_expiry_reached']:[]; }
    private static function fresh(?array $fact,int $now): bool
    { return $fact!==null && ($fact['current']??false)===true && ($fact['complete']??false)===true
        && is_int($fact['id']??null) && $fact['id']>0 && is_int($fact['at']??null) && $fact['at']<=$now
        && is_int($fact['valid_until']??null) && $now<$fact['valid_until']; }
    private static function date(mixed $date): bool
    { return is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date)===1
        && ($d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'))) && $d->format('Y-m-d')===$date; }
}
