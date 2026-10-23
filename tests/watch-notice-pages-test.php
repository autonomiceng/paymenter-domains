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
require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
foreach(['show','save']as$action){$route=app('router')->getRoutes()->getByName('domains.checks.'.$action);expect(in_array('web',$route->gatherMiddleware(),true)&&in_array('auth',$route->gatherMiddleware(),true),'Native web auth guards preferences');}
$nativeSuccessful=true;echo "Owner-safe preference detail, CSRF/session key, stale revision, escaping and query-free Blade passed.\n";
