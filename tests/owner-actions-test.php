<?php
// Synthetic native owner intent regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/raw-input.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
$setup=new OwnerSetup($clock,$ports); $management=new DomainManagement($bindings,$clock);
$action=new OwnerActionController($owners,$setup,$inputs,$management,$ports);
$session=app('request')->session();$other=commerceUser();
$req=static function($d,string $intent,array $data=[], $actor=null)use($user,$session):Request {
    $r=Request::create('/domains/'.$d->id,'POST',$data,[],[],['CONTENT_TYPE'=>'application/x-www-form-urlencoded'],http_build_query($data,'','&',PHP_QUERY_RFC1738));
    if($intent==='code'){nativeNormalizeInput($r);}$r->setUserResolver(fn()=>$actor??$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':'.$intent));return $r;
};
$replay=static function($call):void {try{$call();expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Replay needs a fresh intent');}};
foreach([0,1]as$i){
    $op=$newTransfer('owner-code-'.$i.'.com',$i,true,false);$d=$op->domain;$exact='  Synthetic:Case-Sensitive + % é雪  ';
    foreach(['',"bad\nvalue",str_repeat('x',513),['private']]as$bad){
        $r=$req($d,'code',['transfer_code'=>$bad]);$action->code($r,$d->id);
        expect(!$r->request->has('transfer_code'),'Even malformed code is scrubbed');expect(!$session->has('_old_input.transfer_code'),'Code is never flashed');
        expect(!isset($op->fresh()->payload['epp']),'Invalid code not stored');
    }
    $r=$req($d,'code',['transfer_code'=>$exact]);$stale=clone $r;
    $action->code($r,$d->id);same($exact,$op->fresh()->payload['epp'],'Controller preserves exact encrypted bytes');
    expect(!str_contains(DB::table('domains_operations')->find($op->id)->payload,$exact),'No plaintext at rest');
    expect(!str_contains(json_encode($session->all()),$exact),'No code in session');$replay(fn()=>$action->code($stale,$d->id));
    $r=$req($d,'code',['transfer_code'=>'private-query']);$r->query->set('transfer_code','private-query');$action->code($r,$d->id);
    expect(!$r->query->has('transfer_code'),'Query secret scrubbed');same($exact,$op->fresh()->payload['epp'],'Query code refused');
    $r=$req($d,'code',['transfer_code'=>'foreign'],$other);
    try{$action->code($r,$d->id);expect(false,'Foreign POST refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
    expect(!$r->request->has('transfer_code'),'Foreign secret scrubbed before ownership refusal');
    $before=$op->fresh();$r=$req($d,'setup',['nameservers'=>$i?"ns1.example.com\nns2.example.com":'','privacy'=>$i?'1':'0','confirmed'=>'1']);
    $action->setup($r,$d->id);$after=$op->fresh();expect(isset($after->policy['owner_setup']),'Explicit setup captured');
    same($exact,$after->payload['epp'],'Setup preserves code');same($before->policy['paid_at'],$after->policy['paid_at'],'Setup preserves paid clock');
    same($before->payload['contacts'],$after->payload['contacts'],'Setup preserves contacts');same($before->funding,$after->funding,'Setup grants no funding');
    commerceReject(fn()=>$after->update(['payload'=>[...$after->payload,'privacy'=>!$after->payload['privacy']]]));
    commerceReject(fn()=>$setup->save($user,$d->id,['nameservers'=>['ns3.example.com','ns4.example.com'],'privacy'=>false]));
    $r=$req($d,'code',['transfer_code'=>'replacement']);$action->code($r,$d->id);same('replacement',$op->fresh()->payload['epp'],'Setup freeze permits separate code correction');
    $clock->instant=$now->modify('+30 days');$dto=$owners->present($d->fresh());expect(!$dto->transfer['open'],'Exact UTC cutoff closes entry');
    $r=$req($d,'code',['transfer_code'=>'late']);$action->code($r,$d->id);same('replacement',$op->fresh()->payload['epp'],'POST enforces same exact cutoff');$clock->instant=$now;
}
// JSON reaches the same owned boundary with the same form key and secret scrubbing.
$jsonOp=$newTransfer('owner-json-code.com',0,true,false);$exactJson='  + % é雪  ';
$base=$req($jsonOp->domain,'code');$data=['transfer_code'=>$exactJson,'_request_key'=>$base->input('_request_key')];
$r=Request::create('/domains/'.$jsonOp->domain_id.'/transfer-code','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($data,JSON_THROW_ON_ERROR));
$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
nativeNormalizeInput($r);
$action->code($r,$jsonOp->domain_id);same($exactJson,$jsonOp->fresh()->payload['epp'],'JSON code persists exact decoded bytes');
expect(!$r->json()->has('transfer_code')&&!$r->request->has('transfer_code'),'JSON and form input bags are scrubbed');
foreach([0,1]as$i){
    $newTransfer('owner-register-route-'.$i.'.com',$i,false,false); // choose the account through native seller policy
    $cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[$i],'register'),'owner-register-'.$i.'.com');commerceCheckout($user,$cart);
    $registration=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','owner-register-'.$i.'.com')->sole()->operations()->sole();
    if($i){
        foreach([[],['ns1.owner-register-1.com','ns2.example.com'],['xn--bad.example.com','ns2.example.com']]as$invalid){
            $r=$req($registration->domain,'setup',['nameservers'=>implode("\n",$invalid),'confirmed'=>'1']);$action->setup($r,$registration->domain_id);
            expect($session->get('errors')->has('nameservers'),'Purchase grammar maps to a fixed field error');
            expect(!isset($registration->fresh()->policy['owner_setup']),'Invalid settings never freeze');
        }
        $us=clone $registration->domain;$us->domain='synthetic.us';$us->tld='us';
        try{Paymenter\Extensions\Servers\Domains\Services\AcquisitionSetup::validate($us,$registration,['ns1.example.com','ns2.example.com'],true);expect(false,'Restricted privacy refused');}
        catch(Paymenter\Extensions\Servers\Domains\Services\SetupValidation $e){same(['privacy'],$e->fields,'Restricted privacy has a safe field error');}
        storefrontViews();app('view')->share('errors',$session->get('errors'));
        $html=(new OwnedDomainController($owners))->setup($r,$registration->domain_id)->render();
        same(1,storefrontDom($html)->query('//textarea[@name="nameservers"][@aria-invalid="true"]')->length,'Real Blade identifies setup error');
        app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
    }
    $setup->save($user,$registration->domain_id,['nameservers'=>$i?['ns1.example.com','ns2.example.com']:[],'privacy'=>$i===1]);
    expect(isset($registration->fresh()->policy['owner_setup']),'Registration desired settings captured before dispatch');
    same($i===1,$registration->fresh()->payload['privacy'],'Only declared free privacy captured without a purchase');
    same('awaiting_payment',$registration->fresh()->state,'Setup creates no financial or processing authority');
}
$incomplete=$newTransfer('owner-before-setup.com',1,true,true);$incomplete->update(['payload'=>[...$incomplete->payload,'nameservers'=>[]]]);$makeTransfer(1);
$priorCalls=count($state->calls);$engine->execute($incomplete->id);same([],$incomplete->fresh()->attempt??[],'Incomplete setup consumes no acquisition attempt');same($priorCalls,count($state->calls),'Incomplete setup performs no transport');
$setup->save($user,$incomplete->domain_id,['nameservers'=>['ns1.example.com','ns2.example.com'],'privacy'=>false]);
expect($claims->claim($incomplete->id)!==null,'Valid owner setup permits a virgin funded claim');
$op=$newTransfer('owner-unverified-paid.com',0,false,false);DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
expect(!$owners->present($op->domain)->transfer['open'],'Paid status alone cannot offer an unverified deadline');
$r=$req($op->domain,'setup',['confirmed'=>'1']);$action->setup($r,$op->domain_id);expect(!isset($op->fresh()->policy['owner_setup']),'Unfunded setup refused');
$cutoff=$newTransfer('owner-cutoff-setup.com',1,true,false);$clock->instant=$now->modify('+30 days');
expect(!isset($owners->present($cutoff->domain)->actions['setup']),'Virgin setup is absent at the exact paid cutoff');$clock->instant=$now;
// Exercise the real global trimming middleware, including native whitespace conversion.
$r=Request::create('/','POST',['transfer_code'=>'  keep  ','other'=>'  trim  ']);
nativeNormalizeInput($r);
same('keep',$r->request->get('transfer_code'),'Unrelated core route transfer_code is trimmed again');
same('trim',$r->request->get('other'),'Other native input transformations unchanged');
foreach(['setup-save','contacts-save','code-save','manage']as$name){$route=Illuminate\Support\Facades\Route::getRoutes()->getByName('domains.domains.'.$name);
    expect(in_array('web',$route->gatherMiddleware(),true),'Native CSRF group retained');expect((bool)array_filter($route->gatherMiddleware(),fn($m)=>str_starts_with($m,'throttle:')),'POST throttled');}
require __DIR__.'/Support/committed-code-pages.php';
$nativeSuccessful=true;echo "Owner setup/code native POST, exact cutoff, encrypted bytes, replay and trim regressions passed.\n";
