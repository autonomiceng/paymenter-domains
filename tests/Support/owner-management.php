<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real completed holdings and engine, synthetic transport.
declare(strict_types=1);
require __DIR__.'/owner-pages.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController, OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds,DomainManagement};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$free=isset($ms);[$d,$driver]=$holding($free?'owner-management':'owner-management.com');
storefrontViews();
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,new Refunds($clock)),$management,$ports);
$session=app('request')->session();$session->put('_token','owner-csrf');
$post=static function(string $action,array $data=[])use($d,$user,$session):Request {
    $r=Request::create('/domains/'.$d->id.'/manage/'.$action,'POST',['confirmed'=>'1',...$data]);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':'.$action));return $r;
};
$r=$post('nameservers',['nameservers'=>"ns1.example.com\nns2.example.com"]);$stale=$session->get('domains.form_keys');$again=clone $r;
$count=Operation::count();$controller->manage($r,$d->id,'nameservers');same($count+1,Operation::count(),'Owner POST creates one durable engine request');
$op=$d->operations()->where('action','nameservers')->sole();same('queued',$op->state,'No controller dispatch');same([],$free?$ms->calls:$state->calls,'No driver transport in owner POST');
$session->put('domains.form_keys',$stale);
try{$controller->manage($again,$d->id,'nameservers');expect(false,'Stale session replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Atomic replay guard');}
same($count+1,Operation::count(),'Replay creates no operation');$session->forget('domains.form_keys');
$dto=$owners->present($d->fresh());expect(in_array('Queued — not sent yet',array_column($dto->progress,'state'),true),'Queued operation appears without claiming dispatch');
$result=$management->run($op->id,$driver);same('succeeded',$result->state,'Existing engine grants one synthetic write with readback');
$beforeRead=count($free?$ms->calls:$state->calls);$dto=$owners->present($d->fresh());same($beforeRead,count($free?$ms->calls:$state->calls),'Presentation has no transport');expect(in_array('Confirmed',array_column($dto->progress,'state'),true),'Confirmed engine evidence shown');
$html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$dom=storefrontDom($html);
$forms=$dom->query('//form[@method="POST"]');expect($forms->length>=7,'Supported management forms offered');
same($forms->length,$dom->query('//form/input[@name="_token"]')->length,'Every form has native CSRF');
same($forms->length,$dom->query('//form/input[@name="_request_key"]')->length,'Every form has single-use intent');
same($forms->length,$dom->query('//form//input[@name="confirmed"]')->length,'Every management action has explicit confirmation');
foreach(['dns','ds','glue','auth_code_display']as$a){expect(!isset($dto->management[$a]),'No advanced/display forms in this slice');}
if($free){expect(!str_contains($html,'privacy_purchase'),'Free privacy offers no paid action');commerceReject(fn()=>$driver->validate('nameservers',['use_default'=>true,'nameservers'=>[]],$d->domain));}
else {
    commerceReject(fn()=>$driver->validate('nameservers',['nameservers'=>[]],$d->domain));
    expect(str_contains($html,'Use default nameservers'),'Explicit reset is distinct from custom nameservers');
    $r=$post('nameservers',['use_default'=>'1']);$controller->manage($r,$d->id,'nameservers');$reset=$d->operations()->where('action','nameservers')->orderByDesc('id')->firstOrFail();
    same(['nameservers'=>[],'use_default'=>true],$reset->payload,'Named reset intent is frozen through the engine');
    $state->ns=['dns1.name-services.com','dns2.name-services.com'];$state->apply=false;Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$d->account_fingerprint);
    same('succeeded',$management->run($reset->id,$driver)->state,'Explicit default reset requires provider default readback');
    $writes=array_values(array_filter($state->calls,fn($call)=>$call[0]==='ModifyNS'));same('default',end($writes)[1]['UseDNS'],'The grant sends explicit default DNS intent');$state->apply=true;
}
$before=Operation::count();$r=$post('lock',['locked'=>'0','confirmed'=>'0']);$controller->manage($r,$d->id,'lock');same($before,Operation::count(),'Missing confirmation creates no request');
$r=$post('contacts',['role'=>'all','contact'=>[]]);$controller->manage($r,$d->id,'contacts');
expect($session->get('errors')->has('contact.phone'),'Engine contact paths map to submitted fields');
app('view')->share('errors',$session->get('errors'));
$html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$dom=storefrontDom($html);
expect($dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]')->length>0,'Real Blade identifies the invalid contact field');
expect($dom->query('//input[@name="contact[phone]"][@aria-invalid="true"]/ancestor::details[@open]')->length>0,'Invalid contact forms open without script');
expect(str_contains($html,'Check this contact detail.'),'Fixed contact error copy is rendered without submitted values');
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
