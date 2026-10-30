<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Missing is one word for customers and staff, and every Missing says what to do next.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts;
use Illuminate\Support\Facades\Blade;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage, DomainSummary, Missing, OwnedDomain};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaryStrings, OwnerFormValidation, ReminderTemplates};

storefrontViews();
$root=dirname(__DIR__).'/Domains';
$norm=static fn(string $html)=>preg_replace('/\s+/',' ',trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5)));
$summary=static fn(string $kind,array $more)=>new DomainSummary('next-step.example',null,$kind,'domain.registration.unknown',[],null,null,[],DomainSummaryStrings::all(),...[
    'expiry'=>null,'expiryPrecision'=>null,'expirySource'=>null,'freshness'=>[],'renewalDueAt'=>null,'renewalPrice'=>null,'relatedServices'=>[],'hostnames'=>[],'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create'],...$more]);

// The same word for both: the customer line and the staff cell print it from one constant, and the customer wording is gone.
$customer=DomainListRow::expires($summary('managed',['expiryWaits'=>'registration']));
same(false,array_key_exists('waiting',$customer),'No customer-only waiting state');
$customerLine=$norm((string)Missing::html($customer['cause'],$customer['fix']));
same('Missing Fills in by itself when the registration finishes',$customerLine,'Customer sees Missing and when it fills in');
$staffHtml=(string)(new ReflectionMethod(TldFacts::class,'missing'))->invoke(null,'Use Sync prices on the TLD list.');
same(Missing::LABEL,strtok($customerLine,' '),'Customer word');
expect(str_contains($staffHtml,'>'.Missing::LABEL.'<'),'Staff word');
expect(str_contains($staffHtml,'Use Sync prices on the TLD list.'),'Staff next step');
foreach(['resources/views/index.blade.php','Presentation/DomainListRow.php','Presentation/StatusPill.php'] as $file){
    same(false,str_contains((string)file_get_contents($root.'/'.$file),'Not yet'),$file.' has no customer-only word');
}

// Red with the warning icon when the reader must act. Muted, with no icon, when it fills in by itself or needs nothing.
foreach(['Fills in by itself within a day','No time recorded. No action needed.'] as $calm){
    same(['gray',null,true],[Missing::color($calm),Missing::icon($calm),Missing::needsNothing($calm)],$calm);
    $html=(string)Missing::html($calm);
    expect(str_contains($html,'domains-missing--calm')&&!str_contains($html,'<svg'),'Muted markup: '.$calm);
}
foreach(['Sync failed. Use Resume sync.',null,'Check at your registrar'] as $act){
    same([Missing::COLOR,Missing::ICON,false],[Missing::color($act),Missing::icon($act),Missing::needsNothing($act)],(string)$act);
    $html=(string)Missing::html($act,['label'=>'Contact support','url'=>'/tickets/create']);
    expect(!str_contains($html,'domains-missing--calm')&&str_contains($html,'<svg'),'Red markup: '.(string)$act);
}
same(Missing::FILLS.' within a day',Missing::fills('within a day'),'Fill-in line');

// No support link set up: the next step still shows, as plain words.
same('Missing Contact support',$norm((string)Missing::html(null,Missing::support(null))),'Next step without a link');
same(['label'=>'Contact support','url'=>'/tickets/create'],Missing::support('/tickets/create'),'Next step with a link');
same('<a',substr(ltrim(strstr((string)Missing::html(null,Missing::support('/tickets/create')),'<a')),0,2),'Link when one exists');

// Checkout's line stays byte for byte as it was: the cart lane owns its words.
same('<span class="domains-missing"><svg class="domains-i" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>Missing</span> <span class="domains-missing__cause">Not set for this TLD yet</span>',str_replace("\n",'',view('domains::partials.missing',['cause'=>'Not set for this TLD yet'])->render()),'Checkout Missing line is unchanged');

// Auth code row: the fix fits the phase.
$support=Missing::support('/tickets/create');
$authCode=static fn(array $transfer,array $setup=[])=>DomainPage::authCode(new OwnedDomain('code.example','Active','ok',null,'On',null,['code'=>'/code','setup'=>'/setup'],[],$setup,$transfer,[],false),'/tickets/create');
same(null,$authCode(['open'=>true]),'Open form needs no Missing');
same(['missing'=>false,'action'=>['label'=>'Change auth code','url'=>'/code']],$authCode(['open'=>true,'received'=>true]),'Saved code can change');
same(['missing'=>false,'action'=>['label'=>'View transfer','url'=>'/code']],$authCode(['received'=>true]),'Closed saved code can be viewed');
same(['missing'=>true,'fix'=>['label'=>'Update details','url'=>'/setup']],$authCode(['phase'=>'cutoff'],['contacts'=>true]),'Past the cutoff the fix is the details form');
same(['missing'=>true,'fix'=>$support],$authCode(['phase'=>'cutoff']),'No form, so ask us');
same(['missing'=>true,'fix'=>$support],$authCode(['phase'=>'late'],['contacts'=>true]),'Other phases ask us');

// A record address says what the chosen type needs.
$address=new ReflectionMethod(OwnerFormValidation::class,'recordAddressMessage');
foreach(['a'=>'Enter an IPv4 address, such as 192.0.2.1.','AAAA'=>'Enter an IPv6 address, such as 2001:db8::1.',' mx '=>'Enter a hostname ending in a dot, such as mail.example.com.','URL'=>'Enter a web address, such as https://example.com.','TXT'=>'Enter the text for this record.'] as $type=>$words){
    same($words,$address->invoke(null,'dns.records.0.address',['records'=>[['type'=>$type]]]),'Address words for '.$type);
}
same(null,$address->invoke(null,'dns.records.0.address',['records'=>[['type'=>'NOPE']]]),'Unknown type keeps the default words');
same(null,$address->invoke(null,'dns.records.0.name',['records'=>[['type'=>'A']]]),'Other fields keep their words');

// Auto-renew off: only a reader who can turn it on back is told to.
foreach(['in_app_body','body'] as $part){
    preg_match('/@if\(\$can_turn_on\).*?@endif/s',ReminderTemplates::definitions()['domains_domain_expiring'][$part],$keep);
    expect(str_contains(Blade::render($keep[0],['can_turn_on'=>true,'expires'=>'March 1, 2027']),'turn it on or renew it on the domain page'),$part.' offers the page');
    $stuck=Blade::render($keep[0],['can_turn_on'=>false,'expires'=>'March 1, 2027']);
    expect(str_contains($stuck,'contact support before')&&!str_contains($stuck,'turn it on'),$part.' sends the rest to support');
}

// Every Missing in the code carries a cause or a fix. Checkout belongs to the cart lane and is pinned byte for byte until it lands.
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));$checked=0;
foreach($files as $file){
    $path=(string)$file;if(!preg_match('/\.php$/',$path)){continue;}
    $text=(string)file_get_contents($path);$name=substr($path,strlen($root)+1);
    foreach(['/partials\.missing\'\s*,\s*\[\s*\'cause\'\s*=>\s*null\s*,\s*\'fix\'\s*=>\s*null/','/(?:Missing|\$missing)::html\(\)/','/MissingEntry::apply\([^,()]*(?:\([^()]*\)[^,()]*)*\)/'] as $bare){
        same(0,preg_match($bare,$text),'Bare Missing in '.$name);
    }
    $checked+=preg_match_all('/partials\.missing|Missing::html\(|MissingEntry::apply\(/',$text);
}
expect($checked>20,'Sweep found the Missing sites');
$nativeSuccessful=true;echo "Missing next step checks passed.\n";
