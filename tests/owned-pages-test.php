<?php
// Enom 444f31c owner view parity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
$controller=new OwnedDomainController($owners); $session=app('request')->session();
$request=static function($actor)use($session){$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$actor);$r->setLaravelSession($session);return $r;};
$other=commerceUser(); $snapshots=[];
foreach ([0,1] as $i) {
    $op=$newTransfer(($i?'other':str_repeat('a',63)).'.com',$i,false,false);$d=$op->domain; storefrontViews();
    $dto=$owners->present($d);$snapshots[]=$dto;
    $unavailable=(new Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new Paymenter\Extensions\Servers\Domains\Services\OwnerPorts))->present($d);
    same([],$unavailable->management,'Unavailable capability port grants no management form');expect(!isset($unavailable->actions['setup']),'Unavailable capability port offers no setup form');
    same('Awaiting payment',$dto->status,'Native unpaid transfer is explicit');
    same(null,$dto->transfer['deadline'],'Unpaid order has no invented UTC cutoff');
    expect($dto->transfer['open'],'Unsent unpaid transfer accepts code');
    same([],$dto->management,'Pending transfers have focused setup, without management forms');
    foreach(['dns','ds','glue','auth_code_display'] as $unsupported){expect(!isset($dto->management[$unsupported]),'Advanced/display forms absent');}
    $serialized=strtolower(json_encode($dto));
    foreach(['fingerprint','server_id','provider_order','profile_id','wholesale','syntheticonly']as$secret){expect(!str_contains($serialized,$secret),'Safe owner DTO: '.$secret);}
    foreach(['show','setup','code']as$page){
        if ($page==='setup' && !$i) { try { $controller->setup($request($user),$d->id); expect(false,'No empty transfer setup step'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(404,$e->getStatusCode(),'No-op setup has no page'); } }
        else { $html=$controller->$page($request($user),$d->id)->render();expect(str_contains($html,$d->domain),'Native controller real Blade page '.$page); }
        foreach([$d->id,999999]as$id){try{$controller->$page($request($other),$id);expect(false,'Foreign and missing locator refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}}
    }
    $d->update(['status'=>'active']); $d->service->update(['status'=>'active']); $active=$owners->present($d->fresh());
    same([],$active->management,'An unresolved acquisition offers no management form'); expect($active->changesUnavailable,'Unavailable changes get a neutral notice');
    $d->update(['status'=>'transfer_pending']);
    $d->update(['observation'=>['corrections'=>[['section'=>'contacts','state'=>'readback_confirmed_awaiting_review','profile_id'=>'PRIVATE'],['section'=>'nameservers','state'=>'closed']]]]);
    $html=$controller->show($request($user),$d->id)->render();
    expect(str_contains($html,'Awaiting review')&&str_contains($html,'Unresolved'),'Correction readback never closes original');expect(!str_contains($html,'PRIVATE'),'No correction evidence leakage');
    $op->update(['state'=>'held','safe_code'=>'contact_details_required']);
    expect(str_contains($controller->setup($request($user),$d->id)->render(),'Use updated account details'),'Held contact recovery affordance');
    Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update(['safe_code'=>'registry_details_unsupported']));
    expect(!$owners->present($d->fresh())->transfer['open'],'Unsupported held input remains closed');expect(!isset($owners->present($d->fresh())->actions['setup']),'Unsupported hold offers no impossible setup');
    Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update(['state'=>'pending','safe_code'=>'transfer_pending']));
    $html=$controller->code($request($user),$d->id)->render();expect(str_contains($html,'Your current registrar may email the domain owner'),'Sent pending transfer is not called a hold');
    expect(!str_contains($html,'Your transfer is on hold.'),'Healthy pending transfer never receives support hold text');
    $op->update(['state'=>'uncertain']);$html=$controller->code($request($user),$d->id)->render();expect(str_contains($html,'You don’t need to do anything.'),'Unknown outcome has no replay affordance');

}
$index=$controller->index($request($user))->render();same(2,storefrontDom($index)->query('//tbody/tr')->length,'Only current native and ledger owner records');
$r=$request($user);$r->query->add(['q'=>'other','sort'=>'-name','status'=>'pending']);$page=$controller->index($r);
same(1,$page->getData()['domains']->total(),'Owner search and status filter retain the native/ledger predicate');same(2,$page->getData()['filterCounts'][''],'Counts cover only current owner');
$r->query->set('q','%');same(0,$controller->index($r)->getData()['domains']->total(),'Search wildcard is literal');expect(str_contains($controller->index($r)->render(),'Show all domains'),'Filtered empty state has an escape');
same(0,storefrontDom($controller->index($request($other))->render())->query('//tbody/tr')->length,'Cross-owner index empty');
$d=Domain::firstOrFail();Illuminate\Support\Facades\DB::table('services')->where('id',$d->service_id)->update(['user_id'=>$other->id]);
try{$owners->owned($user,$d->id);expect(false,'Native ownership drift refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
$store=new Paymenter\Extensions\Servers\Domains\Services\Storefront($sellers,$prices,$native,$bindings,
    new Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility($bindings,$clock,new Paymenter\Extensions\Servers\Domains\Services\TransferPrecheck($rdap)),$clock);
$entry=new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController($store);
$r=$request($user);$r->query->set('name',str_repeat('a',63).'.com');$before=count($state->calls);$data=$entry->show($r)->getData();
same(str_repeat('a',63).'.com',$data['results'][0]['domain'],'Dedicated transfer entry preserves the exact canonical name');
$html=$entry->show($r)->render();expect(str_contains($html,'Before you start')&&str_contains($html,'data-domains-results'),'Transfer entry uses approved source guidance and existing checked result flow');
same($before,count($state->calls),'Transfer entry GET has no provider I/O');expect(!str_contains($html,'name="action"'),'Unchecked transfer entry has no order form');
$r->query->set('name','sub.example.com');expect($entry->show($r)->getData()['error']!==null,'Transfer entry rejects unoffered/subdomain endings');
$r->query->set('name',['bad']);same([],$entry->show($r)->getData()['results'],'Malformed transfer name creates no intent');
$unchecked=$newTransfer('owner-unchecked.com');$makeTransfer();$state->rdapUnknown=true;$sealed=$engine->execute($unchecked->id);$state->rdapUnknown=false;
same(true,$sealed->attempt['transfer_admission']['rdap_unchecked'],'Real sealed attempt retains unchecked provenance');
$dto=$owners->present($sealed->domain);expect($dto->transfer['unchecked']&&!$dto->transfer['open'],'Sealed provenance survives the closed editor');
storefrontViews();$calls=$state->calls;$html=$controller->code($request($user),$sealed->domain_id)->render();
expect(str_contains($html,'We couldn’t check this domain’s public registration record for this transfer.'),'Real presenter-to-Blade preserves unchecked provenance');
same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Provenance grants no code editor');same($calls,$state->calls,'Rendering provenance performs no I/O');
require __DIR__.'/Support/late-contact-pages.php';
foreach(['show','setup','code','index','transfer']as$route){$r=Illuminate\Support\Facades\Route::getRoutes()->getByName('domains.domains.'.$route);expect(in_array('auth',$r->gatherMiddleware(),true)&&in_array('web',$r->gatherMiddleware(),true),'Dormant routes have native auth/session boundary');}
$nativeSuccessful=true;echo "Owned DTO, native controllers, real Blade, held/correction/capability and ownership checks passed.\n";
