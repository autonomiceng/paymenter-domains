<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
/** Retained audit plus native staff inbox, never customer mail. Caller deduplicates each health episode. */
final class WatchNoticeHealth
{
    public static function record(int $owner,int $id,string $kind,string $code,int $now): void
    {
        WatchAudit::create(['after_revision'=>hash('sha256',(string)$now),'subject_type'=>'public_notice','subject_id'=>$id,'user_id'=>$owner,'event'=>'notice_health',
            'reason'=>'Public domain notice needs review.','data'=>['target_kind'=>$kind,'code'=>$code],'created_at'=>gmdate('Y-m-d H:i:s',$now)]);
        DB::afterCommit(static function():void {
            try {
                \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffAlert::send(
                    'Domain monitoring check failed','Open Domain monitoring to see the domain.',
                    'filament.admin.resources.domain-watch.index','admin.domains.watch.view');
            } catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Public notice staff inbox unavailable.'); }
        });
    }
}
