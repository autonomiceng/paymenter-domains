<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites as Writes,WatchNoticePreferences,WatchNotices};
/** Why the last customer monitoring alert for a domain failed, and the next step. The "Domain monitoring check failed" staff alert links to this domain's page. */
final class NoticeHealth
{
    /** @return array{text:string,tone:string}|null Null when nothing is open or the viewer can't see monitoring. */
    public static function open(string $kind,int $id,string $name): ?array
    {
        // Sites without customer alerts have no notice tables.
        if(!Staff::can(Writes::VIEW)||!Schema::hasTable('domains_watch_audit')||!Schema::hasTable('domains_watch_deliveries')){return null;}
        try{
            // Newest first, one per cause: a fixed template must not hide a read outage that is still going on.
            // Bound each database result before filtering encrypted kinds, even when PDO buffers reads.
            $records=WatchAudit::where('subject_type','public_notice')->where('subject_id',$id)->where('event','notice_health')->lazyByIdDesc(20)
                ->filter(fn(WatchAudit $a)=>($a->data['target_kind']??null)===$kind)->take(20)->unique(fn(WatchAudit $a)=>implode('|',[$a->data['code']??'',$a->data['channel']??'',$a->data['template']??'']));
            $health=null;
            $a=$records->first(function(WatchAudit $a)use($kind,$id,$name,&$health):bool {
                $code=$a->data['code']??'';
                if($code!=='render_failed'){
                    // Health is saved per check while it stays bad; a clock problem may sit on either one.
                    $health??=WatchNotices::state(WatchNoticePreferences::scope((int)$a->user_id,$name))['health']??[];
                    $axis=['expiry_unavailable'=>'expiry','dns_unavailable'=>'dns'][$code]??null;
                    return $axis?($health[$axis]??false)===true:in_array(true,$health,true);
                }
                // Open until this domain gets an alert on the same channel from the same template after the failure; older records
                // without them close on any alert. Mail going out never proves a broken app body works, nor one template another.
                $channel=$a->data['channel']??null;$template=$a->data['template']??null;
                return !DB::table('domains_watch_deliveries as d')->join('domains_watch_notices as n','n.id','=','d.notice_id')->where('n.target_kind',$kind)
                    ->where($kind==='watch'?'n.watch_id':'n.managed_domain_id',$id)->where('d.state','accepted')->where('d.accepted_at','>',$a->created_at->getTimestamp())
                    ->when($channel&&$template,fn($q)=>$q->where('d.channel',$channel)->where('d.template_key',$template))->exists();
            });
            if(!$a){return null;}$code=(string)($a->data['code']??'');
        }catch(\Illuminate\Contracts\Encryption\DecryptException|\JsonException){return ['text'=>WatchFacts::DECRYPT,'tone'=>'danger'];}
        $template=isset($a->data['template'])?NotificationTemplate::where('key',$a->data['template'])->value('name'):null;
        // Read outages use the customer page's words; the rest say who fixes them.
        $next=match($code){
            'render_failed'=>($a->data['mail_paused']??false)
                ?(config('settings.mail_disable')
                    ?'Site mail is off, and the "'.($template??'alert').'" template always sends mail. Turn off Disable Mail in Settings.'
                    :'Mail is on. Delivery is not confirmed yet.')
                :($template?'The "'.$template.'" template':'An alert template').' has an error. Fix it in Notification templates.',
            'expiry_unavailable'=>'We can’t read the public registry right now. We’ll keep checking.',
            'dns_unavailable'=>'We can’t read the public DNS right now. We’ll keep checking.',
            'clock_reversal'=>'The server clock went back. Ask your site admin to check the server time.',
            default=>'Ask your site admin to check the site logs.'};
        return ['text'=>'Customer alert failed '.WatchFacts::date($a->created_at->getTimestamp()).'. '.$next,'tone'=>in_array($code,['expiry_unavailable','dns_unavailable'],true)?'info':'danger'];
    }
}
