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
same(false,Pref::read($uid,'example.com')['account']['enabled'],'Owner POST changes preferences');
$payload['_request_key']=FormKeys::issue($request($owner),'watch-preferences:watch:'.$id);$payload['email']=1;$controller->save($request($owner,$payload,'POST'),'watch',$id);
same(false,Pref::read($uid,'example.com')['account']['enabled'],'Stale revision refuses overwrite');
same('We could not save these preferences. Reload and try again.',$session->get('errors')->first('preferences'),'Stale POST returns visible validation message');
try{$controller->save($request($owner,$payload,'POST'),'watch',$id);expect(false,'Consumed form key refuses');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Replay refuses');}
$data=$controller->show($request($owner),'watch',$id)->getData();$data['name']=str_repeat('a',63).'.example';$data['notices']=[['kind'=>'ns_changed','company'=>'<script>unsafe</script>','date'=>'January 15, 2027']];
$queries=0;DB::listen(static function()use(&$queries){$queries++;});$before=$queries;$long=view('domains::watch-notices',$data)->render();same($before,$queries,'Blade does not query database');
expect(str_contains($long,'&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($long,'<script>unsafe'),'Company escaped');expect(str_contains($long,$data['name']),'Long domain retained');
// Say each fact once: the summary card drops a notice that Latest notices already states, and keeps it where it is the only place.
$text=static fn(string $html)=>preg_replace('/\s+/',' ',strip_tags($html));
$moved=static fn(string $html)=>substr_count($text($html),'no longer uses the')+substr_count($text($html),'has not used the nameservers');
$provider='Contact your registration provider to check its status.';
$state=static function(array $states,string $kind='watch')use($data){$status=Paymenter\Extensions\Servers\Domains\Services\DomainStates::watch($states,'2027-01-15',true);
    return new Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(...[...get_object_vars($data['summary']),'kind'=>$kind,'stateKeys'=>$states,'noticeDates'=>['dns'=>1800000000],'statusState'=>$status[0]??null,'statusReason'=>$status[1]??null]);};
$page=static fn(array $states,array $notices,array $portal,bool $editable=true)=>view('domains::watch-notices',[...$data,'summary'=>$state($states),'notices'=>$notices,'portal'=>$portal,'editable'=>$editable])->render();
$row=static fn(string $kind)=>[['kind'=>$kind,'company'=>'Example Hosting','date'=>'January 15, 2027']];
same(1,$moved($page(['watch.ns_changed'],$row('ns_changed'),['ns_changed'])),'Checks page states a nameserver change once with an open notice');
same(1,$moved($page(['watch.ns_changed'],[],['ns_changed'])),'Checks page states a nameserver change once from the portal fallback');
same(1,$moved($page(['watch.ns_changed'],[],[],false)),'Summary keeps the nameserver change when Latest notices does not state it');
expect(str_contains($page(['watch.ns_changed'],$row('ns_changed'),['ns_changed']),'>Action required<'),'Summary keeps the status pill');
same(1,substr_count($text($page(['watch.expired'],$row('expired'),['registry_expiry_reached'])),$provider),'Checks page asks to contact the registration provider once with an open notice');
same(1,substr_count($text($page(['watch.expired'],[],['registry_expiry_reached'])),'with your registration provider'),'Portal fallback states the expiry once');
same(0,substr_count($text($page(['watch.expired'],[],['registry_expiry_reached'])),$provider),'Summary drops the provider sentence when the fallback states it');
same(1,substr_count($text($page(['watch.expired'],$row('expired'),[])),'Registry dates can lag behind a recent renewal.'),'Summary keeps the registry lag caveat');
same(1,substr_count($text($page(['watch.redemption'],[],[],false)),$provider),'Summary keeps the provider sentence when Latest notices does not state it');
$owned=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain($data['name'],'Active','ok','2027-01-15','On','$14.00',['show'=>'/domains/1'],[],['contacts'=>false,'required'=>false],[],[],true);
same(1,$moved(view('domains::show',['domain'=>$owned,'summary'=>$state(['domain.active','watch.ns_changed'],'managed'),'keys'=>[]])->render()),'Domain detail page states a nameserver change once');
require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
foreach(['show','save']as$action){$route=app('router')->getRoutes()->getByName('domains.checks.'.$action);expect(in_array('web',$route->gatherMiddleware(),true)&&in_array('auth',$route->gatherMiddleware(),true),'Native web auth guards preferences');}
$nativeSuccessful=true;echo "Owner-safe preference detail, CSRF/session key, stale revision, escaping and query-free Blade passed.\n";
