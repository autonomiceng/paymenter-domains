<?php
// Adapted from Enom 444f31c Services/{Reminders,TransferNotifications}.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\{DB,Schema};
/** Native template edits retain preferences; upgrades replace only exact previous defaults. */
final class ReminderTemplates
{
    // Wording that changed with the glossary: current terms, and the defaults shipped before them.
    private const SUPPORT_ONLY=['keep'=>'To keep this domain, contact support before it expires.','keepBody'=>'To keep this domain, contact support before **{{ $expires }}**.'];
    private const TERMS=['code'=>'auth code','Code'=>'Auth code','paid'=>'Transfer needs an auth code','also'=>'auth code (also called EPP code)','off'=>'Auto-renew is off.',
        'keep'=>'To keep this domain,@if($can_turn_on) turn it on or renew it on the domain page.@else contact support before it expires.@endif',
        'keepBody'=>'To keep this domain,@if($can_turn_on) turn it on or renew it on the domain page.@else contact support before **{{ $expires }}**.@endif'];
    private const V2=['code'=>'transfer code','Code'=>'Transfer code','paid'=>'Paid transfer needs a code','also'=>'transfer code (also called an auth code)','off'=>'Renewal is turned off.']+self::SUPPORT_ONLY;
    private const V3=self::SUPPORT_ONLY+self::TERMS;
    private const CHANGED=['domains_domain_expiring'=>['in_app_body','body'],'domains_transfer_code_paid'=>['name','subject','in_app_title','edit_preference_message','body'],
        'domains_transfer_code_reminder'=>['name','subject','in_app_title','edit_preference_message','body']];
    public static function ensure():void
    {
        $columns=array_flip(Schema::getColumnListing('notification_templates'));
        DB::transaction(function()use($columns):void {
            foreach(self::definitions() as $key=>$template){
                $stored=NotificationTemplate::where('key',$key)->lockForUpdate()->first();
                if(!$stored){
                    (new NotificationTemplate)->forceFill(array_intersect_key(['key'=>$key,...$template],$columns))->save();
                    continue;
                }
                foreach(self::previousDefaults()[$key]??[] as $field=>$previous){
                    if(isset($columns[$field]) && in_array($stored->getAttribute($field),$previous,true)){$stored->setAttribute($field,$template[$field]);}
                }
                if($stored->isDirty()){$stored->save();}
            }
        });
    }
    private static function previousDefaults():array
    {
        $button=static fn(string $url,string $label):string=>'<div class="action"><a class="button button-blue" href="{{ $'.$url.' }}">'.$label.'</a></div>';
        $v1=[
                'in_app_body'=>'@if($invoice)@if($overdue)Your renewal invoice is overdue. Contact support to check renewal options.@else Pay the renewal invoice by {{ $due }}.@endif@elseif($invoice_on)Your renewal invoice is scheduled for {{ $invoice_on }}.@elseif($complimentary)Complimentary pricing. Contact support to confirm its renewal.@else Renewal is off. Contact support if you want to keep this domain.@endif',
                'body'=>"# {{ \$domain }} expires on {{ \$expires }}\n\n@if(\$invoice)\n@if(\$overdue)\nYour renewal invoice for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}) was due on **{{ \$due }}**. Contact support to check whether this domain can still be renewed.\n\n".$button('invoice_url','View invoice')."\n@else\nYour renewal invoice is ready for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}). Pay it by **{{ \$due }}** and we'll renew the domain for you.\n\n"
                    .$button('invoice_url','Pay invoice')."\n@endif\n@elseif(\$invoice_on)\nYour renewal invoice is scheduled for **{{ \$invoice_on }}**. Pay it by the due date and we'll renew the domain for you.\n@elseif(\$complimentary)\nThis domain has complimentary pricing. Contact support to confirm its renewal.\n\n".$button('domain_url','View domain')."\n@else\nRenewal is turned off, so the domain will expire unless you renew it. Websites and email that use it will stop working. Contact support if you want to keep this domain.\n\n".$button('domain_url','View domain')."\n@endif"];
        $v2=self::templates(self::V2);$v3=self::templates(self::V3);
        $out=['domains_domain_expiring'=>array_map(fn($v)=>[$v],$v1)];
        foreach(self::CHANGED as $key=>$fields){ foreach($fields as $field){ $out[$key][$field][]=$v2[$key][$field]; } }
        foreach(['in_app_body','body'] as $field){ $out['domains_domain_expiring'][$field][]=$v3['domains_domain_expiring'][$field]; }
        return $out;
    }
    public static function definitions():array
    {
        return self::templates(self::TERMS);
    }
    private static function templates(array $w):array
    {
        $button=static fn(string $url,string $label):string=>'<div class="action"><a class="button button-blue" href="{{ $'.$url.' }}">'.$label.'</a></div>';
        $common=['enabled'=>true,'mail_enabled'=>'choice_on','in_app_enabled'=>'choice_on','in_app_url'=>'{{ $domain_url }}',
            'edit_preference_message'=>'Domain renewal reminders'];
        $transfer=['enabled'=>true,'mail_enabled'=>'force','in_app_enabled'=>'never','in_app_url'=>'{{ $domain_url }}',
            'in_app_title'=>'Enter the '.$w['code'].' for {{ $domain }}','in_app_body'=>'Enter it by {{ $deadline }}.',
            'edit_preference_message'=>$w['Code'].' reminders'];
        $cutoff="Enter it by **{{ \$deadline }}**.\n\n".$button('domain_url','Enter '.$w['code'])
            ."\n\nIf we don't have it by then, we'll cancel the transfer and add {{ \$amount }} to your account credit.";
        return [
            'domains_domain_expiring'=>$common+['name'=>'Domain expiring soon','subject'=>'{{ $domain }} expires on {{ $expires }}',
                'in_app_title'=>'{{ $domain }} expires in {{ $days }} {{ $days === 1 ? \'day\' : \'days\' }}',
                'in_app_body'=>'@if($invoice)@if($overdue)Your renewal invoice is overdue. Contact support to check renewal options.@else Pay the renewal invoice by {{ $due }}.@endif@elseif($invoice_on)Your renewal invoice is scheduled for {{ $invoice_on }}.@elseif($complimentary)Complimentary pricing. Contact support to confirm its renewal.@else '.$w['off'].' '.$w['keep'].'@endif',
                'body'=>"# {{ \$domain }} expires on {{ \$expires }}\n\n@if(\$invoice)\n@if(\$overdue)\nYour renewal invoice for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}) was due on **{{ \$due }}**. Contact support to check whether this domain can still be renewed.\n\n".$button('invoice_url','View invoice')."\n@else\nYour renewal invoice is ready for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}). Pay it by **{{ \$due }}** and we'll renew the domain for you.\n\n"
                    .$button('invoice_url','Pay invoice')."\n@endif\n@elseif(\$invoice_on)\nYour renewal invoice is scheduled for **{{ \$invoice_on }}**. Pay it by the due date and we'll renew the domain for you.\n@elseif(\$complimentary)\nThis domain has complimentary pricing. Contact support to confirm its renewal.\n\n".$button('domain_url','View domain')."\n@else\n".$w['off'].' '.$w['keepBody']." After it expires, websites and email that use it may stop working.\n\n".$button('domain_url','View domain')."\n@endif"],
            'domains_domain_expired'=>$common+['name'=>'Domain expired','subject'=>'{{ $domain }} has expired',
                'in_app_title'=>'{{ $domain }} has expired','in_app_body'=>'Contact support to check whether this domain can still be renewed.',
                'body'=>"# {{ \$domain }} expired on {{ \$expires }}\n\nWebsites and email that use this domain may stop working. Contact support to check whether it can still be renewed.\n\n".$button('domain_url','View domain')],
            'domains_transfer_code_paid'=>$transfer+['name'=>$w['paid'],'subject'=>'Enter the '.$w['code'].' for {{ $domain }}',
                'body'=>"Thanks for your payment. To move {{ \$domain }} to us, enter its ".$w['also'].". Your current registrar gives you this code. We'll check the transfer once we have it.\n\n".$cutoff],
            'domains_transfer_code_reminder'=>$transfer+['name'=>$w['Code'].' deadline reminder','subject'=>'Reminder: '.$w['code'].' for {{ $domain }}',
                'body'=>"We still need the ".$w['code']." for {{ \$domain }}. ".$cutoff],
            'domains_domain_order_refunded'=>array_replace($common,['name'=>'Domain order credited','subject'=>'Account credit for your {{ $action }} of {{ $domain }}',
                'mail_enabled'=>'force','in_app_title'=>'{{ $amount }} added to your account credit',
                'in_app_body'=>'Your {{ $action }} of {{ $domain }} was cancelled.',
                'body'=>"# Your {{ \$action }} of {{ \$domain }} was cancelled\n\nWe added **{{ \$amount }}** to your account credit. You can use this credit toward a future invoice.\n\n".$button('domain_url','View domain')]),
        ];
    }
}
