<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The client Domains list: one list, four columns, Missing with its cause and fix.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema, DomainSummaryStrings, HistoryLinkSchema};

$op=$newTransfer(placeholderComFixtureName('domains-list-placeholder.com'),0,false,false); $d=$op->domain; storefrontViews();
AdoptionSchema::install(); HistoryLinkSchema::install();
$controller=new OwnedDomainController($owners); $session=app('request')->session();
$request=static function(array $query=[])use($session,$user){$r=Request::create('/domains','GET',$query);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
$norm=static fn(?DOMNode $n)=>$n?preg_replace('/\s+/',' ',trim($n->textContent)):null;
$cell=static fn(DOMXPath $dom,DOMNode $row,string $label)=>$norm($dom->query('td[@data-label="'.$label.'"]',$row)->item(0));
$tone=static fn(DOMXPath $dom,DOMNode $row)=>preg_match('/\bdomains-tone-text-(\w+)/',(string)$dom->query('td[@data-label="Expires"]//*[contains(@class,"domains-tone-text-")]/@class',$row)->item(0)?->value,$m)?$m[1]:null;
$calendar=static fn(int $days)=>$clock->now()->setTimezone(new DateTimeZone('UTC'))->modify(($days<0?'':'+').$days.' days');
$day=static fn(int $days)=>$calendar($days)->format('Y-m-d 00:00:00');
$off=Crypt::encryptString(json_encode(['owner_auto_renew'=>false]));
$support=route('tickets.create');

// Registered domains, driven through the controller. case => [domain columns, acquisition columns, Expires, Renewal first line, Renewal sub line, Expires tone]
$cases=[
    'transfer'=>[[],['state'=>'queued'],'Missing Shows when the transfer completes','Auto-renew on',null,null],
    'transfer-held'=>[[],['state'=>'held','safe_code'=>'transfer_setup_review_required'],'Missing Shows when the transfer completes','Auto-renew on',null,null],
    'register-held'=>[['status'=>'registration_pending'],['action'=>'register','state'=>'held','safe_code'=>null],'Missing Shows when the registration completes','Auto-renew on',null,null],
    'active'=>[['status'=>'active','provider_expires_at'=>$day(200)],['state'=>'succeeded'],$calendar(200)->format('M j, Y'),'Auto-renew on',null,null],
    'expiring'=>[['status'=>'active','provider_expires_at'=>$day(10)],['state'=>'succeeded'],$calendar(10)->format('M j, Y').' In 10 days','Auto-renew on','Next invoice: Missing','warn'],
    'renewal-off'=>[['status'=>'active','provider_expires_at'=>$day(200),'renewal_policy'=>$off],['state'=>'succeeded'],$calendar(200)->format('M j, Y'),'Auto-renew off','Cancels '.$calendar(200)->format('M j, Y'),null],
    'no-expiry'=>[['status'=>'active','provider_expires_at'=>null],['state'=>'succeeded'],'Missing Contact support','Auto-renew on',null,null],
    'past-expiry'=>[['status'=>'active','provider_expires_at'=>$day(-1)],['state'=>'succeeded'],$calendar(-1)->format('M j, Y'),'Auto-renew on',null,'bad'],
    'adoption'=>[['status'=>'adoption_pending'],['state'=>'succeeded'],'Missing Shows when the import is confirmed','Auto-renew on',null,null],
];
$base=['status'=>'transfer_pending','provider_expires_at'=>null,'renewal_policy'=>null];
foreach ($cases as $case=>[$domain,$acquisition,$expires,$renewal,$sub,$expiresTone]) {
    DB::table('domains_domains')->where('id',$d->id)->update([...$base,...$domain]);
    DB::table('domains_operations')->where('id',$op->id)->update(['action'=>'transfer','safe_code'=>null,...$acquisition]);
    $html=$controller->index($request())->render(); $list=storefrontDom($html);
    $row=$list->query('//tbody/tr')->item(0);
    same($expires,$cell($list,$row,'Expires'),'Expires cell: '.$case);
    same($expiresTone,$tone($list,$row),'Expires tone: '.$case);
    same($renewal,$norm($list->query('td[@data-label="Renewal"]/*[contains(@class,"domains-cell-main")]',$row)->item(0)),'Renewal value: '.$case);
    if ($sub!==null) { same($sub,$norm($list->query('td[@data-label="Renewal"]/*[contains(@class,"domains-cell-sub")]',$row)->item(0)),'Renewal sub line: '.$case); }
    else { expect(!str_contains((string)$cell($list,$row,'Renewal'),'Next invoice') && !str_contains((string)$cell($list,$row,'Renewal'),'Cancels'),'No invoice or cancel line: '.$case); }
    if (str_contains($expires,'Contact support')) { same($support,$list->query('td[@data-label="Expires"]//a',$row)->item(0)?->getAttribute('href'),'Contact support links the ticket form: '.$case); }
    $page=$norm($list->query('//div[@class="domains"]')->item(0));
    foreach (['Expiry unknown','See details','Pending verification','Planned due','Renewal off','Manage','Apply','in your account','Related to your hosting'] as $old) { expect(!str_contains($page,$old),'Old list copy removed: '.$old.' ('.$case.')'); }
    expect(!preg_match('/\b(enom|namesilo)\b/i',$page),'No registrar names in the list: '.$case);
}

// Hosting, demo and test-mode rows render from the same DTOs; the list never queries in Blade.
$strings=DomainSummaryStrings::all(); $unknown=DnsSignalLabels::keys(null,null,null);
$hosting=[['id'=>1,'label'=>'Starter hosting','url'=>'/services/1'],['id'=>2,'label'=>'Mail hosting','url'=>'/services/2']];
$dto=static fn(string $name,string $kind,array $more)=>new DomainSummary($name,null,$kind,'domain.registration.unknown',$unknown,null,null,[],$strings,...[
    'expiry'=>null,'expiryPrecision'=>null,'expirySource'=>null,'freshness'=>[],'renewalDueAt'=>null,'renewalPrice'=>null,'relatedServices'=>[],'hostnames'=>[],
    'urls'=>['detail'=>'/domains/'.$name,'support'=>'/tickets/create'],...$more]);
$rows=[
    'real.example'=>[$dto('real.example','managed',['expiry'=>'2027-03-01','daysLeft'=>129,'renewalPrice'=>'$14.99','statusState'=>'active']),'Mar 1, 2027','Auto-renew on $14.99/yr',null],
    'demo-held.example'=>[$dto('demo-held.example','managed',['demo'=>true,'expiryWaits'=>'transfer','renewalPrice'=>'$15.99','statusState'=>'held','statusReason'=>'Transfer on hold']),'Missing Shows when the transfer completes','Auto-renew on $15.99/yr','Demo'],
    'test-expiring.example'=>[$dto('test-expiring.example','managed',['testEnvironment'=>true,'expiry'=>'2026-11-02','daysLeft'=>1,'renewalInvoiceMissing'=>true,'statusState'=>'active']),'Nov 2, 2026 In 1 day','Auto-renew on Next invoice: Missing','Test'],
    'watch-expired.example'=>[$dto('watch-expired.example','watch',['demo'=>true,'expiry'=>'2026-10-21','daysLeft'=>-2,'relatedServices'=>$hosting,'relatedOverflow'=>1,'statusState'=>'watch.expired','statusReason'=>'Renew at your registrar']),'Oct 21, 2026','At your registrar','Demo'],
    'watch-unknown.example'=>[$dto('watch-unknown.example','watch',['relatedServices'=>array_slice($hosting,0,1)]),'Missing Check at your registrar','At your registrar',null],
];
$page=new LengthAwarePaginator(array_column($rows,0),count($rows),25);
$html=view('domains::index',['domains'=>$page,'filterCounts'=>[''=>count($rows),'active'=>2,'under_review'=>1,'expired'=>1]])->render(); $dom=storefrontDom($html);
same(['Domain','Status','Expires','Renewal'],array_map($norm,iterator_to_array($dom->query('//thead/tr/th'))),'Four columns, no Actions column');
same(0,$dom->query('//*[contains(@class,"domains-lede")]')->length,'No lede: the All chip shows the count');
same(0,$dom->query('//form[contains(@class,"domains-filter")]//button')->length,'No Apply button: the filters apply on change');
same(['Expires, soonest first','Expires, latest first','Name, A to Z','Name, Z to A'],array_map($norm,iterator_to_array($dom->query('//select[@name="sort"]/option'))),'Sort options say Expires');
same('auto',$dom->query('//form[contains(@class,"domains-filter")]/@data-domains-autosubmit')->item(0)?->value,'Filter form submits on change');
same(0,$dom->query('//section[contains(@class,"domains-related")]')->length,'No separate hosting section');
foreach (array_keys($rows) as $i=>$name) {
    [,$expires,$renewal,$tag]=$rows[$name]; $row=$dom->query('//tbody/tr')->item($i);
    same(['/domains/'.$name],array_map(fn($a)=>$a->value,iterator_to_array($dom->query('.//a[contains(@class,"domains-row-link")]/@href',$row))),'One row link to the domain: '.$name);
    expect(str_contains((string)$row->getAttribute('class'),'domains-linkrow'),'The whole row is the link: '.$name);
    same($name,$norm($dom->query('.//a[contains(@class,"domains-row-link")]',$row)->item(0)),'Row link text is the name: '.$name);
    same($tag===null?[]:[$tag],array_map(fn($n)=>trim($n->firstChild->textContent),iterator_to_array($dom->query('td[1]/span[contains(@class,"domains-demo-tag")]',$row))),'Demo or Test tag after the name: '.$name);
    same($expires,$cell($dom,$row,'Expires'),'Expires cell: '.$name);
    same($renewal,$cell($dom,$row,'Renewal'),'Renewal cell: '.$name);
}
same('Hosting: Starter hosting and 2 more',$norm($dom->query('//tbody/tr[4]/td[1]/*[contains(@class,"domains-cell-sub")]')->item(0)),'Hosting sub line counts every other service');
same('Hosting: Starter hosting',$norm($dom->query('//tbody/tr[5]/td[1]/*[contains(@class,"domains-cell-sub")]')->item(0)),'Hosting sub line names one service');
same(0,$dom->query('//tbody/tr[1]/td[1]/*[contains(@class,"domains-cell-sub")]')->length,'Registered domain without hosting has no sub line');
same('bad',$tone($dom,$dom->query('//tbody/tr[4]')->item(0)),'Past expiry is red');
same('warn',$tone($dom,$dom->query('//tbody/tr[3]')->item(0)),'Expiry within 30 days is amber');
same(['Transfer on hold','Renew at your registrar'],array_map($norm,iterator_to_array($dom->query('//tbody//*[contains(@class,"domains-pill-reason")]'))),'Reason lines sit under their pills');
same(0,$dom->query('//tbody//*[contains(@class,"domains-badge") and contains(normalize-space(),"navailable")]')->length,'No unavailable pills');
expect(!str_contains($norm($dom->query('//div[@class="domains"]')->item(0)),"\u{2014}"),'No em dashes in the list');
expect(!str_contains($html,'Related services') && !str_contains($html,'View domain details'),'Old hosting card copy removed');

// Empty account.
$empty=storefrontDom(view('domains::index',['domains'=>new LengthAwarePaginator([],0,25)])->render());
same('No domains yet',$norm($empty->query('//*[contains(@class,"domains-empty")]/h2')->item(0)),'Empty heading');
same('Register a new name.',$norm($empty->query('//*[contains(@class,"domains-empty")]/p')->item(0)),'Empty text says it once');
same('yourbusiness.com',$empty->query('//*[contains(@class,"domains-empty")]//input[@name="name"]/@placeholder')->item(0)?->value,'Empty search placeholder');
same(0,$empty->query('//*[contains(@class,"domains-empty")]//a')->length,'No second transfer link: the header button says it');
$filtered=view('domains::index',['domains'=>new LengthAwarePaginator([],0,25),'filterCounts'=>[''=>3],'search'=>'zz'])->render();
expect(str_contains((string)$norm(storefrontDom($filtered)->query('//div[@class="domains"]')->item(0)),'No domains match "zz".'),'Filtered empty uses straight quotes');

// Configured names, application fallback and environment suffixes share one rule.
config(['settings.company_name'=>'Example Hosting','app.name'=>'Example App (testing)']);
same('Example Hosting',DnsSignalLabels::company(),'Configured company');
same('Example Hosting',DnsSignalLabels::company(' Example Hosting (local) '),'Environment suffix removed');
same('Example Technologies',DnsSignalLabels::company('Example Technologies (staging)'),'Staging suffix removed');
same('Example App',DnsSignalLabels::company('  '),'Empty company uses the trimmed app name');
config(['settings.company_name'=>null]);
same('Example App',DnsSignalLabels::company(),'Unset company uses app name');
config(['app.name'=>' (testing) ']);
same('',DnsSignalLabels::company(),'Both names empty');
same('our nameservers',DnsSignalLabels::nameservers(),'Unnamed nameservers');
same('Not pointed to us',DnsSignalLabels::configuredStrings()['domain.points.external_empty'],'Unnamed connection label');
same('Transfer :domain to us',\Paymenter\Extensions\Servers\Domains\Services\WatchTransferStrings::all()['watch.transfer_title'],'Unnamed transfer title');
config(['settings.company_name'=>'Example Hosting']);
same('Example (Hosting)',DnsSignalLabels::company('Example (Hosting)'),'Other brackets are kept');
$nativeSuccessful=true;
