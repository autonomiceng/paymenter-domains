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
