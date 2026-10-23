<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeController;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticePreferences as Pref;
require dirname(__DIR__).'/Domains/routes/owned.php';
$f=noticeFixture();extract($f);storefrontViews();$controller=new WatchNoticeController();$session=app('request')->session();
$request=static function($actor,array $data=[],string $method='GET')use($session){$r=Request::create('/domains/checks/watch/1',$method,$data);$r->setUserResolver(fn()=>$actor);$r->setLaravelSession($session);return $r;};
foreach([$id,999999] as $foreign){try{$controller->show($request($other),'watch',$foreign);expect(false,'Foreign locator refuses');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(404,$e->getStatusCode(),'Cross-owner and missing target');}}
$html=$controller->show($request($owner),'watch',$id)->render();$dom=storefrontDom($html);same(1,$dom->query('//form[@method="POST"]')->length,'One preference POST');
same(1,$dom->query('//input[@name="_token"]')->length,'Native CSRF token');same(1,$dom->query('//input[@name="_request_key"]')->length,'Session-bound request key');
$payload=['account_revision'=>1,'target_revision'=>1,'email'=>0,'notices'=>1,'_request_key'=>FormKeys::issue($request($owner),'watch-preferences:watch:'.$id)];
try{$controller->save($request($other,$payload,'POST'),'watch',$id);expect(false,'Cross-owner preference write refuses');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(404,$e->getStatusCode(),'Cross-owner POST');}
same(true,Pref::read($uid,'example.com')['account']['enabled'],'Foreign POST cannot change preference');
$payload['_request_key']=FormKeys::issue($request($owner),'watch-preferences:watch:'.$id);$controller->save($request($owner,$payload,'POST'),'watch',$id);
same(false,Pref::read($uid,'example.com')['account']['enabled'],'Owner POST changes preferences');same('Alerts turned off.',$session->get('watch_saved'),'Saved flash says alerts are off');
$payload['_request_key']=FormKeys::issue($request($owner),'watch-preferences:watch:'.$id);$payload['email']=1;$controller->save($request($owner,$payload,'POST'),'watch',$id);
same(false,Pref::read($uid,'example.com')['account']['enabled'],'Stale revision refuses overwrite');
same('Couldn’t save. Try again.',$session->get('errors')->first('preferences'),'Stale POST returns visible validation message');
try{$controller->save($request($owner,$payload,'POST'),'watch',$id);expect(false,'Consumed form key refuses');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Replay refuses');}
$fresh=Pref::read($uid,'example.com');$controller->save($request($owner,['account_revision'=>$fresh['account']['revision'],'target_revision'=>$fresh['target']['revision'],'email'=>1,'notices'=>1,'_request_key'=>FormKeys::issue($request($owner),'watch-preferences:watch:'.$id)],'POST'),'watch',$id);
same([true,'Alerts turned on.'],[Pref::read($uid,'example.com')['account']['enabled'],$session->get('watch_saved')],'Turning alerts on says so');
$data=$controller->show($request($owner),'watch',$id)->getData();$data['name']=str_repeat('a',63).'.example';
$data['summary']=new Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(...[...get_object_vars($data['summary']),'relatedServices'=>[['label'=>'<script>unsafe</script>','url'=>'/services/1']]]);
$queries=0;DB::listen(static function()use(&$queries){$queries++;});$before=$queries;$long=view('domains::watch-notices',$data)->render();same($before,$queries,'Blade does not query database');
expect(str_contains($long,'&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($long,'<script>unsafe'),'Hosting service name escaped');expect(str_contains($long,$data['name']),'Long domain retained');
// One page for hosting domains: header, one notice, rows with their one action, History and Transfer to Beco. Each fact once.
$session->forget(['watch_saved','errors']);
$text=static fn(string $html)=>trim(preg_replace('/\s+/',' ',strip_tags($html)));$norm=static fn($n)=>trim(preg_replace('/\s+/',' ',$n->textContent));
$state=static function(array $states,string $kind='watch',array $with=[])use($data){$status=Paymenter\Extensions\Servers\Domains\Services\DomainStates::watch($states,'2027-01-15',true);
    return new Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(...[...get_object_vars($data['summary']),'kind'=>$kind,'stateKeys'=>$states,'noticeDates'=>['dns'=>1800000000],'statusState'=>$status[0]??null,'statusReason'=>$status[1]??null,'relatedServices'=>[],'relatedOverflow'=>0,...$with]);};
$on=['account'=>['enabled'=>true,'revision'=>3],'target'=>['enabled'=>true,'revision'=>4]];
$page=static fn(array $states,array $notices=[],array $portal=[],array $with=[],bool $editable=true,?array $preferences=null)=>view('domains::watch-notices',[...$data,'name'=>'example.com','summary'=>$state($states,'watch',$with),'notices'=>$notices,'portal'=>$portal,'editable'=>$editable,'preferences'=>$preferences??$on])->render();
$li=static function(string $html,string $id)use($norm){$x=storefrontDom($html);$q=fn(string $path)=>array_map($norm,iterator_to_array($x->query('//li[@id="'.$id.'"]/'.$path)));return [$q('span[contains(@class,"domains-row__l")]')[0]??null,$q('span[contains(@class,"domains-row__v")]')[0]??null,$q('span[contains(@class,"domains-row__a")]/*')];};
$card=static fn(string $html,string $term)=>array_map($norm,iterator_to_array(storefrontDom($html)->query('//aside//dt[starts-with(normalize-space(.),"'.$term.'")]/following-sibling::dd[1]')))[0]??null;
$notice=static fn(string $html)=>array_map($norm,iterator_to_array(storefrontDom($html)->query('//div[contains(@class,"domains-notice")]')));
$row=static fn(string $kind,string $date='January 15, 2027')=>[['kind'=>$kind,'company'=>'Example Hosting','date'=>$date]];
$plain=$page([],[],[],['nameservers'=>['nameservers'=>['ns1.example.net','ns2.example.net'],'at'=>'2027-01-10T08:00:00Z'],'expiry'=>'2027-06-01','daysLeft'=>200,'relatedServices'=>[['label'=>'Web A','url'=>'/services/1'],['label'=>'Web B','url'=>'/services/2']],'urls'=>['compareTransfer'=>'/domains/checks/watch/1/transfer','support'=>'/tickets/create']]);$dom=storefrontDom($plain);
same('Domains / example.com',$norm($dom->query('//nav[contains(@class,"domains-crumb")]')->item(0)),'Breadcrumb names the domain');
same('Hosting: Web A and 1 more',$norm($dom->query('//*[contains(@class,"domains-ph__sub")]')->item(0)),'Header names its hosting service');same('/services/1',$dom->query('//*[contains(@class,"domains-ph__sub")]/a/@href')->item(0)?->value,'Hosting service is a link');
same(1,substr_count($text($plain),'We check the public registry and nameservers daily.'),'Status help says how often we check');
same([],$notice($plain),'Nothing to do: no notice');same(0,$dom->query('//input[@type="checkbox"]')->length,'No checkboxes');
same(['Nameservers','ns1.example.net ns2.example.net Checked Jan 10, 2027',[]],$li($plain,'nameservers'),'Nameservers with their check date');
same(['Monitoring alerts','On',['Turn off']],$li($plain,'alerts'),'Alerts on: one Turn off');
$fields=[];foreach($dom->query('//form[@id="form-alerts"]/input[@name!="_token" and @name!="_request_key"]') as $n){$fields[$n->getAttribute('name')]=$n->getAttribute('value');}
same(['account_revision'=>'3','target_revision'=>'4','email'=>'1','notices'=>'0'],$fields,'Turn off posts this domain only');
same('form-alerts',$dom->query('//li[@id="alerts"]//button[@type="submit"]/@form')->item(0)?->value,'Turn off submits the alerts form');
same(['Jun 1, 2027','At your registrar'],[$card($plain,'Expires'),$card($plain,'Renewal')],'Renewal card: expiry and where it renews');
same(['Transfer to Beco','/domains/checks/watch/1/transfer'],[$norm($dom->query('//aside//a[contains(@class,"domains-btn")]')->item(0)),$dom->query('//aside//a[contains(@class,"domains-btn")]/@href')->item(0)?->value],'Transfer to Beco is the card action');
same('No changes yet.',$norm($dom->query('//section[@id="history"]//p')->item(0)),'History empty state');
foreach(['Latest notices','Domain check notifications','Public domain checks','Save preferences','registration provider','Registry dates can lag','Compare transfer price'] as $old){expect(!str_contains($plain,$old),'Old copy removed: '.$old);}
same(['Monitoring alerts','Off',['Turn on']],$li($page([],[],[],[],true,[...$on,'target'=>['enabled'=>false,'revision'=>4]]),'alerts'),'Alerts off for this domain: Turn on');
$off=$page([],[],[],[],true,[...$on,'account'=>['enabled'=>false,'revision'=>3]]);
same(['Monitoring alerts','Off for all domains',['Notification settings']],$li($off,'alerts'),'Off for all domains links its settings');same(0,storefrontDom($off)->query('//form[@method="POST"]')->length,'No toggle while alerts are off for all domains');
same(null,$li($page([],[],[],[],false),'alerts')[0],'No alerts row when the domain cannot be edited');
same([null,['Monitoring paused.']],[$li($page(['watch.paused']),'alerts')[0],$notice($page(['watch.paused']))],'Paused monitoring: one notice, no alerts row');
$ns=$page(['watch.ns_changed'],$row('ns_changed'),['ns_changed'],['urls'=>['support'=>'/tickets/create']]);
same(['example.com stopped using Beco nameservers on Jan 15, 2027. Change them back at your registrar, or contact support.'],$notice($ns),'Nameserver change: one notice with its fix');
same('/tickets/create',storefrontDom($ns)->query('//div[contains(@class,"domains-notice")]//a/@href')->item(0)?->value,'Contact support is the link');
same(1,substr_count($text($ns),'stopped using Beco nameservers'),'Nameserver change stated once');expect(str_contains($ns,'>Action required<'),'Header keeps the status pill');same(0,storefrontDom($ns)->query('//*[contains(@class,"domains-pill-reason")]')->length,'The notice gives the reason, not the pill');
same(['example.com stopped using Beco nameservers on Jan 15, 2027. Change them back at your registrar, or contact support.'],$notice($page(['watch.ns_changed'],[],['ns_changed'],['urls'=>[]])),'Portal fallback states the change once, without a link when support is unknown');
$expired=$page(['watch.expired'],$row('expired'),['registry_expiry_reached'],['expiry'=>'2026-10-01','daysLeft'=>-22]);same(['example.com expired on Oct 1, 2026. Renew it at your registrar.'],$notice($expired),'Expired: one notice');same(1,substr_count($text($expired),'at your registrar'),'Expired: the fix is said once');
$soon=$page([],$row('expiring'),[],['expiry'=>'2026-11-01','daysLeft'=>9]);
same([['Renew example.com at your registrar before Nov 1, 2026.'],'Nov 1, 2026 In 9 days'],[$notice($soon),$card($soon,'Expires')],'Expiring: one notice and the days left');
$blind=$page([],[],['expiry_unavailable','dns_unavailable'],['expiry'=>null,'nameservers'=>null,'freshness'=>['delegation'=>['checkedAt'=>1790000000,'stale'=>true]]]);
same([['We can’t read the public registry or DNS for example.com right now. We’ll keep checking.'],'Missing Shows after the next check',['Nameservers','Missing Shows after the next check',[]]],[$notice($blind),$card($blind,'Expires'),$li($blind,'nameservers')],'Can’t read: the notice says why, each Missing says when');
same('Missing Check at your registrar',$card($page([],[],[],['expiry'=>null]),'Expires'),'Unknown expiry carries its cause');
same(['Nameservers','ns1.example.net ns2.example.net Checking again',[]],$li($page(['watch.ns_pending'],[],[],['nameservers'=>['nameservers'=>['ns1.example.net','ns2.example.net'],'at'=>'2027-01-10T08:00:00Z']]),'nameservers'),'Unconfirmed change says it is checking again');
$moves=$page([],[...$row('ns_changed'),...$row('ns_restored','January 20, 2027')]);
same(['Nameservers changed Jan 15, 2027','Nameservers back to Beco Jan 20, 2027'],array_map($norm,iterator_to_array(storefrontDom($moves)->query('//section[@id="history"]//tbody/tr'))),'History lists nameserver changes with dates');
same(null,storefrontDom($page([],[],[],['urls'=>[]]))->query('//aside//a[contains(@class,"domains-btn")]')->item(0),'No transfer offer, no button');
$owned=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain($data['name'],'Active','ok','2027-01-15','On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],[],true);
same(1,substr_count($text(view('domains::show',['domain'=>$owned,'summary'=>$state(['domain.active','watch.ns_changed'],'managed'),'keys'=>[]])->render()),'stopped using Beco nameservers'),'Domain detail page states a nameserver change once');
require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
foreach(['show','save']as$action){$route=app('router')->getRoutes()->getByName('domains.checks.'.$action);expect(in_array('web',$route->gatherMiddleware(),true)&&in_array('auth',$route->gatherMiddleware(),true),'Native web auth guards preferences');}
$nativeSuccessful=true;echo "Owner-safe preference detail, CSRF/session key, stale revision, escaping and query-free Blade passed.\n";
