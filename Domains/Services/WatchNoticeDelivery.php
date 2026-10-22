<?php
// Native queue integration adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\NotificationEnabledStatus;
use App\Models\{User,NotificationTemplate,EmailLog,Notification};
use Illuminate\Support\Facades\{DB,Mail};
use Illuminate\View\Compilers\BladeCompiler;
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
use Paymenter\Extensions\Servers\Domains\Mail\WatchNotice as NoticeMail;
/** Claims commit outside the native queue boundary. Each channel has independent retained evidence. */
final class WatchNoticeDelivery
{
    public function __construct(private bool $enabled=false) {}
    public static function url(string $kind,int $id): string
    { return url('/domains/checks/'.$kind.'/'.$id); }
    public static function data(WatchNotice $n): array
    {
        return ['domain'=>$n->data['domain'],'date'=>\Carbon\CarbonImmutable::parse($n->data['date'],'UTC')->format('F j, Y'),
            'company'=>\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company(config('settings.company_name')),
            'domain_url'=>self::url($n->target_kind,(int)($n->watch_id??$n->managed_domain_id))];
    }
    public static function prepare(WatchNotice $n,string $channel,User $user): ?array
    {
        $template=NotificationTemplate::where('key','domains_watch_'.$n->kind)->first();
        if (!$template || !$template->enabled) { return null; }
        if ($channel==='mail' && $template->mail_enabled===NotificationEnabledStatus::Force && config('settings.mail_disable')) {
            throw new \DomainException('Mail channel temporarily unavailable.');
        }
        $preference=$user->notificationsPreferences()->where('notification_template_id',$template->id)->first();
        if (!$template->isEnabledForPreference($preference,$channel) || ($channel==='mail' && config('settings.mail_disable'))) { return null; }
        $data=self::data($n);$stamp=hash('sha256',serialize($template->getRawOriginal()));$email=$user->email;
        // The only dynamic fields are escaped safe scalars; no raw public/registrar response or native models.
        if ($channel==='mail') {
            $mail=new NoticeMail($template,$data);$mail->envelope();$mail->render();view('domains::mail-text',$mail->content()->with)->render();
            return compact('template','data','mail','stamp','email');
        }
        $title=BladeCompiler::render($template->in_app_title,$data);$body=BladeCompiler::render($template->in_app_body,$data);
        return compact('template','data','title','body','stamp','email');
    }
    private static function current(?array $prepared,int $owner,string $channel): bool
    {
        if (!$prepared) { return false; }$user=User::whereKey($owner)->lockForUpdate()->first();$template=NotificationTemplate::whereKey($prepared['template']->id)->lockForUpdate()->first();
        if (!$user || $user->email!==$prepared['email'] || !$template || !$template->enabled || !hash_equals($prepared['stamp'],hash('sha256',serialize($template->getRawOriginal())))) { return false; }
        $pref=$user->notificationsPreferences()->where('notification_template_id',$template->id)->lockForUpdate()->first();
        return $template->isEnabledForPreference($pref,$channel) && !($channel==='mail' && config('settings.mail_disable'));
    }
    public function reserve(int $noticeId,string $channel,int $now,?array $resend=null): ?int
    {
        if (!$this->enabled || $now<0 || DB::transactionLevel()!==0 || !in_array($channel,['mail','app'],true)) { return null; }
        // Render errors do not acquire a delivery key; the planner's health signal remains inspectable.
        $n=WatchNotice::find($noticeId);$user=$n?User::find($n->user_id):null;if (!$n || !$user) { return null; }
        try { $prepared=self::prepare($n,$channel,$user); } catch (\Throwable) { $this->renderFailure($n,$channel,$now);return null; }
        return DB::transaction(function()use($n,$channel,$now,$prepared,$resend):?int {
            $t=WatchNoticeTarget::capture($n->target_kind,(int)($n->watch_id??$n->managed_domain_id),$n->user_id,$now);if (!$t) { return null; }
            $n=WatchNotice::whereKey($n->id)->lockForUpdate()->first();$state=WatchNotices::state($n->scope,true);
            if ($n->state!=='open' || !in_array($channel,$n->data['channels'],true) || !WatchNoticeTarget::eligible($n,$t,$state)) { return null; }
            if ($resend && (!DB::table('domains_watch_deliveries')->where('id',$resend['id'])->where('notice_id',$n->id)->where('channel',$channel)->whereIn('state',['accepted','uncertain'])->exists() || !\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_type','notice_delivery')->where('subject_id',$resend['id'])->where('actor_id',$resend['actor'])->where('event','notice_resend')->get()->contains(fn($a)=>($a->data['key']??null)===$resend['key']))) { throw new \DomainException('Audited resend required.'); }
            $key=hash('sha256',$n->episode_key.'|'.$channel.($resend?'|'.$resend['key']:''));
            $on=self::current($prepared,$n->user_id,$channel) && WatchNoticePreferences::enabled($n->user_id,$t['name'],true);
            $prior=DB::table('domains_watch_deliveries')->where('delivery_key',$key)->lockForUpdate()->first();
            if ($prior) {
                $predecessor=WatchNotice::find($prior->notice_id);
                if (!$resend && $prior->notice_id!=$n->id && $n->target_kind==='managed' && $predecessor?->target_kind==='watch'
                    && $predecessor->scope===$n->scope && $predecessor->episode_key===$n->episode_key
                    && ($prior->state==='reserved' || ($prior->state==='suppressed' && $prior->safe_code==='registration_managed'))) {
                    \Paymenter\Extensions\Servers\Domains\Models\WatchAudit::create(['after_revision'=>hash('sha256',(string)$now),'subject_type'=>'notice_delivery','subject_id'=>$prior->id,'user_id'=>$n->user_id,
                        'event'=>'notice_claim_relinked','reason'=>'Unhanded channel follows managed registration','data'=>['predecessor_notice_id'=>$prior->notice_id,'notice_id'=>$n->id], 'created_at'=>gmdate('Y-m-d H:i:s',$now)]);
                    DB::table('domains_watch_deliveries')->where('id',$prior->id)->update(['notice_id'=>$n->id,'state'=>$on?'reserved':'suppressed','safe_code'=>$on?null:'preference_off']);
                    return $on?(int)$prior->id:null;
                }
                return $prior->state==='reserved' && $prior->notice_id==$n->id?(int)$prior->id:null;
            }
            return DB::table('domains_watch_deliveries')->insertGetId(['notice_id'=>$n->id,'user_id'=>$n->user_id,'channel'=>$channel,'delivery_key'=>$key,
                'template_key'=>'domains_watch_'.$n->kind,'state'=>$on?'reserved':'suppressed','reserved_at'=>$now,'safe_code'=>$on?null:'preference_off',
                'resend_of'=>$resend['id']??null,'actor_id'=>$resend['actor']??null]);
        });
    }
    public function handoff(int $id,int $now): bool
    {
        if (!$this->enabled || DB::transactionLevel()!==0) { return false; }
        $d=DB::table('domains_watch_deliveries')->find($id);$n=$d?WatchNotice::find($d->notice_id):null;$user=$n?User::find($n->user_id):null;
        if (!$d || $d->state!=='reserved' || !$n || !$user) { return false; }
        try { $prepared=self::prepare($n,$d->channel,$user); } catch (\Throwable) { $this->renderFailure($n,$d->channel,$now);return false; }
        $allowed=DB::transaction(function()use($id,$n,$d,$now,$prepared):bool {
            User::whereIn('id',array_filter([$n->user_id,$d->actor_id]))->orderBy('id')->lockForUpdate()->get();
            $t=WatchNoticeTarget::capture($n->target_kind,(int)($n->watch_id??$n->managed_domain_id),$n->user_id,$now);
            $n=WatchNotice::whereKey($n->id)->lockForUpdate()->first();$q=DB::table('domains_watch_deliveries')->where('id',$id);$d=$q->lockForUpdate()->first();
            if (!$d || $d->state!=='reserved' || (int)$d->notice_id!==(int)$n->id) { return false; }
            $state=WatchNotices::state($n->scope,true);
            if ($now<$d->reserved_at || $now<max($state['expiry']['evaluated_at']??0,$state['dns']['evaluated_at']??0)) { return false; }
            $on=$t && $n->state==='open' && WatchNoticePreferences::enabled($n->user_id,$t['name'],true) && WatchNoticeTarget::eligible($n,$t,$state) && self::current($prepared,$n->user_id,$d->channel);
            if ($d->resend_of && !WatchNoticeReview::allowed((int)$d->actor_id,true)) { $on=false; }
            if (!$on) { $q->update(['state'=>'suppressed','safe_code'=>'target_or_preference_changed']);return false; }
            if ($d->channel==='mail') {
                $day=hash('sha256',$n->scope.'|'.WatchNoticePlan::day($now));
                // Higher priority still-true notice wins even if a stale worker asks for a lower one first.
                foreach(WatchNotice::where('scope',$n->scope)->where('state','open')->get() as $other) {
                    if ($other->data['priority']>=$n->data['priority'] || !WatchNoticeTarget::eligible($other,$t,$state)) { continue; }
                    try { if (!self::prepare($other,'mail',User::findOrFail($n->user_id))) { continue; } } catch (\Throwable) { continue; }
                    $otherKey=hash('sha256',$other->episode_key.'|mail');
                    $prior=DB::table('domains_watch_deliveries')->where('delivery_key',$otherKey)->first();
                    if (!$prior || $prior->state==='reserved') { return false; }
                }
                if (!DB::table('domains_watch_mail_days')->insertOrIgnore(['scope'=>$day,'delivery_id'=>$id,'created_at'=>$now])) {
                    if ($d->resend_of) { $q->update(['state'=>'suppressed','safe_code'=>'daily_mail_limit']); }return false;
                }
            }
            // A committed uncertain marker closes the automatic replay path before any native call.
            $q->update(['state'=>'uncertain','safe_code'=>'handoff_started']);
            return true;
        });
        if (!$allowed) { return false; }
        try {
            if ($d->channel==='mail') {
                $mail=$prepared['mail'];$log=EmailLog::create(['user_id'=>$user->id,'subject'=>$mail->envelope()->subject,'to'=>$user->email,'body'=>$mail->render()]);
                DB::table('domains_watch_deliveries')->where('id',$id)->update(['native_id'=>$log->id]);$mail->email_log_id=$log->id;
                Mail::to($user->email)->bcc($prepared['template']->bcc)->cc($prepared['template']->cc)->queue($mail);
            } else {
                $native=Notification::create(['user_id'=>$user->id,'title'=>$prepared['title'],'body'=>$prepared['body'],'url'=>$prepared['data']['domain_url']]);
                DB::table('domains_watch_deliveries')->where('id',$id)->update(['native_id'=>$native->id]);
            }
            DB::table('domains_watch_deliveries')->where('id',$id)->update(['state'=>'accepted','accepted_at'=>$now,'safe_code'=>null]);return true;
        } catch (\Throwable) { return false; } // Unknown native acceptance remains uncertain forever without audited staff action.
    }
    private function renderFailure(WatchNotice $n,string $channel,int $now): void
    {
        DB::transaction(function()use($n,$channel,$now):void {
            $t=WatchNoticeTarget::capture($n->target_kind,(int)($n->watch_id??$n->managed_domain_id),$n->user_id,$now);if (!$t) { return; }
            $state=WatchNotices::state($n->scope,true);$key=$n->id.'|'.$channel;
            if (!($state['render_failures'][$key]??false)) {
                $state['render_failures'][$key]=true;WatchNotices::write($n->scope,$n->user_id,$state,$now);
                WatchNoticeHealth::record($n->user_id,(int)($n->watch_id??$n->managed_domain_id),$n->target_kind,'render_failed',$now);
            }
        });
    }
    public function deliver(int $noticeId,int $now): array
    {
        $out=[];foreach(['mail','app'] as $channel) { $id=$this->reserve($noticeId,$channel,$now);$out[$channel]=$id!==null && $this->handoff($id,$now); }return $out;
    }
}
