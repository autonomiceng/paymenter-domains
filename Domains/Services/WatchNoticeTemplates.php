<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\{DB,Schema};
/** Inserts missing templates; upgrades replace only fields that still hold an earlier default. */
final class WatchNoticeTemplates
{
    private const TERMS=['copy'=>[
            'expiring'=>['Check renewal for {{ $domain }} before {{ $date }}','The public registry lists {{ $date }} as the expiry date for {{ $domain }}. Public registry dates may not show recent changes. Check renewal with your registrar.'],
            'expired'=>['{{ $domain }} has expired','The public registry shows {{ $domain }} expired on {{ $date }}. Contact your registrar to check its status. Whether it can still be renewed depends on your registrar and the registry.'],
            'ns_changed'=>['Nameservers changed for {{ $domain }}','As of {{ $date }}, {{ $domain }} no longer uses {{ $nameservers }}. If you didn’t expect this, contact support.'],
            'ns_restored'=>['{{ $domain }} uses {{ $nameservers }} again','As of {{ $date }}, {{ $domain }} uses {{ $nameservers }} again.'],
        ],'name'=>'Monitoring alert: ','names'=>['expiring'=>'expires soon','expired'=>'expired','ns_changed'=>'nameservers changed','ns_restored'=>'nameservers restored'],
        'preference'=>'Monitoring alerts','link'=>'View domain','footer'=>'You get this because monitoring alerts are on. Turn them off on the domain page.'];
    private const V1=['copy'=>[
            'expiring'=>['Check renewal for {{ $domain }} before {{ $date }}','The public registry lists {{ $date }} as the expiry date for {{ $domain }}. Public registry dates may not show recent changes. Check renewal with your registration provider.'],
            'expired'=>['Registry expiry date reached for {{ $domain }}','The public registry expiry date for {{ $domain }} was reached on {{ $date }}. Contact your registration provider to check its status. Whether it can still be renewed depends on your registration provider and the registry.'],
            'ns_changed'=>['Nameservers changed for {{ $domain }}','As of {{ $date }}, {{ $domain }} no longer uses the nameservers provided by {{ $company }}. If you did not expect this, contact support.'],
            'ns_restored'=>['Nameserver change resolved for {{ $domain }}','As of {{ $date }}, {{ $domain }} uses the nameservers provided by {{ $company }} again.'],
        ],'name'=>'Domain checks: ','names'=>['expiring'=>'expiry approaching','expired'=>'registry expiry reached','ns_changed'=>'nameservers changed','ns_restored'=>'nameserver change resolved'],
        'preference'=>'Public domain check notices','link'=>'View domain checks','footer'=>'You receive this because public domain check notices are enabled in your account. This notice is not a bill.',
        'after'=>'To pause notices about this domain, or turn them off for all domains, use the link above.'];
    private const TEXT=['name','edit_preference_message','subject','in_app_title','in_app_body','body'];
    public static function ensure(): void
    {
        $v2=self::definitions();
        foreach ($v2 as &$fields) { foreach (self::TEXT as $field) { $fields[$field]=str_replace('{{ $nameservers }}','{{ $company }} nameservers',$fields[$field]); } }
        unset($fields);
        $columns=array_flip(Schema::getColumnListing('notification_templates'));$previous=self::templates(self::V1);
        DB::transaction(function()use($columns,$previous,$v2):void {
            foreach(self::definitions() as $key=>$template) {
                $stored=NotificationTemplate::where('key',$key)->lockForUpdate()->first();
                if (!$stored) { (new NotificationTemplate())->forceFill(array_intersect_key(['key'=>$key,...$template],$columns))->save(); continue; }
                foreach(self::TEXT as $field) {
                    $value=$stored->getAttribute($field);
                    // Match the earlier default name by digest so only that exact stored value is upgraded.
                    $oldName=$key==='domains_watch_ns_restored' && $field==='name' && is_string($value)
                        && hash('sha256',$value)==='078ddbdde621a7eac58d2297b01e9cf4f10ce3a69b16fb6634ff8f2b0ea236cc';
                    if (isset($columns[$field]) && ($value===$previous[$key][$field] || $value===$v2[$key][$field] || $oldName)) { $stored->setAttribute($field,$template[$field]); }
                }
                if ($stored->isDirty()) { $stored->save(); }
            }
        });
    }
    public static function definitions(): array
    {
        return self::templates(self::TERMS);
    }
    private static function templates(array $w): array
    {
        $out=[];$cell='<tr><td style="color:#1f2937;padding:0 0 20px;font-family:Arial,sans-serif;';
        foreach($w['copy'] as $kind=>[$subject,$body]) {
            $link=$cell.'font-size:16px;line-height:24px"><a href="{{ $domain_url }}" style="color:#2563eb;text-decoration:underline">'.$w['link'].'</a></td></tr>';
            $footer=$cell.'font-size:14px;line-height:22px">'.$w['footer'].'</td></tr>';
            $out['domains_watch_'.$kind]=['name'=>$w['name'].$w['names'][$kind],'enabled'=>true,'mail_enabled'=>'choice_on','in_app_enabled'=>'choice_on',
                'edit_preference_message'=>$w['preference'],'subject'=>$subject,'in_app_title'=>$subject,'in_app_body'=>$body,'in_app_url'=>'{{ $domain_url }}',
                'body'=>'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr><td style="color:#1f2937;padding:0 0 16px;font-family:Arial,sans-serif;font-size:18px;line-height:26px;font-weight:bold;overflow-wrap:anywhere">'.$subject.'</td></tr>'
                    .$cell.'font-size:16px;line-height:24px;overflow-wrap:anywhere">'.$body.'</td></tr>'
                    .(isset($w['after']) ? $footer.$link.'<tr><td style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:22px">'.$w['after'].'</td></tr></table>'
                        : $link.str_replace('padding:0 0 20px;','',$footer).'</table>')];
        }return $out;
    }
}
