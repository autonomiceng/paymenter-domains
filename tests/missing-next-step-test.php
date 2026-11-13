<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Missing is one word for customers and staff, and every Missing says what to do next.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist, TldFacts};
use Illuminate\Support\Facades\{Blade, Route};
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
// slop-sweep: the account-table cell shows the word alone; its next step is said once in the row note.
$staff=TldFacts::priceState(['prices'=>[['action'=>'renew','term'=>1,'retail'=>null,'synced'=>true,'flags'=>[]]],'selected'=>true,'refresh'=>[]],'renew','retail',false);
same(Missing::LABEL,strtok($customerLine,' '),'Customer word');
same(Missing::LABEL,$staff['value'],'Staff word');
expect(str_contains((string)$staff['cause'],'Edit prices must supply a valid price.'),'Staff next step');
foreach(['resources/views/index.blade.php','Presentation/DomainListRow.php','Presentation/StatusPill.php'] as $file){
    same(false,str_contains((string)file_get_contents($root.'/'.$file),'Not yet'),$file.' has no customer-only word');
}

// Waiting for a transfer code is actionable; its future registrar expiry is a normal waiting fact.
$order=['state'=>'paid','kind'=>'transfer','next'=>'awaiting_code'];
$waiting=$summary('managed',['order'=>$order,'statusState'=>'awaiting_code','expiryWaits'=>'transfer']);
$expiry=DomainListRow::expires($waiting);
same([false,'Shows when the transfer finishes'],[$expiry['missing'],$expiry['value']],'Waiting-code expiry says when the registrar date shows, not Missing');
$pending=new OwnedDomain('waiting.example','Action required','warn',null,'',null,['show'=>'/domains/1','code'=>'/domains/1/code'],[],
    ['contacts'=>false,'required'=>false],['open'=>true,'received'=>false],[],false,statusReason:'Auth code needed',order:$order);
$renewal=storefrontDom(view('domains::partials.owner-renew',['domain'=>$pending,'summary'=>$waiting,'support'=>'/tickets/create'])->render());
same('Shows when the transfer finishes',trim($renewal->query('//div[dt="Expires"]/dd')->item(0)->textContent),'Domain page expiry says when it shows, with no Missing or support link');
same(0,$renewal->query('//div[dt="Expires"]/dd//svg')->length,'Domain page expiry has no danger mark before code entry');
$notice=DomainPage::notice($pending,'/tickets/create',$waiting);
same(['warn','Enter the auth code from your current registrar.',['label'=>'Enter auth code','url'=>'/domains/1/code']],
    [$notice['tone'],$notice['text'],$notice['action']],'The code action stays conspicuous above the calm expiry');

// Red with the hexagon when the reader must act. Muted, with no icon, when it fills in by itself or needs nothing.
foreach(['Fills in by itself within a day','No time recorded. No action needed.'] as $calm){
    same(['gray',null,true],[Missing::color($calm),Missing::icon($calm),Missing::needsNothing($calm)],$calm);
    $html=(string)Missing::html($calm);
    expect(str_contains($html,'domains-missing--calm')&&!str_contains($html,'<svg'),'Muted markup: '.$calm);
}
foreach(['Sync failed. Use Resume sync.',null,'Check at your registrar'] as $act){
    same([Missing::COLOR,Missing::ICON,false],[Missing::color($act),(string)Missing::icon($act),Missing::needsNothing($act)],(string)$act);
    $html=(string)Missing::html($act,['label'=>'Contact support','url'=>'/tickets/create']);
    expect(!str_contains($html,'domains-missing--calm')&&str_contains($html,'<svg'),'Red markup: '.(string)$act);
}
same(Missing::FILLS.' within a day',Missing::fills('within a day'),'Fill-in line');

// No support link set up: the next step still shows, as plain words.
same('Missing Contact support',$norm((string)Missing::html(null,Missing::support(null))),'Next step without a link');
same(['label'=>'Contact support','url'=>'/tickets/create'],Missing::support('/tickets/create'),'Next step with a link');
same('<a',substr(ltrim(strstr((string)Missing::html(null,Missing::support('/tickets/create')),'<a')),0,2),'Link when one exists');
// Tickets off must not link the dead ticket page: the host's contact, else staff get the next step.
$contact=static fn()=>trim(view('domains::partials.contact-support')->render());
config(['settings.tickets_disabled'=>true,'domains.support_url'=>'mailto:help@example.test']);
same('<a class="domains-a" href="mailto:help@example.test">Contact support</a>',$contact(),'Tickets off, contact set');
same('mailto:help@example.test',Missing::supportUrl(),'Same link for Missing and the sentence');
$staffCheck=static fn()=>collect(AccountChecklist::system()['failing'])->firstWhere('label','Support contact');
same(null,$staffCheck(),'Staff are not asked to fix a working contact');
foreach([null,'javascript:alert(1)','mailto:','tel:','https:','https://',' https:// ','https://?x'] as $unusable){
    config(['domains.support_url'=>$unusable]);
    same('',$contact(),'No promise and no dead link without a contact');
    expect(str_contains($staffCheck()['cause']??'','Turn tickets on, or ask your site admin to set a support link'),'Staff must get the next step');
}
config(['domains.support_url'=>' https://help.example.test ']);
same('https://help.example.test',Missing::supportUrl(),'Outer spaces do not break a real link');
config(['domains.support_url'=>'http://help.example.test/contact']);
same('http://help.example.test/contact',Missing::supportUrl(),'A plain http link with a host works');
config(['domains.support_url'=>null,'settings.tickets_disabled'=>false]);
$routes=Route::getRoutes();Route::setRoutes(new \Illuminate\Routing\RouteCollection());
$cause=$staffCheck()['cause']??'';
Route::setRoutes($routes);
expect(str_contains($cause,'support link')&&!str_contains($cause,'Turn tickets on'),'Tickets on with no ticket page: the fallback step, not "turn tickets on"');
config(['settings.tickets_disabled'=>false,'domains.support_url'=>'mailto:help@example.test']);
same(route('tickets.create'),Missing::supportUrl(),'Tickets on: the ticket form');
same(null,$staffCheck(),'Tickets on: nothing for staff to fix');
config(['domains.support_url'=>null]);

// Checkout keeps its exact cause and the shared Missing hexagon.
same('<span class="domains-missing">'.Missing::ICON.'Missing</span> <span class="domains-missing__cause">Not set for this TLD yet</span>',str_replace("\n",'',view('domains::partials.missing',['cause'=>'Not set for this TLD yet'])->render()),'Checkout Missing uses the shared mark and unchanged cause');

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
expect($checked>10,'Sweep found the Missing sites');
$nativeSuccessful=true;echo "Missing next step checks passed.\n";
