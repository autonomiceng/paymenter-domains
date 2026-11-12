<?php
// Native owner POSTs need real checkout, encrypted payloads, native sessions and Blade.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-actions.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
foreach([0,1]as$i){
    $op=$newTransfer('owner-code-'.$i.'-placeholder.com',$i,true,false);$d=$op->domain;$exact='  Synthetic:Case-Sensitive + % é雪  ';
    foreach(['',"bad\nvalue",str_repeat('x',513),['private']]as$bad){
        $session->forget('_old_input');
        $r=$req($d,'code',['transfer_code'=>$bad,'password'=>'PRIVATE-PASSWORD','auth_code'=>'PRIVATE-AUTH']);$action->code($r,$d->id);
        expect(!$r->request->has('transfer_code'),'Even malformed code is scrubbed');expect(!$session->has('_old_input.transfer_code'),'Code is never flashed');
        same([],$session->get('_old_input',[]),'Code failure flashes no submitted fields');
        expect(!isset($op->fresh()->payload['epp']),'Invalid code not stored');
    }
    $r=$req($d,'code',['transfer_code'=>$exact]);$stale=clone $r;
    $saved=$action->code($r,$d->id);same($exact,$op->fresh()->payload['epp'],'Controller preserves exact encrypted bytes');
    // A paid transfer with incomplete setup only saves the code; no registrar work has started.
    same([route('domains.domains.show',$d->id),OwnerActionController::CODE_SAVED],[$saved->getTargetUrl(),$session->get('domains_status')],'Saved code opens the domain page with what happens next');
    \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->update(['processing_enabled'=>false]);
    $session->forget(['domains_status','errors']);
    $saved=$action->code($req($d,'code',['transfer_code'=>$exact]),$d->id);
    same(route('domains.domains.show',$d->id),$saved->getTargetUrl(),'Orders off returns to the domain page after saving');
    expect(!$session->has('errors'),'Orders off saves without errors');
    same(OwnerActionController::CODE_SAVED,$session->get('domains_status'),'Orders off confirms the save without claiming transfer progress');
    \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->update(['processing_enabled'=>true]);
    expect(!str_contains(DB::table('domains_operations')->find($op->id)->payload,$exact),'No plaintext at rest');
    expect(!str_contains(json_encode($session->all()),$exact),'No code in session');$replay(fn()=>$action->code($stale,$d->id));
    $r=$req($d,'code',['transfer_code'=>'private-query']);$r->query->set('transfer_code','private-query');$action->code($r,$d->id);
    expect(!$r->query->has('transfer_code'),'Query secret scrubbed');same($exact,$op->fresh()->payload['epp'],'Query code refused');
    $r=$req($d,'code',['transfer_code'=>'foreign'],$other);
    try{$action->code($r,$d->id);expect(false,'Foreign POST refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
    expect(!$r->request->has('transfer_code'),'Foreign secret scrubbed before ownership refusal');
    // The existing native fixture covers the controller, frozen operation and real Blade notice together.
    $before=$op->fresh();$session->forget(['domains_status','errors']);
    $r=$req($d,'setup',['nameservers'=>$i?"ns1.example.com\nns2.example.com":'','privacy'=>'1']);
    $response=$action->setup($r,$d->id);
    same(url('/domains/'.$d->id.'/setup'),$response->getTargetUrl(),'Hidden privacy returns to setup');
    expect($session->get('errors')?->has('owner_action')===true,'Hidden privacy has a general visible refusal');
    expect(!$session->has('domains_status'),'Refused setup never reports success');
    expect(!isset($op->fresh()->policy['owner_setup']),'Refused hidden privacy leaves setup unfrozen');
    same($before->payload,$op->fresh()->payload,'Refusal preserves all stored choices');
    storefrontViews();app('view')->share('errors',$session->get('errors'));$page=$i?'setup':'show';
    $html=(new OwnedDomainController($owners))->$page($r,$d->id)->render();
    $notice=storefrontDom($html)->query('//*[@role="alert"]')->item(0)?->textContent??'';
    expect(str_contains($notice,'WHOIS privacy is not offered yet.')&&str_contains($notice,'save again without it'),'Real setup page explains refusal and how to save again');
    same(0,storefrontDom($html)->query('//input[@name="privacy"]')->length,'Refusal keeps the privacy field hidden');
    app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
    $requests=$d->operations()->count();
    $action->manage($req($d,'privacy_purchase'),$d->id,'privacy_purchase');
    same($notice,storefrontDom((new OwnedDomainController($owners))->$page($r,$d->id)->with('errors',$session->get('errors'))->render())->query('//*[@role="alert"]')->item(0)?->textContent??'','Management refusal gives the same visible explanation');
    same($requests,$d->operations()->count(),'Refused management purchase creates no request');
    $r=$req($d,'setup',['nameservers'=>$i?"ns1.example.com\nns2.example.com":'','confirmed'=>'1']);
    $action->setup($r,$d->id);$after=$op->fresh();expect(isset($after->policy['owner_setup']),'Setup without a privacy choice saves after refusal');
    same(false,$after->payload['privacy'],'Setup without privacy preserves the stored choice');
    same($exact,$after->payload['epp'],'Setup preserves code');same($before->policy['paid_at'],$after->policy['paid_at'],'Setup preserves paid clock');
    same($before->payload['contacts'],$after->payload['contacts'],'Setup preserves contacts');same($before->funding,$after->funding,'Setup grants no funding');
    commerceReject(fn()=>$after->update(['payload'=>[...$after->payload,'privacy'=>!$after->payload['privacy']]]));
    commerceReject(fn()=>$setup->save($user,$d->id,['nameservers'=>['ns3.example.com','ns4.example.com'],'privacy'=>false]));
    $r=$req($d,'code',['transfer_code'=>'replacement']);$action->code($r,$d->id);same('replacement',$op->fresh()->payload['epp'],'Setup freeze permits separate code correction');
    $clock->instant=$now->modify('+30 days');$dto=$owners->present($d->fresh());expect(!$dto->transfer['open'],'Exact UTC cutoff closes entry');
    $r=$req($d,'code',['transfer_code'=>'late']);$action->code($r,$d->id);same('replacement',$op->fresh()->payload['epp'],'POST enforces same exact cutoff');$clock->instant=$now;
}
// JSON reaches the same owned boundary with the same form key and secret scrubbing.
$jsonOp=$newTransfer(placeholderComFixtureName('owner-json-code-placeholder.com'),0,true,false);$exactJson='  + % é雪  ';
$base=$req($jsonOp->domain,'code');$data=['transfer_code'=>$exactJson,'_request_key'=>$base->input('_request_key')];
$r=Request::create('/domains/'.$jsonOp->domain_id.'/transfer-code','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($data,JSON_THROW_ON_ERROR));
$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
nativeNormalizeInput($r);
$action->code($r,$jsonOp->domain_id);same($exactJson,$jsonOp->fresh()->payload['epp'],'JSON code persists exact decoded bytes');
expect(!$r->json()->has('transfer_code')&&!$r->request->has('transfer_code'),'JSON and form input bags are scrubbed');
// Exercise the real global trimming middleware, including native whitespace conversion.
$r=Request::create('/','POST',['transfer_code'=>'  keep  ','other'=>'  trim  ']);
nativeNormalizeInput($r);
same('keep',$r->request->get('transfer_code'),'Unrelated core route transfer_code is trimmed again');
same('trim',$r->request->get('other'),'Other native input transformations unchanged');
foreach(['setup-save','contacts-save','code-save','manage']as$name){$route=Illuminate\Support\Facades\Route::getRoutes()->getByName('domains.domains.'.$name);
    expect(in_array('web',$route->gatherMiddleware(),true),'Native CSRF group retained');expect((bool)array_filter($route->gatherMiddleware(),fn($m)=>str_starts_with($m,'throttle:')),'POST throttled');}
$nativeSuccessful=true;echo "Owner setup/code native POST, exact cutoff, encrypted bytes, replay and trim regressions passed.\n";
