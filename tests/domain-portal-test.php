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
$html=$list->index($request($owner))->render();$dom=storefrontDom($html);$row=$dom->query('//tbody/tr')->item(0);
same(1,$dom->query('//tbody/tr')->length,'Hosting domain joins the one Domains list');same(url('/domains/checks/watch/'.$id),$dom->query('.//a[contains(@class,"domains-row-link")]/@href',$row)->item(0)?->value,'Hosting row links its domain page');
same('At your registrar',trim($dom->query('td[@data-label="Renewal"]',$row)->item(0)?->textContent??''),'Hosting row renews at its registrar');expect(str_starts_with(trim($dom->query('td[1]/*[contains(@class,"domains-cell-sub")]',$row)->item(0)?->textContent??''),'Hosting: '),'Hosting row names its service');
expect(!str_contains($html,'Related to your hosting')&&!str_contains($html,'No domains yet'),'No separate hosting section and no empty state beside a hosting row');expect(str_contains($html,'example.com'),'Related name is visible');expect(!str_contains($list->index($request($other))->render(),'example.com'),'Foreign list is empty');
$queries=0;DB::listen(static function()use(&$queries){$queries++;});$view=$controller->show($request($owner),'watch',$id);$before=$queries;$html=$view->render();same($before,$queries,'Detail Blade queries no tables');expect(!str_contains($html,'Connection details unavailable')&&!str_contains($html,'Not pointed to'),'Unknown connection never claims empty and shows no unknown-only chip');
$dom=storefrontDom($html);$text=static fn(DOMNode $node)=>trim($node->textContent);
$nameservers='//li[@id="nameservers"]/span[@class="domains-row__v"]';
same(1,$dom->query('//li[@id="nameservers"]')->length,'One initial Nameservers row');
same(1,$dom->query($nameservers)->length,'One Nameservers value');
same(['Missing'],array_map($text,iterator_to_array($dom->query($nameservers.'/span[@class="domains-missing"]'))),'Initial Nameservers value is Missing');
same(['Shows after the first check'],array_map($text,iterator_to_array($dom->query($nameservers.'/span[@class="domains-sub"]'))),'Initial Nameservers names the pending first check');
$expires='//section[@id="renewal"]//dl[@class="domains-card"]/div[dt[starts-with(normalize-space(.),"Expires")]]';
same(1,$dom->query($expires)->length,'One initial Renewal Expires row');
same(1,$dom->query($expires.'/dd')->length,'One Expires value');
same(['Missing'],array_map($text,iterator_to_array($dom->query($expires.'/dd/span[@class="domains-missing"]'))),'Initial expiry value is Missing');
same(['Check at your registrar'],array_map($text,iterator_to_array($dom->query($expires.'/dd/span[@class="domains-sub"]'))),'Initial expiry names its registrar cause');
$beforeState=DB::table('domains_watch_work')->get()->toJson();$list->index($request($owner))->render();$controller->show($request($owner),'watch',$id)->render();same($beforeState,DB::table('domains_watch_work')->get()->toJson(),'GET never enrolls or queues public work');
app('view')->getFinder()->addLocation($fixture.'/themes/default/views');HostingDomainRow::boot();Auth::setUser($owner);$v=view('services.show',['service'=>$service,'fields'=>[]]);app('view')->callComposer($v);$fields=$v->getData()['fields'];same(1,count($fields),'One compact hosting Domain row');expect(str_contains((string)$fields[0]['text'],url('/domains/checks/watch/'.$id)),'Hosting row links owned watch detail');
Auth::setUser($other);$v=view('services.show',['service'=>$service,'fields'=>[]]);app('view')->callComposer($v);same([],$v->getData()['fields'],'Hosting composer refuses foreign owner');
DB::table('domains_watch_notice_state')->updateOrInsert(['scope'=>WatchNoticePreferences::scope($uid,'example.com')],['user_id'=>$uid,'data'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['target'=>['watch',$id],'portal'=>['dns'=>['dns_unavailable','ns_pending']]])),'updated_at'=>time()]);
foreach(['paused','retired'] as $state){DB::table('domains_watch_names')->where('id',$id)->update(['state'=>$state]);$html=$controller->show($request($owner),'watch',$id)->render();expect(!str_contains($html,'We will keep trying')&&!str_contains($html,'we are checking a possible'),'Read-only detail never implies checking continues');expect(str_contains($html,$state==='paused'?'Monitoring paused.':'Monitoring stopped.'),'Retained read-only detail');same(0,storefrontDom($html)->query('//form[@method="POST"]')->length,'Ineligible watch has no preference POST');$payload=['account_revision'=>1,'target_revision'=>1,'email'=>0,'notices'=>0,'_request_key'=>FormKeys::issue($request($owner),'watch-preferences:watch:'.$id)];try{$controller->save($request($owner,$payload,'POST'),'watch',$id);expect(false,'Retired/paused preference refuses');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(404,$e->getStatusCode(),'Fresh POST eligibility');}}
same(true,WatchNoticePreferences::read($uid,'example.com')['account']['enabled'],'Refused POST preserves preferences');
// A seeded hosting domain keeps its Demo tag on the native hosting page. Rolled back so later renders see the real watch.
DB::beginTransaction();DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'active']);Auth::setUser($owner);
Paymenter\Extensions\Servers\Domains\Models\WatchRegistryObservation::create(['watch_id'=>$id,'user_id'=>$owner->id,'stamp'=>str_repeat('c',64),'bootstrap_revision'=>str_repeat('d',64),'started_at'=>time(),'completed_at'=>time(),'result'=>'unknown','reason'=>'no_expiry','safe_code'=>'expiry_unknown','facts'=>['offline_demo'=>true]]);
$v=view('services.show',['service'=>$service,'fields'=>[]]);app('view')->callComposer($v);$row=storefrontDom((string)$v->getData()['fields'][0]['text']);
same('Demo',trim($row->query('//a/following-sibling::span[1]/text()')->item(0)?->textContent??''),'Hosting row tags a seeded domain after its name');
same('Demo',trim(storefrontDom($controller->show($request($owner),'watch',$id)->render())->query('//h1/span[contains(@class,"domains-demo-tag")]/text()')->item(0)?->textContent??''),'Domain details tag a seeded hosting domain');DB::rollBack();
$nativeSuccessful=true;echo "Portal order, owner-safe detail and hosting links, retained read-only states, local GET and query-free Blade passed.\n";
