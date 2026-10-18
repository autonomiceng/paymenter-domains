<?php
// Enom 444f31c TransferOrders precheck scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility, Rdap, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock=new FakeClock($now); $calls=0; $status=200;
$rdap=new Rdap(static function($url)use(&$calls,&$status){$calls++;same(0,Illuminate\Support\Facades\DB::transactionLevel(),'RDAP outside locks');
    return ['status'=>str_contains($url,'dns.json')?200:$status,'body'=>json_encode(str_contains($url,'dns.json')
        ?['services'=>[[['com'],['https://rdap.example.com/']]]]
        :['objectClassName'=>'domain','ldhName'=>'x.com','status'=>['active'],'events'=>[
            ['eventAction'=>'registration','eventDate'=>'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];});
$eligibility=new CheckoutEligibility($bindings,$clock,new TransferPrecheck($rdap));
$s=$sellers->selection('com','transfer',1,$now);$server=App\Models\Server::find($s->serverId);
commerceReject(fn()=>$eligibility->observe('x.com',$s,[1]));same(0,$calls,'No lookup without explicit allowlist');
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$token=$eligibility->observe('x.com',$s,[1]);same($s->serverId,$eligibility->require($token,'x.com',[1])->serverId,'Accepted transfer receipt');
same(2,$calls,'One bootstrap plus one domain read');
foreach([['domain.net',$s,[1]],['x.com',$s,[2]]]as[$name,$selection,$identity]){commerceReject(fn()=>$eligibility->require($token,$name,$identity));}
$status=404;commerceReject(fn()=>$eligibility->observe('x.com',$s,[1]));
$status=503;$unknown=$eligibility->observe('x.com',$s,[1]);expect(is_string($unknown),'Unknown RDAP stays advisory with registrar proof');$status=200;
foreach([null,true]as$premium){$reads->premium=$premium;commerceReject(fn()=>$eligibility->observe('x.com',$s,[1]));}$reads->premium=false;
$reads->available=false;commerceReject(fn()=>$eligibility->observe('x.com',$s,[1]));$reads->available=true;
$setting=$server->settings()->create(['key'=>'domains_transfer_creation_lock_days','value'=>'366']);
commerceReject(fn()=>$eligibility->observe('x.com',$s,[1]));$setting->update(['value'=>'30']);
commerceReject(fn()=>$eligibility->require($token,'x.com',[1]));$setting->update(['value'=>'60']);
$server->settings()->where('key','domains_transfer_endings')->update(['value'=>'net']);commerceReject(fn()=>$eligibility->require($token,'x.com',[1]));
$server->settings()->where('key','domains_transfer_endings')->update(['value'=>'com']);
foreach([null,[['name'=>'unaccepted']]]as$schema){$tlds[0]->update(['registry_fields'=>$schema]);commerceReject(fn()=>$eligibility->observe('x.com',$s,[1]));}$tlds[0]->update(['registry_fields'=>[]]);
foreach(['x.net','sub.x.com','xn--abc.com']as$name){commerceReject(fn()=>$eligibility->observe($name,$s,[1]));}
$ns=App\Models\Server::find($tlds[1]->server_id);$ns->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
$sellers->change('com',2,$ns->id,true,true,$now);$nsSelection=$sellers->selection('com','transfer',1,$now);
$wire=new class implements Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\DeadlineTransport {
    public function send($environment,$context,$request,#[SensitiveParameter] string $apiKey): Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse {throw new LogicException('Deadline required');}
    public function sendWithin($environment,$context,$request,#[SensitiveParameter] string $apiKey,int $timeoutMs): Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse {
        return new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\TransportResponse(200,json_encode(['request'=>['operation'=>$request->command],'reply'=>['code'=>300,'detail'=>'success','available'=>[['domain'=>'x.com','price'=>'12.34','premium'=>0]]]]));
    }
};
$nsBinding=new AccountBinding('namesilo',$ns->id,Environment::Test,'account-namesilo');
$nsDriver=new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client($nsBinding,'synthetic',$wire));
$nsContext=new RequestContext($nsBinding,RequestOrigin::Interactive,$now->modify('+25 seconds'),new RequestBudget(3),$clock);
same(false,$nsDriver->availability(new Query('x.com',Action::Transfer,1),$nsContext)->evidence()->premium,'Synthetic NameSilo reply carries premium=0');
commerceReject(fn()=>(new TransferPrecheck($rdap))->check('x.com',$nsSelection,$nsDriver,$nsContext));
commerceReject(fn()=>$eligibility->observe('x.com',$nsSelection,[1]));
$budget=new RequestBudget(1);$context=new RequestContext(new AccountBinding('enom',1,Environment::Test,'synthetic'),RequestOrigin::Interactive,$now->modify('+25 seconds'),$budget,$clock);
$bounded=0;$fresh=new Rdap(static function()use(&$bounded){$bounded++;return ['status'=>200,'body'=>'{"services":[[["com"],["https://rdap.example.com/"]]]}'];});
$r=$fresh->precheck('x.com',60,60,$now,$context);same(1,$bounded,'Neutral budget prevents second wire call');expect($r['warning']!==null,'Exhausted lookup stays unknown');
$clock->instant=$now; $timeouts=[];
$context=new RequestContext($context->binding,RequestOrigin::Interactive,$now->modify('+250 milliseconds'),new RequestBudget(2),$clock);
$fresh=new Rdap(static function($url,$timeout)use(&$timeouts,$clock){$timeouts[]=$timeout;$clock->instant=$clock->instant->modify('+1 second');return ['status'=>200,'body'=>'{"services":[[["com"],["https://rdap.example.com/"]]]}'];});
$r=$fresh->precheck('x.com',60,60,$now,$context);same([250],$timeouts,'Remaining deadline caps transport and prevents second lookup');expect($r['warning']!==null,'Expired lookup remains unknown');
echo "Transfer precheck, allowlist, neutral eligibility, registry and budget refusals passed.\n";$nativeSuccessful=true;
