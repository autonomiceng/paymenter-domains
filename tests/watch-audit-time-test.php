<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real Eloquent casts and retained installation are needed to reproduce the site-zone storage boundary.
require __DIR__.'/native-app.php';
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
use Paymenter\Extensions\Servers\Domains\Services\{Installation,WatchNoticeHealth};
$install=new Installation;$install->install();
$prior=date_default_timezone_get();date_default_timezone_set('America/Los_Angeles');
$attrs=['subject_type'=>'watch','subject_id'=>7,'event'=>'staff_pause','after_revision'=>'1','data'=>[]];
try{
    foreach(['2026-11-01T08:30:00Z','2026-11-01T09:30:00Z','2026-03-08T09:59:59Z','2026-03-08T10:00:00Z'] as $value){
        $at=new DateTimeImmutable($value);$local=CarbonImmutable::instance($at)->setTimezone('America/Los_Angeles');
        $a=WatchAudit::create([...$attrs,'created_at'=>$local]);$raw=DB::table('domains_watch_audit')->find($a->id);
        same($at->format('Y-m-d H:i:s'),$raw->created_at,'UTC wall time survives DST and a non-UTC writer');
        same('UTC',$raw->time_zone,'Every new row declares UTC');
        same($at->getTimestamp(),$a->fresh()->created_at->getTimestamp(),'The stored instant round-trips');
        date_default_timezone_set('Asia/Tokyo');
        same($at->getTimestamp(),$a->fresh()->created_at->getTimestamp(),'A later site zone cannot reinterpret UTC');
        date_default_timezone_set('America/Los_Angeles');
    }
    WatchNoticeHealth::record(7,7,'watch','clock_reversal',1793525400);
    same(1793525400,WatchAudit::where('event','notice_health')->sole()->created_at->timestamp,'Health elapsed-time input retains its instant');
    $migration='2026_11_11_000060_add_watch_audit_time_zone';
    Schema::table('domains_watch_audit',fn($t)=>$t->dropColumn('time_zone'));
    DB::table('migrations')->where('migration',$migration)->delete();
    DB::table('domains_watch_audit')->delete();
    DB::table('domains_watch_audit')->insert([...$attrs,'data'=>'retained encrypted bytes','created_at'=>'2026-11-01 01:30:00']);
    $before=DB::table('domains_watch_audit')->sole();$install->install();
    $old=WatchAudit::sole();same(null,$old->time_zone,'Ambiguous retained rows stay unmarked');
    same($before->created_at,$old->getRawOriginal('created_at'),'Ambiguous wall times are never shifted');
    same($before->data,$old->getRawOriginal('data'),'Retained evidence is unchanged');
    same(CarbonImmutable::parse($before->created_at,'America/Los_Angeles')->timestamp,$old->created_at->timestamp,'Unmarked rows preserve the prior local-time read contract');
    $path=dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';
    (require $path)->up();$install->install();$install->verify();same(1,WatchAudit::count(),'Reruns keep retained rows');
    DB::table('migrations')->where('migration',$migration)->delete();
    $error=null;try{$install->install();}catch(DomainException $e){$error=$e->getMessage();}
    same('Retained watch audit time zones have no installation record.',$error,'An unrecorded marker is refused');
    DB::table('migrations')->insert(['migration'=>$migration,'batch'=>99]);
    Schema::table('domains_watch_audit',fn($t)=>$t->dropColumn('time_zone'));
    $error=null;try{$install->install();}catch(RuntimeException $e){$error=$e->getMessage();}
    same('Retained watch audit time zones are missing.',$error,'A recorded but lost marker is refused');
}finally{date_default_timezone_set($prior);}
$nativeSuccessful=true;echo "Watch audit UTC instants, DST, mixed rows and conservative retained upgrade.\n";
