<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline watch comparison and real native cart.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,RateLimiter};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{WatchTransferController,StorefrontController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{WatchTransferPolicy,WatchTransfer,WatchTransferStrings,DomainSummaries,Storefront,CheckoutEligibility,TransferPrecheck,Rdap,CartAdmission};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
config(['domains.watch_transfer.enabled'=>true]);$f=noticeFixture();extract($f);extract(commerceFixture(true,static fn()=>null));$clock=new FakeClock($now);$at=$now->getTimestamp();
DB::table('domains_watch_names')->where('id',$id)->update(['registration'=>'external']);noticeRegistry($f,$at,45);
$wire=(object)['status'=>['active'],'code'=>200,'during'=>null,'domainCalls'=>0,'failOn'=>null];
$rdap=new Rdap(static function($url)use($wire,$at){if($wire->during){$run=$wire->during;$wire->during=null;$run();}if(!str_contains($url,'dns.json')){$wire->domainCalls++;}return ['status'=>str_contains($url,'dns.json')?200:($wire->domainCalls===$wire->failOn?503:$wire->code),'body'=>json_encode(str_contains($url,'dns.json')?['services'=>[[['com'],['https://rdap.example.com/']]]]:['objectClassName'=>'domain','ldhName'=>'example.com','status'=>$wire->status,'events'=>[['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>gmdate('Y-m-d\TH:i:s\Z',$at+45*86400)]]])];});
$eligibility=new CheckoutEligibility($bindings,$clock,new TransferPrecheck($rdap));$store=new Storefront($sellers,$prices,$native,$bindings,$eligibility,$clock);
$selection=$sellers->selection('com','transfer',1,$now);App\Models\Server::find($selection->serverId)->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';storefrontViews();$session=app('request')->session();
$controller=new WatchTransferController(new WatchTransfer($store,new DomainSummaries),$eligibility,$clock);app()->instance(StorefrontController::class,new StorefrontController($store));
(new CartAdmission($native,$sellers,$eligibility,$clock))->boot();Auth::login($owner);
$req=static function(array $data=[],?App\Models\User $actor=null)use($session,$owner){$r=Request::create('/domains/watches/1/transfer','POST',$data);$r->setLaravelSession($session);$r->setUserResolver(fn()=>$actor??$owner);return $r;};
$post=static function(array $extra=[])use($req,$controller,$id){$r=$req();$key=FormKeys::issue($r,'watch-transfer:'.$id);return $controller->check($req(['_request_key'=>$key,'revision'=>'1',...$extra]),$id);};
config(['domains.watch_transfer.enabled'=>false]);$offReads=$reads->calls;same(false,$controller->show($req(),$id)->getData()['canCheck'],'Default off GET');same(null,(new DomainSummaries)->one($owner,'watch',$id,$at)->urls['compareTransfer'],'Default off summary link');same('unavailable',$post()->getData()['state'],'Default off POST');same($offReads,$reads->calls,'Off gate makes no reads');config(['domains.watch_transfer.enabled'=>true]);commerceReject(fn()=>$eligibility->comparison('example.com',fn()=>true,fn()=>null));
$before=$reads->calls;$help=$controller->show($req(),$id);same('help',$help->getData()['state'],'GET only helps');same($before,$reads->calls,'GET no provider calls');expect(!str_contains($help->render(),'Add transfer to cart'),'Unknown has no purchase action');
try{$controller->show($req(actor:$other),$id);expect(false,'Foreign watch rejected');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
foreach([13=>false,14=>true,90=>true,91=>false]as$days=>$allowed){same($allowed,WatchTransferPolicy::window(gmdate('Y-m-d',$at+$days*86400),strtotime(gmdate('Y-m-d',$at).' UTC')),'Window '.$days);}
same(false,WatchTransferPolicy::window('2027-02-30',$at),'Invalid date');
$verified=['verified'=>true,'name'=>'example.com','currency'=>'USD','tax_basis'=>'exclusive','term'=>1,'observed'=>$at,'expires'=>$at+100,'minor'=>'2000'];
same('$9.00',WatchTransferPolicy::saving($verified,'1100','example.com',$at),'Verified comparable saving');same(null,WatchTransferPolicy::saving(null,'1100','example.com',$at),'No source no savings');
foreach(['currency'=>'EUR','tax_basis'=>'inclusive','term'=>2,'verified'=>false,'name'=>'foreign.com','expires'=>$at,'observed'=>$at+1,'minor'=>'unknown']as$key=>$value){same(null,WatchTransferPolicy::saving([...$verified,$key=>$value],'1100','example.com',$at),'Reject incomparable '.$key);}
$view=$post();same('eligible',$view->getData()['state'],'Strict evidence offers transfer');$html=$view->render();expect(str_contains($html,WatchTransferStrings::all()['watch.transfer_owner']),'Explicit authority');expect(!str_contains($html,'less than your verified'),'No invented savings');
foreach(['product_id','account_fingerprint','name="epp"','name="domain"']as$secret){expect(!str_contains($html,$secret),'No client authority '.$secret);}
$before=$reads->calls;$missing=$post(['step'=>'confirm']);expect($missing instanceof Illuminate\Contracts\View\View,'Authority missing cannot reach cart');same('eligible',$missing->getData()['state'],'Authority required');expect(str_contains($missing->render(),'id="authority-error"'),'Missing authority explained');same($before,$reads->calls,'Missing authority no reads');
RateLimiter::clear('domains.watch-transfer:'.$owner->id);
foreach([['redemptionPeriod'],['pendingDelete'],['clientTransferProhibited'],['futureUnknownStatus']]as$status){$wire->status=$status;same('unavailable',$post()->getData()['state'],'Status fails closed');}$wire->status=['active'];
$wire->code=503;same('unavailable',$post()->getData()['state'],'Unknown RDAP no purchase');$wire->code=200;$reads->premium=true;same('unavailable',$post()->getData()['state'],'Premium refused');$reads->premium=false;
RateLimiter::clear('domains.watch-transfer:'.$owner->id);$post();$wire->during=fn()=>DB::table('domains_watch_names')->where('id',$id)->update(['revision'=>2]);
same('changed',$post(['step'=>'confirm','authority'=>'1'])->getData()['state'],'Revision race during provider read');DB::table('domains_watch_names')->where('id',$id)->update(['revision'=>1]);
RateLimiter::clear('domains.watch-transfer:'.$owner->id);$post();$wire->failOn=$wire->domainCalls+2;$refused=$post(['step'=>'confirm','authority'=>'1']);same(0,App\Models\CartItem::count(),'Unknown final cart read cannot add a purchase');same('changed',$refused->getData()['state'],'Final read failure shows changed');$wire->failOn=null;
// A reservation appearing during the last unlocked native-cart read still refuses this handoff.
RateLimiter::clear('domains.watch-transfer:'.$owner->id);$post();$reservation=null;
$wire->during=function()use($wire,&$reservation,$selection,$other){$wire->during=function()use(&$reservation,$selection,$other){$reservation=DB::table('domains_domains')->insertGetId(['service_id'=>999999,'user_id'=>$other->id,'server_id'=>$selection->serverId,'registrar_key'=>$selection->registrar,'account_fingerprint'=>$selection->fingerprint,'domain'=>'example.com','reservation_name'=>'example.com','sld'=>'example','tld'=>'com','status'=>'pending']);};};
$post(['step'=>'confirm','authority'=>'1']);expect($reservation!==null,'Final read reached reservation race');same(0,App\Models\CartItem::count(),'Concurrent reservation cannot bypass admission');DB::table('domains_domains')->where('id',$reservation)->delete();$wire->during=null;
$post();$response=$post(['step'=>'confirm','authority'=>'1','domain'=>'forged.net','action'=>'register','product_id'=>'999','epp'=>'private-input']);same(route('cart'),$response->getTargetUrl(),'Normal cart adapter');
$item=App\Models\CartItem::sole();same('example.com',$item->checkout_config['domain'],'Only canonical watch name');expect(!str_contains(json_encode($item->checkout_config),'private-input'),'Transfer code discarded');same('transfer',$native->mapping($item->product,$item->plan)['action'],'Transfer intent only');
app('request')->cookies->set('cart',$item->cart->ulid);RateLimiter::clear('domains.watch-transfer:'.$owner->id);$post();same('unavailable',$post(['step'=>'confirm','authority'=>'1'])->getData()['state'],'Duplicate native cart line is unavailable');same(1,App\Models\CartItem::count(),'Existing cart line retained');
$nativeSuccessful=true;echo "Watch comparison: pure gates, no invented savings, owner/revision races, strict reads and neutral native cart passed.\n";
