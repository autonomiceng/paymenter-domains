<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
/** Retained audit plus native staff inbox, never customer mail. Caller deduplicates each health episode. */
final class WatchNoticeHealth
{
    /** $failure names what failed when an alert could not be prepared (template key, channel, mail_paused), so the page can name it and tell when it recovers. */
    public static function record(int $owner,int $id,string $kind,string $code,int $now,array $failure=[]): void
    {
        WatchAudit::create(['after_revision'=>hash('sha256',(string)$now),'subject_type'=>'public_notice','subject_id'=>$id,'user_id'=>$owner,'event'=>'notice_health',
            'reason'=>'Public domain notice needs review.','data'=>['target_kind'=>$kind,'code'=>$code]+$failure,'created_at'=>gmdate('Y-m-d H:i:s',$now)]);
        // Name the domain and open its own page, where its checks show; the list alone can't say which one.
        $managed=$kind==='managed';
        $name=$managed?DB::table('domains_domains')->where('id',$id)->value('domain'):DB::table('domains_watch_names')->where('id',$id)->value('canonical_name');
        $line='A customer notice for '.(is_string($name)&&$name!==''?$name:'a domain').' could not be prepared.';
        DB::afterCommit(static function()use($line,$managed,$id):void {
            try {
                \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send('Domain monitoring check failed',$line,
                    $managed?'filament.admin.resources.domains.view':'filament.admin.resources.domain-watch.view','admin.domains.watch.view',parameters:['record'=>$id]);
            } catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Public notice staff inbox unavailable.'); }
        });
    }
}
