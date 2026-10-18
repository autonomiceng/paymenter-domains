<?php
// Actual native mail and Filament rendering, synthetic data only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';require __DIR__.'/Support/staff-tlds.php';
use App\Models\NotificationTemplate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,ReminderTemplates};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');$strings=[];
$base=['domain'=>'example.com','expires'=>'October 18, 2027','days'=>7,'invoice'=>false,'invoice_on'=>null,'due'=>'October 11, 2027',
    'overdue'=>false,'complimentary'=>false,'years'=>1,'amount'=>'$12.00','invoice_url'=>'https://example.test/invoices/123','domain_url'=>'https://example.test/domains/123',
    'deadline'=>'Nov 17, 2026, 12:00 PM UTC','action'=>'transfer'];
$cases=[
    'renew-invoice'=>['domains_domain_expiring',['invoice'=>true,'years'=>3,'amount'=>'$36.00']],
    'renew-overdue'=>['domains_domain_expiring',['invoice'=>true,'years'=>3,'amount'=>'$36.00','days'=>1,'overdue'=>true]],
    'renew-planned'=>['domains_domain_expiring',['days'=>30,'invoice_on'=>'September 27, 2027']],
    'renew-complimentary'=>['domains_domain_expiring',['days'=>30,'complimentary'=>true]],
    'renew-off'=>['domains_domain_expiring',[]],
    'expired'=>['domains_domain_expired',[]],
    'transfer-paid'=>['domains_transfer_code_paid',['domain_url'=>'https://example.test/domains/123/transfer-code']],
    'transfer-deadline'=>['domains_transfer_code_reminder',['domain_url'=>'https://example.test/domains/123/transfer-code']],
    'credit'=>['domains_domain_order_refunded',[]],
    'renew-long'=>['domains_domain_expiring',['domain'=>str_repeat('long-domain-',4).'end.example.com','invoice'=>true,'years'=>3,'amount'=>'$36.00']],
];
foreach($cases as $name=>[$key,$values]){
    $data=array_replace($base,$values);$mail=new DomainNotice(NotificationTemplate::where('key',$key)->sole(),$data);
    $html=$mail->render();$text=view('domains::mail-text',$mail->content()->with)->render();
    $strings['emails'][$name]=['template'=>$key,'subject'=>$mail->envelope()->subject,'text'=>$text];
    // Holding identity never enters scalar customer data. Both route cases are byte-identical.
    foreach(['enom','namesilo'] as $holder){file_put_contents($out.'/'.$name.'-'.$holder.'.html',$html);file_put_contents($out.'/'.$name.'-'.$holder.'.txt',$text);}
    file_put_contents($out.'/'.$name.'-text.html','<!doctype html><html><meta name="viewport" content="width=device-width"><title>Plain text part</title><body style="margin:24px"><pre style="font:16px/1.6 monospace;white-space:pre-wrap;overflow-wrap:anywhere">'.e($text).'</pre></body></html>');
    expect(!preg_match('/enom|namesilo|PRIVATE-/i',$html.$text),'Neutral customer parts');
}
[$d,$bill,$op]=$make('staff-held-renewal.com');$op->update(['state'=>'held']);$enable();$reminders->remind($d->id);
[$unknown]=$make('staff-unknown-price.com');$rate=\Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$unknown->server_id)->where('action','renew')->sole();$rate->update(['revision'=>$rate->revision+1,'retail'=>null]);$reminders->remind($unknown->id);
tldPanel();Auth::setUser($staff);$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
foreach(['staff-held'=>[ViewDomain::class,['record'=>$d->id]],'staff-unknown'=>[ViewDomain::class,['record'=>$unknown->id]],'staff-setup'=>[RegistrarSetup::class,[]]] as $name=>[$class,$params]){
    $test=Livewire::actingAs($staff)->test($class,$params)->assertSuccessful();
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(!str_contains($html,'PRIVATE-')&&!str_contains($html,'account-enom'),'Staff fixture protects private facts');file_put_contents($out.'/'.$name.'.html',$html);
}
$strings['staff']=ObligationAlerts::LABELS;$strings['setup']=\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule($d->server_id);
file_put_contents($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Ten native customer cases HTML/text both holders and three native staff surfaces rendered.\n";$nativeSuccessful=true;
