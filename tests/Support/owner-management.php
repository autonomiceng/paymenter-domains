<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real completed holdings and engine, synthetic transport.
declare(strict_types=1);
require __DIR__.'/owner-pages.php';
require_once __DIR__.'/raw-input.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController, OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds,DomainManagement};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$free=isset($ms);[$d,$driver]=$holding($free?'owner-management':'owner-management-placeholder.com');
storefrontViews();
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);
// Future-only stops preserve settings management and accurately show renewal Off.
\App\Models\ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Stop future domain renewal']);
$d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'renewals_stopped'=>true]]);
$stoppedDto=$owners->present($d->fresh());same('Off',$stoppedDto->renewal,'Customer sees owned/native future stop');expect(isset($stoppedDto->management['nameservers']),'Settings management remains available under future-only stop');
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$session=app('request')->session();$session->put('_token','owner-csrf');
$post=static function(string $action,array $data=[])use($d,$user,$session):Request {
    $data=['confirmed'=>'1',...$data];
    $r=Request::create('/domains/'.$d->id.'/manage/'.$action,'POST',$data,[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],http_build_query($data));
    nativeNormalizeInput($r);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':'.$action));return $r;
};
foreach ([
    ['ns1.example.com.','Enter at least 2 nameservers, one per line.'],
    [implode("\n",array_map(fn($i)=>'ns'.$i.'.example.com',range(1,$free?14:13))),'Enter no more than '.($free?13:12).' nameservers.'],
    ["bad_host.example.com\nns2.example.com","bad_host.example.com isn't a valid hostname. Use a name like ns1.example.com."],
    ["NS1.EXAMPLE.COM.\nns1.example.com",'ns1.example.com is listed more than once.'],
    [null,'Enter at least 2 nameservers, one per line.'],
] as [$text,$message]) {
    $before=Operation::count();$controller->manage($post('nameservers',['nameservers'=>$text]),$d->id,'nameservers');
    same($message,$session->get('errors')->first('nameservers'),'Management nameserver errors explain the cause');
    same($before,Operation::count(),'Invalid nameservers create no operation');
}
if ($free) {
    $controller->manage($post('nameservers',['nameservers'=>"ns1.12-34\nns2.example.com"]),$d->id,'nameservers');
    same("ns1.12-34 isn't a valid hostname. Use a name like ns1.example.com.",$session->get('errors')->first('nameservers'),'NameSilo management requires a letter in the final label');
}
$session->forget(['errors','_old_input','domains_failed_form']);
$r=$post('nameservers',['nameservers'=>"NS1.EXAMPLE.COM.\nns2.example.com."]);$stale=$session->get('domains.form_keys');$again=clone $r;
$count=Operation::count();$controller->manage($r,$d->id,'nameservers');same($count+1,Operation::count(),'Owner POST creates one durable engine request');
$op=$d->operations()->where('action','nameservers')->sole();same(['ns1.example.com','ns2.example.com'],$op->payload['nameservers'],'Management strips root dots before freezing input');same('queued',$op->state,'No controller dispatch');same([],$free?$ms->calls:$state->calls,'No driver transport in owner POST');
$session->put('domains.form_keys',$stale);
try{$controller->manage($again,$d->id,'nameservers');expect(false,'Stale session replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Atomic replay guard');}
same($count+1,Operation::count(),'Replay creates no operation');$session->forget('domains.form_keys');
$dto=$owners->present($d->fresh());expect(in_array('In progress',array_column($dto->progress,'state'),true)&&!in_array('Completed',array_column($dto->progress,'state'),true),'Queued operation appears without claiming dispatch');
$result=$management->run($op->id,$driver);same('succeeded',$result->state,'Existing engine grants one synthetic write with readback');
$beforeRead=count($free?$ms->calls:$state->calls);$dto=$owners->present($d->fresh());same($beforeRead,count($free?$ms->calls:$state->calls),'Presentation has no transport');expect(in_array('Completed',array_column($dto->progress,'state'),true),'Confirmed engine evidence shown');
$registrant=$owners->operation($d)->payload['contacts']['registrant']??null;expect(is_array($registrant)&&filled($registrant['email_address']??null),'The registration stores its registrant');
same(['name'=>trim($registrant['first_name'].' '.$registrant['last_name']),'email'=>$registrant['email_address']],$dto->owner,'Owner row reads the stored registrant');
$changed=Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'authorized_by'=>(int)$user->id,
    'operation_key'=>hash('sha256','owner-contact-change'),'action'=>'contacts','term'=>1,'currency'=>'USD','amount'=>'0.00','funding_basis'=>'non_billable_management','state'=>'succeeded','payload'=>['role'=>'registrant','contact'=>[...$registrant,'first_name'=>'Jane','last_name'=>'Doe','email_address'=>'jane@example.com']],'policy'=>[]]);
same(['name'=>'Jane Doe','email'=>'jane@example.com'],$owners->present($d->fresh())->owner,'A confirmed contact change replaces the stored registrant');
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$changed->id)->delete();
$html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$dom=storefrontDom($html);
$forms=$dom->query('//form[@method="POST"]');expect($forms->length>=7,'Supported management forms offered');
same($forms->length,$dom->query('//form/input[@name="_token"]')->length,'Every form has native CSRF');
same($forms->length,$dom->query('//form/input[@name="_request_key"]')->length,'Every form has single-use intent');
same(0,$dom->query('//input[@type="checkbox"]')->length,'Owner management forms have no checkboxes');
same(1,$dom->query('//li[@id="contacts"]/span[contains(@class,"domains-row__v")][contains(.,"'.$registrant['email_address'].'")]')->length,'Owner row shows the registrant email');
if(isset($dto->management['auth_code_email'])){same($free?'Emailed to the admin contact':'Emailed to the owner',trim(preg_replace('/\s+/',' ',$dom->query('//li[@id="auth_code_email"]/span[contains(@class,"domains-row__v")]')->item(0)->textContent)),'Auth code row names who receives the code');}
same(0,$dom->query('//input[@name="confirmed"]')->length,'No form carries a ticked or hidden confirmation');
foreach(['nameservers','lock','auth_code_email']as$risky){if(isset($dto->management[$risky])&&!($risky==='lock'&&($dto->management['lock']['state']??null)===false)){same(1,$dom->query('//li[@id="'.$risky.'"]//form[@data-confirm]//dialog[@data-confirm-dialog][not(@open)]//button[@name="confirmed"]')->length,'Risky action has a closed confirm dialog: '.$risky);}}
foreach(['contacts','privacy']as$plain){if(isset($dto->management[$plain])){same(1,$dom->query('//li[@id="'.$plain.'"]')->length,'Plain action row rendered: '.$plain);same(0,$dom->query('//li[@id="'.$plain.'"]//dialog[@data-confirm-dialog]|//li[@id="'.$plain.'"]//form[@data-confirm]')->length,'Plain action has no confirm step: '.$plain);}}
foreach($free?['dns','srv','ds','glue','auth_code_display']:['dns','srv','ds','auth_code_display']as$a){expect(!isset($dto->management[$a]),'Unsupported or disabled advanced/display forms are absent');}
if($free){expect(!str_contains($html,'privacy_purchase'),'Free privacy offers no paid action');commerceReject(fn()=>$driver->validate('nameservers',['use_default'=>true,'nameservers'=>[]],$d->domain));}
else {
    commerceReject(fn()=>$driver->validate('nameservers',['nameservers'=>[]],$d->domain));
    expect(str_contains($html,'Use the default nameservers'),'Explicit reset is distinct from custom nameservers');
    $r=$post('nameservers',['use_default'=>'1']);$controller->manage($r,$d->id,'nameservers');$reset=$d->operations()->where('action','nameservers')->orderByDesc('id')->firstOrFail();
    same(['nameservers'=>[],'use_default'=>true],$reset->payload,'Named reset intent is frozen through the engine');
    $state->ns=['dns1.name-services.com','dns2.name-services.com'];$state->apply=false;Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$d->account_fingerprint);
    same('succeeded',$management->run($reset->id,$driver)->state,'Explicit default reset requires provider default readback');
    $writes=array_values(array_filter($state->calls,fn($call)=>$call[0]==='ModifyNS'));same('default',end($writes)[1]['UseDNS'],'The grant sends explicit default DNS intent');$state->apply=true;
}
require __DIR__.'/owner-input.php';
// Risky actions are refused until the confirm step sends its field; without script that step renders inline.
foreach([['lock',['locked'=>'0','confirmed'=>'0']],['lock',['locked'=>'0']],['nameservers',['nameservers'=>"ns1.example.com\nns2.example.com"]],['auth_code_email',[]]]as[$action,$data]){
    $before=Operation::count();$r=$post($action,$data);if(!array_key_exists('confirmed',$data)){$r->request->remove('confirmed');}
    $response=$controller->manage($r,$d->id,$action);same($before,Operation::count(),'Missing confirmation creates no request: '.$action);
    same(422,$response->getStatusCode(),'Missing confirmation re-renders the page: '.$action);$confirmDom=storefrontDom($response->getContent());
    same(1,$confirmDom->query('//li[@id="'.$action.'"]//form[@data-confirm]//dialog[@data-confirm-dialog][@open][not(ancestor::dialog[not(@open)])]//button[@name="confirmed"][@value="1"]')->length,'Inline confirm step without script: '.$action);
}
expect(str_contains($response->getContent(),'Email the auth code?'),'Confirm step names the concrete action');
// Plain edits need no acknowledgement: this contacts request reaches engine validation.
$r=$post('contacts',['role'=>'all','contact'=>[]]);$r->request->remove('confirmed');$controller->manage($r,$d->id,'contacts');
expect($session->get('errors')->has('contact.phone'),'Engine contact paths map to submitted fields');
app('view')->share('errors',$session->get('errors'));
$html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$dom=storefrontDom($html);
expect($dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]')->length>0,'Real Blade identifies the invalid contact field');
expect($dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]/ancestor::dialog[@open]')->length>0,'Invalid contact forms open without script');
expect(str_contains($html,'Enter a phone number with its country code, such as +1.2025550123.'),'Contact error explains the expected format');
$r=$post('auth_code_email');$controller->manage($r,$d->id,'auth_code_email');
$dto=$owners->present($d->fresh());expect(!isset($dto->management['auth_code_email']),'Email cooldown removes the unavailable action');
$peer=$d->operations()->where('action','auth_code_email')->sole();Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$peer->id)->update(['state'=>'uncertain']);
$dto=$owners->present($d->fresh());same([],$dto->management,'An unresolved peer removes management forms');expect($dto->changesUnavailable,'Peer block has a neutral notice');
$before=Operation::count();
Illuminate\Support\Facades\DB::table('services')->where('id',$d->service_id)->update(['user_id'=>commerceUser()->id]);$r=$post('lock',['locked'=>'0']);
try{$controller->manage($r,$d->id,'lock');expect(false,'Native ownership drift refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
same($before,Operation::count(),'Foreign native owner creates no request');
// Real native CSRF middleware refuses absent and mismatched tokens before a controller can run.
app()->instance('env','production');$csrf=new Illuminate\Foundation\Http\Middleware\ValidateCsrfToken(app(),app('encrypter'));
foreach([[],['_token'=>'wrong']]as$data){$r=Request::create('/domains/'.$d->id.'/manage/lock','POST',$data);$r->setLaravelSession($session);
    try{$csrf->handle($r,fn()=>throw new RuntimeException('CSRF bypass'));expect(false,'CSRF refused');}catch(Illuminate\Session\TokenMismatchException){}}
app()->instance('env','testing');
$nativeSuccessful=true;echo "Owner management native request/engine grant, replay, ownership, CSRF and real Blade checks passed.\n";
