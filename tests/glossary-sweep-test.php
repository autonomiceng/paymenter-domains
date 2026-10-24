<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One term per concept on the help page, emails, invoice lines and order pages.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';require_once __DIR__.'/Support/template-defaults.php';
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
use App\Models\NotificationTemplate;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController,OwnerHelpController,OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
use Paymenter\Extensions\Servers\Domains\Services\{InvoiceDescription,OwnerHelp,ReminderTemplates,WatchNoticeTemplates};
config(['settings.company_name'=>'Example Hosting']);
storefrontViews();
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
$plain=static fn(string $html):string=>trim(preg_replace('/\s+/u',' ',html_entity_decode(preg_replace('/<[^>]+>/',' ',preg_replace('~<(style|script|svg)\b[^>]*>.*?</\1>~si','',$html)),ENT_QUOTES)));
$none=static function(string $text,array $old,string $where):void { foreach($old as $s){expect(!str_contains($text,$s),$where.' drops "'.$s.'"');} expect(!str_contains($text,'—'),$where.' has no em dash'); };
$source=static fn(string $class):string=>(string)file_get_contents((new ReflectionClass($class))->getFileName());

// Help: the auth code and WHOIS privacy headings keep their old anchors, so old links still land on them.
same(['nameservers'=>'Nameservers','glue-records'=>'Glue records','dns-records'=>'DNS records','dnssec'=>'DNSSEC','transfer-lock'=>'Transfer lock',
    'transfer-code'=>'Auth code','privacy'=>'WHOIS privacy','contacts'=>'Contacts','auto-renew'=>'Auto-renew'],OwnerHelp::ANCHORS,'Help headings use the glossary terms');
same('Also called an EPP code. Treat it like a password.',OwnerHelp::SUMMARIES['transfer-code'],'Auth code popover: the help page names transfer code once');
$md=file_get_contents(OwnerHelp::path());
expect(str_contains($md,"How each setting on a domain page works. Changes show in History once the registrar confirms them."),'Help intro');
same(1,substr_count(strtolower($md),'transfer code'),'"transfer code" appears once, as the other name for the auth code');
same(1,substr_count($md,'(also called EPP code or transfer code)'),'The auth code names its other terms once');
expect(str_contains($md,'**Use the default nameservers**'),'Help names the nameservers button as the page does');
$none($md,['Activity','Domain privacy','domain privacy','administrative contact','EPP code)','auth code or EPP','could not','cannot','do not','does not'],'Help page');
$help=$plain((new OwnerHelpController)->show()->render());
foreach(['Auth code','WHOIS privacy','History'] as $term){expect(str_contains($help,$term),'Rendered help says '.$term);}

// Emails: definitions use the glossary terms, said once.
$r=ReminderTemplates::definitions();
same(['Transfer needs an auth code','Enter the auth code for {{ $domain }}','Enter the auth code for {{ $domain }}','Auth code reminders'],
    [$r['domains_transfer_code_paid']['name'],$r['domains_transfer_code_paid']['subject'],$r['domains_transfer_code_paid']['in_app_title'],$r['domains_transfer_code_paid']['edit_preference_message']],'Paid transfer email terms');
same(['Auth code deadline reminder','Reminder: auth code for {{ $domain }}'],[$r['domains_transfer_code_reminder']['name'],$r['domains_transfer_code_reminder']['subject']],'Deadline reminder terms');
expect(str_contains($r['domains_transfer_code_paid']['body'],'enter its auth code (also called EPP code).')&&str_contains($r['domains_transfer_code_paid']['body'],'>Enter auth code</a>'),'Paid transfer body and button');
expect(str_starts_with($r['domains_transfer_code_reminder']['body'],'We still need the auth code for {{ $domain }}.'),'Reminder body');
expect(str_contains($r['domains_domain_expiring']['in_app_body'],'@else Auto-renew is off. To keep this domain, contact support before it expires.@endif'),'Expiring in-app: Auto-renew is off');
expect(str_contains($r['domains_domain_expiring']['body'],"Auto-renew is off. To keep this domain, contact support before **{{ \$expires }}**."),'Expiring email: Auto-renew is off');
$none(json_encode($r,JSON_UNESCAPED_UNICODE),['transfer code','Transfer code','Renewal is turned off','could not','did not'],'Reminder templates');
$w=WatchNoticeTemplates::definitions();
same(['Monitoring alert: expires soon','Monitoring alert: expired','Monitoring alert: nameservers changed','Monitoring alert: nameservers restored'],array_column($w,'name'),'Monitoring alert names');
same(['Monitoring alerts'],array_values(array_unique(array_column($w,'edit_preference_message'))),'Monitoring alerts preference');
same('{{ $domain }} has expired',$w['domains_watch_expired']['subject'],'Expired subject');
same('As of {{ $date }}, {{ $domain }} no longer uses {{ $nameservers }}. If you didn’t expect this, contact support.',$w['domains_watch_ns_changed']['in_app_body'],'Nameservers changed body');
foreach($w as $key=>$t){
    $body=$plain($t['body']);
    expect(str_ends_with($body,'View domain You get this because monitoring alerts are on. Turn them off on the domain page.'),'Footer said once, after the link: '.$key);
    $none($body.$t['subject'],['registration provider','Domain checks','domain check','public domain check','not a bill','To pause notices','provided by','did not','could not','Registry expiry date reached'],'Watch email '.$key);
}

// Every template uses the same configured name and no-name wording as pages.
foreach ([['Example Hosting','Ignored','Example Hosting'],[' Example Hosting (staging) ','Ignored','Example Hosting'],[null,'Example App (testing)','Example App'],[null,' (local) ','']] as [$setting,$appName,$expected]) {
    config(['settings.company_name'=>$setting,'app.name'=>$appName]);
    $n=new \Paymenter\Extensions\Servers\Domains\Models\WatchNotice(['target_kind'=>'watch','watch_id'=>1,'data'=>['domain'=>'example.com','date'=>'2027-01-15']]);
    $data=\Paymenter\Extensions\Servers\Domains\Services\WatchNoticeDelivery::data($n);
    same($expected,$data['company'],'Notification company fallback');
    $rendered=\Illuminate\Support\Facades\Blade::render($w['domains_watch_ns_restored']['subject'],$data);
    same('example.com uses '.($expected !== '' ? $expected.' nameservers' : 'our nameservers').' again',$rendered,'Rendered notification fallback');
}
config(['settings.company_name'=>'Example Hosting']);

// Upgrades replace only exact earlier defaults, field by field; edited fields stay.
ReminderTemplates::ensure();WatchNoticeTemplates::ensure();
foreach([...priorReminderDefaults(),...priorWatchDefaults()] as $key=>$fields){
    $current=($r+$w)[$key];$t=NotificationTemplate::where('key',$key)->sole();
    foreach(array_intersect_key($fields,array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('notification_templates'))) as $field=>$versions){
        foreach($versions as $old){
            $t->forceFill([$field=>$old])->save();ReminderTemplates::ensure();WatchNoticeTemplates::ensure();
            same($current[$field],$t->fresh()->getAttribute($field),'Earlier default upgrades: '.$key.'.'.$field);
        }
        $t->forceFill([$field=>'Custom '.$field])->save();ReminderTemplates::ensure();WatchNoticeTemplates::ensure();
        same('Custom '.$field,$t->fresh()->getAttribute($field),'Edited field stays: '.$key.'.'.$field);
        $t->forceFill([$field=>$current[$field]])->save();
    }
}

// Invoice lines: WHOIS privacy, still capped at 255 characters.
$issued=new DateTimeImmutable('2026-10-19T12:00:00Z');
same('WHOIS privacy example.com: Oct 19, 2026 - Oct 19, 2027 (from purchase)',InvoiceDescription::privacy('example.com',null,$issued),'Privacy invoice line');
$long=str_repeat('a',63).'.'.str_repeat('b',63).'.'.str_repeat('c',63).'.'.str_repeat('d',57).'.com';
same(255,mb_strlen(InvoiceDescription::privacy($long,null,$issued)),'Long privacy line stays at the cap');

// Order pages: TLD, auth code, WHOIS privacy; "per year" said once on the price list.
$data=storefrontData();
$search=view('domains::search',$data)->render();$dom=storefrontDom($search);
same(['TLD'],array_map(fn($n)=>$n->textContent,iterator_to_array($dom->query('//label[@for="domains-tld"]'))),'Search TLD label');
expect(str_contains($plain($search),'Type the name you want. We check it across every TLD we sell.'),'Search lede');
same(['First-year prices'],array_map(fn($n)=>$n->value,iterator_to_array($dom->query('//p[@class="domains-endings"]/@aria-label'))),'Price list has no noun');
$none($plain($search),['ending','Ending'],'Search page');
$pricing=view('domains::pricing',[...$data,'rows'=>$data['endings']])->render();$dom=storefrontDom($pricing);
same(['TLD','Register','Renew','Transfer','Register for'],array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('//thead/tr/th'))),'Price list columns');
same(3,$dom->query('//td[@data-label="Renew"]')->length,'Stacked renew label');
same(1,substr_count($plain($pricing),'per year'),'"per year" said once, in the lede');
$none($plain($pricing),['ending','Ending','first year','adds 1 year'],'Price list');
$transfer=view('domains::transfer',[...$data,'results'=>[]])->render();$dom=storefrontDom($transfer);
expect(str_contains($plain($transfer),'Turn off the transfer lock. Get the auth code (also called EPP code). You’ll enter it after checkout.'),'Transfer steps');
same(['TLD','Transfer (adds 1 year)'],array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('//thead/tr/th'))),'Transfer price columns');
$none($plain($transfer),['ending','Ending','transfer code','auth or EPP','registrar lock','includes 1 year'],'Transfer page');
$line=static fn(?string $price)=>$plain(view('domains::checkout-line',['domain'=>['name'=>'example.com','action'=>'Registration','years'=>1,'renewalPrice'=>$price,'editUrl'=>'/cart/1']])->render());
expect(str_contains($line('$14.00'),'Renews at $14.00/yr'),'Cart line renewal price');
expect(str_contains($line(null),'Renewal price: Missing Not set for this TLD yet'),'Cart line: Missing with its cause');
expect(!str_contains($line(null),'unavailable'),'Cart line never says unavailable');
$none(file_get_contents(dirname(__DIR__).'/Domains/resources/views/cart-edit.blade.php'),['ending'],'Cart edit');

// Setup and auth code pages.
$owned=static fn(array $setup,array $transfer)=>new OwnedDomain('example.com','In progress','info',null,'',null,['show'=>'/domains/1','setupSave'=>'/domains/1/setup','codeSave'=>'/domains/1/code','contactsSave'=>'/domains/1/contacts'],[],
    ['contacts'=>false,'required'=>true,'nameservers'=>true,'privacy'=>true,'defaults'=>true,'maxNameservers'=>12,...$setup],$transfer,[],false);
$setup=view('domains::setup',['domain'=>$owned([],[])])->render();$dom=storefrontDom($setup);
same(['Finish setup'],array_map(fn($n)=>$n->textContent,iterator_to_array($dom->query('//h1'))),'Setup title');
same(0,$dom->query('//input[@name="confirmed"]')->length,'No confirm checkbox: the button confirms');
expect(str_contains($plain($setup),'Turn on WHOIS privacy (free)'),'Setup privacy option');
$held=$plain(view('domains::setup',['domain'=>$owned(['required'=>false],[])])->render());
expect(str_contains($held,'Under review Your order is on hold. Contact support.'),'Held setup: pill and one line, no reason line');
$transferDto=['open'=>true,'received'=>true,'paid'=>true,'deadline'=>'Nov 17, 2026, 12:00 UTC','held'=>false,'unchecked'=>true,'phase'=>'pending'];
$code=view('domains::code',['domain'=>$owned(['required'=>false],$transferDto)])->render();$dom=storefrontDom($code);$codeText=$plain($code);
same(['Auth code'],array_map(fn($n)=>$n->textContent,iterator_to_array($dom->query('//h1'))),'Code page title');
same(['Auth code'],array_map(fn($n)=>$n->textContent,iterator_to_array($dom->query('//label[@for="transfer-code-input"]'))),'Code field label');
same(['Save'],array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('//form//button[@type="submit"]'))),'Code button');
same(1,substr_count($codeText,OwnerActionController::CODE_SAVED),'Saved line said once');
expect(str_contains($codeText,'Expires Missing Shows when the transfer completes'),'Unchecked registry record: Expires Missing with its cause');
$none($codeText,['transfer code','Transfer code','public registration record','Code saved','auth code or EPP'],'Auth code page');
$cutoff=$plain(view('domains::code',['domain'=>$owned(['required'=>false],[...$transferDto,'open'=>false,'phase'=>'cutoff','unchecked'=>false])])->render());
expect(str_contains($cutoff,'The time to enter or change this auth code has passed.'),'Cutoff names the auth code');

// Controllers and services: renamed terms, "can't / couldn't" voice.
same('Auth code saved. The transfer starts once payment is confirmed.',OwnerActionController::CODE_SAVED,'One saved string for page and flash');
$owner=$source(OwnerActionController::class);
expect(str_contains($owner,"'Enter a valid auth code.'")&&str_contains($owner,"'Change requested. Check History for updates.'")&&str_contains($owner,"'WHOIS privacy isn’t available for this domain.'"),'Owner action messages');
$none($owner,['transfer code','Check Activity','Domain privacy','is not available',"get('confirmed')!=='1'"],'Owner action controller');
$renewal=$source(OwnerRenewalController::class);
expect(str_contains($renewal,"'You can’t renew online right now. Contact support to renew this domain.'")&&str_contains($renewal,"'Auto-renew is off for this domain. Contact support if you want to renew it.'"),'Renewal refusals');
$none($renewal,['is not available','Renewal is turned off'],'Owner renewal controller');
$cart=$source(\Paymenter\Extensions\Servers\Domains\Services\CartAdmission::class);
foreach(["'Enter the auth code after checkout.'","'Registrar changed. Search again.'","'Test domains can’t be ordered. Remove them to check out.'","'Transfers can’t be ordered yet. Remove them to check out.'","'Domains can’t be ordered right now. Remove them to check out.'"] as $s){expect(str_contains($cart,$s),'Cart message '.$s);}
$none($cart,['Seller changed','TEST domains','TEST transfer','unavailable','transfer code after'],'Cart admission');
expect(str_contains($source(\Paymenter\Extensions\Servers\Domains\Services\NativeCancellation::class),"'Can’t cancel this domain right now. Contact support.'"),'Native cancellation refusal');
expect(str_contains($source(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController::class),"'Choose a TLD from the list.'"),'Transfer TLD error');
expect(str_contains($source(\Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController::class),"'Check the TLD after the dot, for example '"),'Search TLD error');
expect(str_contains($source(\Paymenter\Extensions\Servers\Domains\Services\NativeActivationInformation::class),'We may ask for the auth code from your current registrar.'),'Activation information');
expect(str_contains($source(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class),"['name'=>'Domain prices',"),'Client nav names the prices page by its title');
// Previous defaults upgrade twice; custom text is retained.
foreach ($w as $key=>$fields) {
    $t=NotificationTemplate::where('key',$key)->sole();
    foreach (array_intersect(['name','subject','in_app_title','in_app_body','body'],\Illuminate\Support\Facades\Schema::getColumnListing('notification_templates')) as $field) {
        $old=str_replace('{{ $nameservers }}','{{ $company }} nameservers',$fields[$field]);
        if ($key==='domains_watch_ns_restored' && $field==='name') { $old=hex2bin('4d6f6e69746f72696e6720616c6572743a206e616d6573657276657273206261636b20746f204265636f'); }
        $t->setAttribute($field,$old)->save();
        WatchNoticeTemplates::ensure();WatchNoticeTemplates::ensure();
        same($fields[$field],$t->fresh()->getAttribute($field),'Previous default upgrades idempotently: '.$key.'/'.$field);
        $t->setAttribute($field,'Custom '.$old)->save();WatchNoticeTemplates::ensure();
        same('Custom '.$old,$t->fresh()->getAttribute($field),'Operator copy stays: '.$key.'/'.$field);
        $t->setAttribute($field,$fields[$field])->save();
    }
}
$nativeSuccessful=true;echo "Glossary sweep checks passed.\n";
