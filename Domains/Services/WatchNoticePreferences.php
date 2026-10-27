<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
/** Independent preferences never invalidate DNS/registry stamps or affect renewal notifications. */
final class WatchNoticePreferences
{
    public static function scope(int $owner,string $name): string { return hash('sha256',$owner.'|'.$name); }
    public static function account(int $owner): string { return hash('sha256','account|'.$owner); }
    public static function read(int $owner,string $name,bool $lock=false): array
    {
        $out=[];
        foreach(['account'=>self::account($owner),'target'=>self::scope($owner,$name)] as $key=>$scope) {
            $q=DB::table('domains_watch_preferences')->where('scope',$scope)->where('user_id',$owner);
            if ($lock) { DB::table('domains_watch_preferences')->insertOrIgnore(['scope'=>$scope,'user_id'=>$owner]); }
            $r=$q->when($lock,fn($q)=>$q->lockForUpdate())->first();$out[$key]=['enabled'=>$r?(bool)$r->enabled:true,'revision'=>$r?(int)$r->revision:1];
        }return $out;
    }
    /** Caller already holds current native owner/target locks; one POST updates both scopes. */
    public static function save(int $owner,string $name,int $accountRevision,int $targetRevision,bool $email,bool $notices): void
    {
        if (DB::transactionLevel()<1) { throw new \LogicException('Preference transaction required.'); }
        $p=self::read($owner,$name,true);
        if ($p['account']['revision']!==$accountRevision || $p['target']['revision']!==$targetRevision) { throw new \DomainException('Preferences changed. Reload and try again.'); }
        foreach([[self::account($owner),$email],[self::scope($owner,$name),$notices]] as [$scope,$enabled]) {
            DB::table('domains_watch_preferences')->where('scope',$scope)->update(['enabled'=>$enabled,'revision'=>DB::raw('revision+1')]);
        }
        // Both public channels stop immediately. Accepted/uncertain claims remain immutable evidence.
        if (!$email || !$notices) {
            $ids=DB::table('domains_watch_notices')->where('user_id',$owner)->when($email,fn($q)=>$q->where('scope',self::scope($owner,$name)))->pluck('id');
            DB::table('domains_watch_deliveries')->whereIn('notice_id',$ids)->whereIn('state',['reserved','render_failed'])->update(['state'=>'suppressed','safe_code'=>'preference_off']);
        }
    }
    public static function enabled(int $owner,string $name,bool $lock=false): bool
    { $p=self::read($owner,$name,$lock);return $p['account']['enabled'] && $p['target']['enabled']; }
}
