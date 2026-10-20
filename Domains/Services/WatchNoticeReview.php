<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{User,EmailLog,Notification};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchNotice};
/** Staff inspection never reclassifies an uncertain send as unsent. Resend has a new audited identity. */
final class WatchNoticeReview
{
    public static function allowed(int $id,bool $lock=false): bool
    {
        $user=User::whereKey($id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        if (!$user) { return false; }$user->setRelation('role',$user->role()->when($lock,fn($q)=>$q->lockForUpdate())->first());
        return $user->hasPermission('admin.domains.view') && $user->hasPermission('admin.domains.watch.manage');
    }
    public static function inspect(User $actor,int $id): array
    {
        abort_unless(self::allowed((int)$actor->id,DB::transactionLevel()>0),403);
        $d=DB::table('domains_watch_deliveries')->find($id);abort_unless($d,404);
        return ['id'=>(int)$d->id,'channel'=>$d->channel,'state'=>$d->state,'native_id'=>$d->native_id,'accepted_at'=>$d->accepted_at,
            'evidence_type'=>$d->channel==='mail'?'email_log':'notification','resend_of'=>$d->resend_of];
    }
    public static function resend(User $actor,int $id,int $nativeId,string $reason,string $requestKey,int $now,WatchNoticeDelivery $delivery): ?int
    {
        if (DB::transactionLevel()!==0 || strlen(trim($reason))<10 || strlen($reason)>500 || !preg_match('/\A[a-f0-9]{64}\z/D',$requestKey)) { throw new \DomainException('Native evidence, reason and request key are required.'); }
        $r=DB::transaction(function()use($actor,$id,$nativeId,$reason,$requestKey,$now):array {
            abort_unless(self::allowed((int)$actor->id,DB::transactionLevel()>0),403);
            $d=DB::table('domains_watch_deliveries')->where('id',$id)->lockForUpdate()->first();
            abort_unless($d && in_array($d->state,['accepted','uncertain'],true),409);
            $n=WatchNotice::findOrFail($d->notice_id);$e=$d->channel==='mail'?EmailLog::find($nativeId):Notification::find($nativeId);
            abort_unless($e && (int)$e->user_id===(int)$d->user_id && (int)$d->native_id===$nativeId,409);
            if ($d->channel==='mail' && DB::table('domains_watch_mail_days')->where('scope',hash('sha256',$n->scope.'|'.WatchNoticePlan::day($now)))->exists()) { throw new \DomainException('A public domain check email was already queued today. Review again on the next UTC day.'); }
            $key=hash('sha256','resend|'.$id.'|'.$requestKey);
            if (!WatchAudit::where('subject_type','notice_delivery')->where('subject_id',$id)->where('event','notice_resend')->get()->contains(fn($a)=>($a->data['key']??null)===$key)) {
                WatchAudit::create(['after_revision'=>hash('sha256',(string)$now),'subject_type'=>'notice_delivery','subject_id'=>$id,'user_id'=>$d->user_id,'actor_id'=>$actor->id,'event'=>'notice_resend','reason'=>trim($reason),
                    'data'=>['key'=>$key,'channel'=>$d->channel,'native_id'=>$nativeId,'prior_state'=>$d->state],'created_at'=>gmdate('Y-m-d H:i:s',$now)]);
            }
            return ['notice'=>$n->id,'channel'=>$d->channel,'id'=>$id,'actor'=>(int)$actor->id,'reason'=>trim($reason),'key'=>$key];
        });
        return $delivery->reserve($r['notice'],$r['channel'],$now,$r);
    }
}
