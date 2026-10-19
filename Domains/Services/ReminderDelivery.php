<?php
// Native notification integration adapted from Enom444f31c Reminders/TransferNotifications; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Helpers\NotificationHelper;
use App\Models\{EmailLog,NotificationTemplate,User};
use Illuminate\Support\Facades\{DB,Mail};
use Illuminate\View\Compilers\BladeCompiler;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
/** Called under current holding locks. Marker commits before queue handoff; no uncertain resend. */
final class ReminderDelivery
{
    public static function reserve(int $domainId,string $identity,string $key,array $data,User $user,int $now):?array
    {
        $data+=['renews_automatically'=>false,'currency'=>'USD'];if(($data['invoice']??null)===false){$data['invoice']=null;} // Retained templates must never promise automatic renewal.
        $digest=hash('sha256',$domainId.':'.$identity);
        if(DB::table('domains_reminder_deliveries')->where('delivery_key',$digest)->exists()){return null;}
        $template=NotificationTemplate::where('key',$key)->lockForUpdate()->first();
        if(!$template){throw new \DomainException('Notification template missing.');}
        $pref=$template?$user->notificationsPreferences()->where('notification_template_id',$template->id)->first():null;
        $wantedEmail=$template->enabled&&$template->isEnabledForPreference($pref,'mail');
        $email=$wantedEmail&&!config('settings.mail_disable');
        $inApp=$template?->enabled&&$template->isEnabledForPreference($pref,'app');
        if($wantedEmail&&!$email&&!$inApp){throw new \DomainException('Mail channel temporarily unavailable.');}
        $mail=$email?new DomainNotice($template,$data):null;
        // Template/render errors happen BEFORE a send claim and remain safely retryable.
        if($mail){$mail->envelope();$mail->render();view('domains::mail-text',$mail->content()->with)->render();}
        if($inApp){BladeCompiler::render($template->in_app_title,$data);BladeCompiler::render($template->in_app_body,$data);BladeCompiler::render($template->in_app_url,$data);}
        $id=DB::table('domains_reminder_deliveries')->insertGetId(['delivery_key'=>$digest,'domain_id'=>$domainId,'template'=>$key,
            'state'=>($email||$inApp)?'handoff':'suppressed','created_at'=>$now]);
        return ($email||$inApp)?compact('id','mail','inApp','template','data','user'):null;
    }
    public static function handoff(?array $claim,int $now):bool
    {
        if(!$claim){return false;}
        try{
            if($claim['mail']){
                $mail=$claim['mail'];$user=$claim['user'];
                $log=EmailLog::create(['user_id'=>$user->id,'subject'=>$mail->envelope()->subject,'to'=>$user->email,'body'=>$mail->render()]);
                $mail->email_log_id=$log->id;
                Mail::to($user->email)->bcc($claim['template']->bcc)->cc($claim['template']->cc)->queue($mail);
            }
            if($claim['inApp']){NotificationHelper::sendInAppNotification($claim['template'],$claim['data'],$claim['user']);}
            DB::table('domains_reminder_deliveries')->where('id',$claim['id'])->update(['state'=>'accepted','accepted_at'=>$now]);
            return true;
        }catch(\Throwable){
            DB::table('domains_reminder_deliveries')->where('id',$claim['id'])->update(['state'=>'uncertain']);
            return false; // A queue exception may follow acceptance. Never guess that nothing was sent.
        }
    }
}
