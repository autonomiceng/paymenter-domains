<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\{DB,Schema};
final class WatchNoticeTemplates
{
    public static function ensure(): void
    {
        $columns=array_flip(Schema::getColumnListing('notification_templates'));
        DB::transaction(function()use($columns):void {
            foreach(self::definitions() as $key=>$template) {
                if (!NotificationTemplate::where('key',$key)->exists()) { (new NotificationTemplate())->forceFill(array_intersect_key(['key'=>$key,...$template],$columns))->save(); }
            }
        });
    }
    public static function definitions(): array
    {
        $copy=[
            'expiring'=>['Check renewal for {{ $domain }} before {{ $date }}','The public registry lists {{ $date }} as the expiry date for {{ $domain }}. Public registry dates may not show recent changes. Check renewal with your registration provider.'],
            'expired'=>['Registry expiry date reached for {{ $domain }}','The public registry expiry date for {{ $domain }} was reached on {{ $date }}. Contact your registration provider to check its status. Whether it can still be renewed depends on your registration provider and the registry.'],
            'ns_changed'=>['Nameservers changed for {{ $domain }}','As of {{ $date }}, {{ $domain }} no longer uses the nameservers provided by {{ $company }}. If you did not expect this, contact support.'],
            'ns_restored'=>['Nameserver change resolved for {{ $domain }}','As of {{ $date }}, {{ $domain }} uses the nameservers provided by {{ $company }} again.'],
        ];$out=[];
        foreach($copy as $kind=>[$subject,$body]) {
            $out['domains_watch_'.$kind]=['name'=>'Domain checks: '.(['expiring'=>'expiry approaching','expired'=>'registry expiry reached','ns_changed'=>'nameservers changed','ns_restored'=>'nameserver change resolved'][$kind]),'enabled'=>true,'mail_enabled'=>'choice_on','in_app_enabled'=>'choice_on',
                'edit_preference_message'=>'Public domain check notices','subject'=>$subject,'in_app_title'=>$subject,'in_app_body'=>$body,'in_app_url'=>'{{ $domain_url }}',
                'body'=>'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">'.$subject.'</td></tr>'
                    .'<tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px;overflow-wrap:anywhere">'.$body.'</td></tr>'
                    .'<tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:14px;line-height:22px">You receive this because public domain check notices are enabled in your account. This notice is not a bill.</td></tr>'
                    .'<tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">View domain checks</a></td></tr>'
                    .'<tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">To pause notices about this domain, or turn them off for all domains, use the link above.</td></tr></table>'];
        }return $out;
    }
}
