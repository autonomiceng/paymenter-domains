<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{WatchNoticeController,OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,HostingDomainRow,WatchNoticePreferences};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
$f=noticeFixture();extract($f);require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';storefrontViews();$session=app('request')->session();
$request=static function($actor,array $data=[],string $method='GET')use($session){$r=Request::create('/domains',$method,$data);$r->setUserResolver(fn()=>$actor);$r->setLaravelSession($session);return $r;};
$controller=new WatchNoticeController();$list=app(OwnedDomainController::class);
$html=$list->index($request($owner))->render();expect(strpos($html,'Find your domain name')<strpos($html,'Related to your hosting'),'Related section follows registrations');expect(str_contains($html,'example.com'),'Related name is visible');expect(!str_contains($list->index($request($other))->render(),'example.com'),'Foreign list is empty');
$queries=0;DB::listen(static function()use(&$queries){$queries++;});$view=$controller->show($request($owner),'watch',$id);$before=$queries;$html=$view->render();same($before,$queries,'Detail Blade queries no tables');expect(str_contains($html,'Connection details unavailable')&&!str_contains($html,'Not pointed to'),'Unknown connection never claims empty');
$beforeState=DB::table('domains_watch_work')->get()->toJson();$list->index($request($owner))->render();$controller->show($request($owner),'watch',$id)->render();same($beforeState,DB::table('domains_watch_work')->get()->toJson(),'GET never enrolls or queues public work');
app('view')->getFinder()->addLocation($fixture.'/themes/default/views');HostingDomainRow::boot();Auth::setUser($owner);$v=view('services.show',['service'=>$service,'fields'=>[]]);app('view')->callComposer($v);$fields=$v->getData()['fields'];same(1,count($fields),'One compact hosting Domain row');expect(str_contains((string)$fields[0]['text'],url('/domains/checks/watch/'.$id)),'Hosting row links owned watch detail');
Auth::setUser($other);$v=view('services.show',['service'=>$service,'fields'=>[]]);app('view')->callComposer($v);same([],$v->getData()['fields'],'Hosting composer refuses foreign owner');
DB::table('domains_watch_notice_state')->updateOrInsert(['scope'=>WatchNoticePreferences::scope($uid,'example.com')],['user_id'=>$uid,'data'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['target'=>['watch',$id],'portal'=>['dns'=>['dns_unavailable','ns_pending']]])),'updated_at'=>time()]);
foreach(['paused','retired'] as $state){DB::table('domains_watch_names')->where('id',$id)->update(['state'=>$state]);$html=$controller->show($request($owner),'watch',$id)->render();expect(!str_contains($html,'We will keep trying')&&!str_contains($html,'we are checking a possible'),'Read-only detail never implies checking continues');expect(str_contains($html,$state==='paused'?'Checks paused':'Watch ended'),'Retained read-only detail');same(0,storefrontDom($html)->query('//form[@method="POST"]')->length,'Ineligible watch has no preference POST');$payload=['account_revision'=>1,'target_revision'=>1,'email'=>0,'notices'=>0,'_request_key'=>FormKeys::issue($request($owner),'watch-preferences:watch:'.$id)];try{$controller->save($request($owner,$payload,'POST'),'watch',$id);expect(false,'Retired/paused preference refuses');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(404,$e->getStatusCode(),'Fresh POST eligibility');}}
same(true,WatchNoticePreferences::read($uid,'example.com')['account']['enabled'],'Refused POST preserves preferences');
$nativeSuccessful=true;echo "Portal order, owner-safe detail and hosting links, retained read-only states, local GET and query-free Blade passed.\n";
