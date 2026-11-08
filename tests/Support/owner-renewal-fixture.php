<?php
// Shared native owner renewal fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
// These matrices exercise Enom holdings; keep other fixtures' two-registrar default unchanged.
$fixtureRegistrars=['enom'];
require __DIR__.'/operation-fixture.php';
// One real native checkout creates independent lines and operations, without rebuilding Livewire per domain.
$runtime=app()->bound(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class)?app(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class):null;
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
try {
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $cart=commerceCart($user);
    foreach($registrationNames as $name){commerceAdd($cart,$product,placeholderComFixtureName($name));}
    commerceCheckout($user,$cart)->assertHasNoErrors();$registrations=[];
    foreach($registrationNames as $name){
        $registration=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain',$name)->sole()->operations()->sole();
        Illuminate\Support\Facades\DB::table('invoices')->where('id',$registration->invoice_id)->update(['status'=>'paid']);
        App\Models\InvoiceTransaction::create(['invoice_id'=>$registration->invoice_id,'amount'=>$registration->amount,'fee'=>'0.00','status'=>'succeeded']);
        $registrations[$name]=$registration;
    }
} finally {if($runtime!==null){app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class,$runtime);}}
$new=static function(string $name)use(&$registrations){$op=$registrations[$name];unset($registrations[$name]);return $op->fresh();};
require __DIR__.'/reconciliation-fixture.php';
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
[$source]=$prepare(placeholderComFixtureName('owner-renew-placeholder.com'));same('succeeded',$reconcile->run($source->id)->state,'Owner registration confirmed');
[$lateSource]=$prepare(placeholderComFixtureName('owner-renew-late-placeholder.com'));same('succeeded',$reconcile->run($lateSource->id)->state,'Late registration confirmed');
$d=$source->domain()->firstOrFail();$late=$lateSource->domain()->firstOrFail();$server=$product->server;Queue::fake();storefrontViews();
$setting=static fn(string $key,bool $value)=>$server->settings()->updateOrCreate(['key'=>$key],['value'=>$value,'type'=>'boolean']);
$req=static function($domain,array $data,$actor=null,?string $intent=null)use($user,$session):Request {
    $r=Request::create('/domains/'.$domain->id.'/renew','POST',$data);$r->setUserResolver(fn()=>$actor??$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$domain->id.':'.($intent??'renew')));return $r;
};
$refused=static function($domain,array $data,string $message)use($controller,$req,$session):void {
    $session->forget('errors');$response=$controller->renew($req($domain,$data),$domain->id);
    same(route('domains.domains.show',$domain->id),$response->getTargetUrl(),'Refusal returns to the domain page');
    same([$message],$session->get('errors')->getBag('default')->get('renew'),'Exact owner refusal: '.$message.'; received '.json_encode($session->get('errors')->getBag('default')->get('renew')));
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
// The auto-renew confirmation explains Renew now even when manual requests are off.
// Check actual links, dialogs and submit controls, including hidden ones, rather than prose.
$noRenewalForm=static function(string $html,$domain,string $message):void {
    $dom=storefrontDom($html);$url=route('domains.domains.renew',$domain->id);
    $open=route('domains.domains.show',$domain->id).'?edit=renew#edit-renew';
    same(0,$dom->query('//form[@action="'.$url.'"]|//*[@formaction="'.$url.'"]|//a[@href="'.$url.'" or @href="'.$open.'"]|//dialog[@id="edit-renew"]|//select[@id="renew-years"]')->length,$message);
};
$ledger=static fn()=>serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),Operation::where('action','renew')->get()->map->getRawOriginal()->all()]);
