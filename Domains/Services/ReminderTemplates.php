<?php
// Adapted from Enom 444f31c Services/{Reminders,TransferNotifications}.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\{DB,Schema};
/** Native template edits retain Paymenter's audit and preferences; upgrades only create missing keys. */
final class ReminderTemplates
{
    public static function ensure():void
    {
        $columns=array_flip(Schema::getColumnListing('notification_templates'));
        DB::transaction(function()use($columns):void {
            foreach(self::definitions() as $key=>$template){
                if(!NotificationTemplate::where('key',$key)->exists()){
                    (new NotificationTemplate)->forceFill(array_intersect_key(['key'=>$key,...$template],$columns))->save();
                }
            }
        });
    }
    public static function definitions():array
    {
        $button=static fn(string $url,string $label):string=>'<div class="action"><a class="button button-blue" href="{{ $'.$url.' }}">'.$label.'</a></div>';
        $common=['enabled'=>true,'mail_enabled'=>'choice_on','in_app_enabled'=>'choice_on','in_app_url'=>'{{ $domain_url }}',
            'edit_preference_message'=>'Domain renewal reminders'];
        $transfer=['enabled'=>true,'mail_enabled'=>'force','in_app_enabled'=>'never','in_app_url'=>'{{ $domain_url }}',
            'in_app_title'=>'Enter the transfer code for {{ $domain }}','in_app_body'=>'Enter it by {{ $deadline }}.',
            'edit_preference_message'=>'Transfer code reminders'];
        $cutoff="Enter it by **{{ \$deadline }}**.\n\n".$button('domain_url','Enter transfer code')
            ."\n\nIf we don't have it by then, we'll cancel the transfer and add {{ \$amount }} to your account credit.";
        return [
            'domains_domain_expiring'=>$common+['name'=>'Domain expiring soon','subject'=>'{{ $domain }} expires on {{ $expires }}',
                'in_app_title'=>'{{ $domain }} expires in {{ $days }} {{ $days === 1 ? \'day\' : \'days\' }}',
                'in_app_body'=>'@if($invoice)@if($overdue)Your renewal invoice is overdue. Contact support to check renewal options.@else Pay the renewal invoice by {{ $due }}.@endif@elseif($invoice_on)Your renewal invoice is scheduled for {{ $invoice_on }}.@elseif($complimentary)Complimentary pricing. Contact support to confirm its renewal.@else Renewal is turned off. To keep this domain, contact support before it expires.@endif',
                'body'=>"# {{ \$domain }} expires on {{ \$expires }}\n\n@if(\$invoice)\n@if(\$overdue)\nYour renewal invoice for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}) was due on **{{ \$due }}**. Contact support to check whether this domain can still be renewed.\n\n".$button('invoice_url','View invoice')."\n@else\nYour renewal invoice is ready for **{{ \$years }} {{ \$years === 1 ? 'year' : 'years' }}** ({{ \$amount }}). Pay it by **{{ \$due }}** and we'll renew the domain for you.\n\n"
                    .$button('invoice_url','Pay invoice')."\n@endif\n@elseif(\$invoice_on)\nYour renewal invoice is scheduled for **{{ \$invoice_on }}**. Pay it by the due date and we'll renew the domain for you.\n@elseif(\$complimentary)\nThis domain has complimentary pricing. Contact support to confirm its renewal.\n\n".$button('domain_url','View domain')."\n@else\nRenewal is turned off. To keep this domain, contact support before **{{ \$expires }}**. After it expires, websites and email that use it may stop working.\n\n".$button('domain_url','View domain')."\n@endif"],
            'domains_domain_expired'=>$common+['name'=>'Domain expired','subject'=>'{{ $domain }} has expired',
                'in_app_title'=>'{{ $domain }} has expired','in_app_body'=>'Contact support to check whether this domain can still be renewed.',
                'body'=>"# {{ \$domain }} expired on {{ \$expires }}\n\nWebsites and email that use this domain may stop working. Contact support to check whether it can still be renewed.\n\n".$button('domain_url','View domain')],
            'domains_transfer_code_paid'=>$transfer+['name'=>'Paid transfer needs a code','subject'=>'Enter the transfer code for {{ $domain }}',
                'body'=>"Thanks for your payment. To move {{ \$domain }} to us, enter its transfer code (also called an auth code). Your current registrar gives you this code. We'll check the transfer once we have it.\n\n".$cutoff],
            'domains_transfer_code_reminder'=>$transfer+['name'=>'Transfer code deadline reminder','subject'=>'Reminder: transfer code for {{ $domain }}',
                'body'=>"We still need the transfer code for {{ \$domain }}. ".$cutoff],
            'domains_domain_order_refunded'=>array_replace($common,['name'=>'Domain order credited','subject'=>'Account credit for your {{ $action }} of {{ $domain }}',
                'mail_enabled'=>'force','in_app_title'=>'{{ $amount }} added to your account credit',
                'in_app_body'=>'Your {{ $action }} of {{ $domain }} was cancelled.',
                'body'=>"# Your {{ \$action }} of {{ \$domain }} was cancelled\n\nWe added **{{ \$amount }}** to your account credit. You can use this credit toward a future invoice.\n\n".$button('domain_url','View domain')]),
        ];
    }
}
