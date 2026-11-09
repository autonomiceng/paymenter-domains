<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native owner boundary with synthetic registrar transports.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/owner-records.php';
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
[$op]=$prepare(placeholderComFixtureName('local-progress-placeholder.com'));$d=$op->domain()->firstOrFail();$other=commerceUser();
$controller=new OwnedDomainController($owners);
$request=Request::create('/domains/'.$d->id.'/order-status','GET');$request->setUserResolver(fn()=>$user);
$calls=count($state->calls);$before=[$d->getRawOriginal(),$op->getRawOriginal()];
$response=$controller->orderStatus($request,$d->id);$progress=$response->getData(true);
same(['pending','state'],array_keys($progress),'Polling returns only local progress, not payload or customer data');
same(true,$progress['pending'],'The owner can poll an unfinished registration');
expect(is_string($progress['state'])&&preg_match('/^[a-f0-9]{64}$/D',$progress['state'])===1,'Progress is an opaque local state token');
expect(str_contains($response->headers->get('Cache-Control'),'no-store')&&str_contains($response->headers->get('Cache-Control'),'private'),'Progress cannot be cached between owners');
same($progress,$controller->orderStatus($request,$d->id)->getData(true),'Unchanged local status has a stable token');
storefrontViews();app('view')->getFinder()->flush();$request->setLaravelSession($session);$html=$controller->show($request,$d->id)->render();
expect(preg_match('/const state = "([a-f0-9]{64})";/',$html,$embedded)===1,'Real pending page embeds a local progress token');
same($progress['state'],$embedded[1],'Page presentation and local poll return the same token without a reload loop');

same($before,[$d->fresh()->getRawOriginal(),$op->fresh()->getRawOriginal()],'Polling never mutates the order or holding');
$foreign=clone $request;$foreign->setUserResolver(fn()=>$other);
try{$controller->orderStatus($foreign,$d->id);throw new LogicException('A foreign owner could poll this holding');}catch(ModelNotFoundException){}
// A changed native owner revokes the old ledger owner's access immediately.
DB::table('services')->where('id',$d->service_id)->update(['user_id'=>$other->id]);
try{$controller->orderStatus($request,$d->id);throw new LogicException('A stale ledger owner could poll this service');}catch(ModelNotFoundException){}
DB::table('services')->where('id',$d->service_id)->update(['user_id'=>$user->id]);
// Retained local closure changes the token and ends polling without asking the registrar.
DB::table('domains_domains')->where('id',$d->id)->update(['status'=>'registration_rejected']);
$closed=$controller->orderStatus($request,$d->id)->getData(true);
same(false,$closed['pending'],'A terminal registration stops customer polling');expect($closed['state']!==$progress['state'],'A local status change reloads the open page');
// Open pages read status on their own limit, so status reads never use up the limit for owner forms.
require __DIR__.'/Support/web-visit.php';app()->instance(Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::class,$owners);
$help=webVisit($user,'/domains/help');same(200,$help->getStatusCode(),'The owner opens a page under the owner form limit');
$left=(int)$help->headers->get('X-RateLimit-Remaining');
for($i=0;$i<5;$i++){$read=webVisit($user,'/domains/'.$d->id.'/order-status');same(200,$read->getStatusCode(),'An open page reads its order status');}
same(['pending','state'],array_keys(json_decode($read->getContent(),true)),'The web read returns only local progress');
same('120',$read->headers->get('X-RateLimit-Limit'),'Status reads count against their own limit');
same($left-1,(int)webVisit($user,'/domains/help')->headers->get('X-RateLimit-Remaining'),'Status reads leave the owner form limit untouched');
foreach(['domains.domains.order-status','domains.domains.management-status'] as $name){
    $middleware=Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware();
    expect(in_array('auth',$middleware,true)&&in_array(App\Http\Middleware\MustVerfiyEmail::class,$middleware,true)&&!in_array('throttle:60,1,domains.owner',$middleware,true),'Status read '.$name.' keeps sign-in and verification on its own limit');
}
same($calls,count($state->calls),'Every progress read and refusal performs no provider I/O');
$nativeSuccessful=true;echo "Native local order progress preserves ownership and never calls the registrar.\n";
