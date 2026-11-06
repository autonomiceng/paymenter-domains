<?php
// Native owner renewal request; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/reconciliation-fixture.php';
require __DIR__.'/owner-renewal-terms.php';
require __DIR__.'/owner-pages.php';
// Preserve the installed routing contract while exercising the real owner renewal path.
app()->forgetInstance(\Paymenter\Extensions\Servers\Domains\Services\Storefront::class);
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, OwnedDomains, RenewalCycles, RuntimeIneligible, RuntimeReason};
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();
$billing=new DomainBilling($bindings,$guard,$clock,catalogue: $GLOBALS['caps']??null);Queue::fake();
$controller=new OwnerRenewalController($owners,$billing);$session=app('request')->session();$other=commerceUser();
$d=$late=null;
if(($ownerRenewalDomains??2)>0){
    [$source]=$prepare(placeholderComFixtureName('owner-renew-placeholder.com'));same('succeeded',$reconcile->run($source->id)->state,'Owner registration confirmed');
    $d=$source->domain()->firstOrFail();
}
if(($ownerRenewalDomains??2)>1){
    [$lateSource]=$prepare(placeholderComFixtureName('owner-renew-late-placeholder.com'));same('succeeded',$reconcile->run($lateSource->id)->state,'Late registration confirmed');
    $late=$lateSource->domain()->firstOrFail();
}
$server=$product->server;Queue::fake();storefrontViews();
$setting=static fn(string $key,bool $value)=>$server->settings()->updateOrCreate(['key'=>$key],['value'=>$value,'type'=>'boolean']);
$req=static function($domain,array $data,$actor=null,?string $intent=null)use($user,$session):Request {
    $r=Request::create('/domains/'.$domain->id.'/renew','POST',$data);$r->setUserResolver(fn()=>$actor??$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$domain->id.':'.($intent??'renew')));return $r;
};
$refused=static function($domain,array $data,string $message)use($controller,$req,$session):void {
    $session->forget('errors');$response=$controller->renew($req($domain,$data),$domain->id);
    same(route('domains.domains.show',$domain->id),$response->getTargetUrl(),'Refusal returns to the domain page');
    same([$message],$session->get('errors')->getBag('default')->get('renew'),'Exact owner refusal: '.$message);
};
$replay=static function($call):void {try{$call();expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Replay needs a fresh intent');}};
$bounded=static function(callable $read):mixed {
    DB::flushQueryLog();DB::connection()->enableQueryLog();
    try{$result=$read();$queries=DB::getQueryLog();}finally{DB::connection()->disableQueryLog();DB::flushQueryLog();}
    $operations=0;
    foreach($queries as $query){
        expect(!preg_match('/^(?:insert|update|delete) /i',$query['query']),'Owner read has no database writes');
        if(str_contains($query['query'],'from "domains_operations"')){$operations++;expect(str_contains($query['query'],'limit'),'Owner renewal operation reads are bounded');}
    }
    expect($operations>0,'Owner renewal read checks operation queries');return $result;
};
$page=static function($domain)use($user,$session,$owners,$bounded):string {
    $r=Request::create('/domains/'.$domain->id,'GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    return $bounded(fn()=>(new OwnedDomainController($owners))->show($r,$domain->id)->render());
};
$ledger=static fn()=>serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),Operation::where('action','renew')->get()->map->getRawOriginal()->all()]);
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
