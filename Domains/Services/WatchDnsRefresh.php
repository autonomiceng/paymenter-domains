<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
/**
 * DNS template refreshes for the watch enrollment sweep. Each template scope keeps its own progress,
 * so a later edit never repeats work an earlier refresh already finished. Requests wait in their own
 * row until the next batch claim, so an active batch keeps its claim, cursor and snapshot.
 */
final class WatchDnsRefresh
{
    public const PENDING='enrollment_refresh';
    public const DEFAULT='default';
    public static function scope(?int $profile): string { return $profile===null?self::DEFAULT:'profile:'.$profile; }
    /** @param array<string,string> $keys scope => effective template id and revision */
    public static function request(array $keys): void
    {
        if(!$keys){return;}
        DB::transaction(static function()use($keys):void {
            DB::table('domains_watch_schedule')->insertOrIgnore(['scope'=>self::PENDING]);
            $q=DB::table('domains_watch_schedule')->where('scope',self::PENDING);$row=$q->lockForUpdate()->first();
            $pending=self::decode($row->data)['pending']??[];$pending=is_array($pending)?$pending:[];
            $started=self::normalize(self::decode(DB::table('domains_watch_schedule')->where('scope','enrollment')->value('data')))['keys'];
            foreach($keys as $scope=>$key){
                // One effective revision refreshes once; a check that began after it keeps its claim.
                if(($pending[$scope]??$started[$scope]??null)===$key){continue;}
                $pending[$scope]=$key;
            }
            $q->update(['data'=>json_encode(['pending'=>$pending])]);
        });
    }
    /** Older rows hold one shared flag and profile list that ran from cursor zero to the end of the pass. */
    public static function normalize(array $data): array
    {
        $refreshes=is_array($data['refreshes']??null)?$data['refreshes']:[];
        if($data['refresh_dns']??false){$refreshes[self::DEFAULT]??=['start'=>0,'wrapped'=>false];}
        foreach(is_array($data['refresh_profiles']??null)?$data['refresh_profiles']:[] as $id){$refreshes[self::scope((int)$id)]??=['start'=>0,'wrapped'=>false];}
        unset($data['refresh_dns'],$data['refresh_profiles'],$data['restart']);
        return [...$data,'refreshes'=>$refreshes,'keys'=>is_array($data['keys']??null)?$data['keys']:[]];
    }
    /** Caller holds the enrollment row lock. A newer key for a scope replaces its older progress from this cursor. */
    public static function claim(array $data,int $cursor): array
    {
        $q=DB::table('domains_watch_schedule')->where('scope',self::PENDING);$row=$q->lockForUpdate()->first();
        $pending=self::decode($row?->data)['pending']??[];
        foreach(is_array($pending)?$pending:[] as $scope=>$key){
            $data['refreshes'][$scope]=['start'=>$cursor,'wrapped'=>false];$data['keys'][$scope]=$key;
        }
        if($pending){$q->update(['data'=>null]);}
        return $data;
    }
    /** A refresh covers each watch once: above its start before the pass wraps, then up to its start. */
    public static function applies(array $data,?int $profile,int $id): bool
    {
        $r=$data['refreshes'][self::scope($profile)]??null;
        return is_array($r) && (($r['wrapped']??false)?$id<=(int)$r['start']:$id>(int)$r['start']);
    }
    public static function release(array $data,int $cursor,bool $complete): array
    {
        foreach($data['refreshes'] as $scope=>$r){
            $start=(int)($r['start']??0);
            if(($r['wrapped']??false)?($complete||$cursor>=$start):($complete&&$start===0)){unset($data['refreshes'][$scope]);}
            elseif($complete){$data['refreshes'][$scope]['wrapped']=true;}
        }
        return $data;
    }
    private static function decode(?string $json): array
    { $data=json_decode($json??'{}',true);return is_array($data)?$data:[]; }
}
