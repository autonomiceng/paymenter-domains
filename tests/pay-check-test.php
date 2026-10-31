<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Full fixture: the stop has to be proven on the real Livewire pay call, native invoice rows and the holding locks.
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/acquisition-retirement.php';
use App\Livewire\Invoices\Show;
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{Cache,DB,View};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,OperationClaims,PayCheck,RegistrarRegistry,RuntimeWork};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

$live=(object)['available'=>true,'premium'=>false,'cost'=>'0.01','fail'=>false,'reads'=>0,'query'=>null];
$registry=new RegistrarRegistry;
$verifier=new class implements AccountVerifier {public function verify(Environment $e,#[SensitiveParameter] array $credentials):string{return 'verified-stable-id';}};
$registry->register('enom',['token'],$verifier,static function(AccountBinding $b,array $c)use($live,$clock):FakeRegistrar {
    $quote=$live->query?new QuoteEvidence($live->query,new Money($live->cost,'USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds')):null;
    $reader=new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(quote:$quote,available:$live->available,premium:$live->premium)));
    $reader->beforeRead=static function(string $method)use($live):void {
        same(0,DB::transactionLevel(),'The live read holds no database lock');
        if($method==='availability'){$live->reads++;}
        if($live->fail){throw new RuntimeException('Synthetic registrar timeout');}
    };
    return $reader;
});
require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
app()->instance(PayCheck::class,new PayCheck(new AccountBindings($registry),$clock));PayCheck::boot();
$aim=static function(Invoice $invoice)use($live):void {$op=Operation::where('invoice_id',$invoice->id)->first();$live->query=OperationClaims::query($op,$op->domain);};
// The three pay paths prove the hook; the other cases ask the same check directly because a Livewire render takes two seconds here.
$stop=static function(Invoice $invoice)use($aim):?array {$aim($invoice);return app(PayCheck::class)->stop($invoice);};
$pay=static function(Invoice $invoice,string $method='credit')use($user,$aim){
    $aim($invoice);
    return Livewire::actingAs($user)->test(Show::class,['invoice'=>$invoice->fresh()])->set('selectedMethod',$method)->call('processPayment');
};
$funds=static function()use($user):void {Credit::updateOrCreate(['user_id'=>$user->id,'currency_code'=>'USD'],['amount'=>'500.00']);};
$cash=static fn()=>(string)Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount');
$fresh=static function()use($live):void {Cache::flush();$live->available=true;$live->premium=false;$live->fail=false;$live->reads=0;$live->cost='0.01';};
$money=static fn(Operation $op)=>InvoiceTransaction::where('invoice_id',$op->invoice_id)->whereIn('status',['succeeded','processing'])->count();
$funds();
// Taken, on each of the three pay paths: credit, a gateway and a saved card.
foreach(['credit'=>'credit','gateway'=>'gateway-1','saved'=>'01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $path=>$method){
    $fresh();$live->available=false;$before=$cash();
    $op=$new(placeholderComFixtureName('taken-'.$path.'-placeholder.com'),paid:false);$invoice=Invoice::findOrFail($op->invoice_id);
    $page=$pay($invoice,$method);
    same(['cancelled','rejected',0,$before],[$invoice->fresh()->status,$op->fresh()->state,$money($op),$cash()],$path.': the order closes and no money moves');
    same(null,$op->domain->fresh()->reservation_name,$path.': the name is free');
    same(1,$live->reads,$path.': one read');
    $page->assertRedirect(route('domains.domains.search',['name'=>$op->domain->domain]));
    same($op->domain->domain,session('domains.closed.name'),$path.': the search page names the domain');
}
// A second customer can buy the name that was released.
$first=$op;$fresh();$other=App\Models\User::factory()->create();$cart=commerceCart($other);commerceAdd($cart,$product,$first->domain->domain,1);commerceCheckout($other,$cart);
same([(int)$other->id,'registration_pending'],Domain::where('reservation_name',$first->domain->domain)->get(['user_id','status'])->map(fn($d)=>[(int)$d->user_id,$d->status])->sole(),'The next customer holds the released name');
// The price went up, or the name turned premium.
foreach(['cost','premium'] as $change){
    $fresh();if($change==='cost'){$live->cost='5000.00';}else{$live->premium=true;}
    $op=$new(placeholderComFixtureName('changed-'.$change.'-placeholder.com'),paid:false);$closed=$stop(Invoice::findOrFail($op->invoice_id));
    same(['cancelled','changed'],[Invoice::whereKey($op->invoice_id)->value('status'),$closed['why']],$change.': the order closes and says the price changed');
}
// A registrar blip never stops a sale.
$fresh();$live->fail=true;$op=$new(placeholderComFixtureName('blip-placeholder.com'),paid:false);$pay(Invoice::findOrFail($op->invoice_id));
same(['paid',1],[Invoice::whereKey($op->invoice_id)->value('status'),InvoiceTransaction::where('invoice_id',$op->invoice_id)->count()],'A failed read lets payment go');
// A second click inside the cache sends no second read.
$funds();$fresh();$op=$new(placeholderComFixtureName('double-click-placeholder.com'),paid:false);$invoice=Invoice::findOrFail($op->invoice_id);
$stop($invoice);$stop($invoice);same(1,$live->reads,'Two clicks, one read');
// Opening the pay form sends nothing.
$fresh();$op=$new(placeholderComFixtureName('page-load-placeholder.com'),paid:false);$invoice=Invoice::findOrFail($op->invoice_id);
Livewire::actingAs($user)->test(Show::class,['invoice'=>$invoice->fresh()])->call('processPayment');same(0,$live->reads,'No pay method chosen, no read');
// Real money on the invoice: the existing paths own it.
$fresh();$live->available=false;$op=$new(placeholderComFixtureName('part-paid-placeholder.com'),paid:false);
InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);$stop(Invoice::findOrFail($op->invoice_id));
same(['awaiting_payment',0],[$op->fresh()->state,$live->reads],'A partly paid order stays open, with no read');
// A declined card is not money, so it does not keep the name.
$fresh();$live->available=false;$op=$new(placeholderComFixtureName('declined-placeholder.com'),paid:false);
InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'failed']);$stop(Invoice::findOrFail($op->invoice_id));
same('cancelled',Invoice::whereKey($op->invoice_id)->value('status'),'A declined card does not block the close');
// Domain plus hosting: the rest stays payable on a new invoice and the old one keeps its history.
$fresh();$live->available=false;$op=$new(placeholderComFixtureName('mixed-placeholder.com'),paid:false);$old=Invoice::findOrFail($op->invoice_id);
$hostingProduct=App\Models\Product::create(['name'=>'Hosting','slug'=>'hosting','category_id'=>$product->category_id]);$hostingPlan=$hostingProduct->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$domainService=Service::findOrFail($op->service_id);
$hosting=Service::create(['order_id'=>$domainService->order_id,'user_id'=>$user->id,'product_id'=>$hostingProduct->id,'plan_id'=>$hostingPlan->id,'currency_code'=>'USD','price'=>'7.00','quantity'=>1,'status'=>'pending']);
DB::table('invoice_items')->insert(['invoice_id'=>$old->id,'reference_type'=>Service::class,'reference_id'=>$hosting->id,'price'=>'7.00','quantity'=>1,'description'=>'Hosting','created_at'=>$clock->now(),'updated_at'=>$clock->now()]);
$pay($old);
$rest=Invoice::whereKey(session('domains.closed.invoice'))->first();
same(['cancelled',2,'pending','7.00',$hosting->id,'pending'],[$old->fresh()->status,$old->items()->count(),$rest?->status,(string)$rest?->items()->sole()->price,(int)$rest?->items()->sole()->reference_id,$hosting->fresh()->status],'The hosting line moves and stays payable');
View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');$banner=view('domains::partials.order-closed')->render();
same([true,true],[str_contains($banner,$op->domain->domain),str_contains($banner,route('invoices.show',$rest))],'The banner names the domain and links the rest of the order');
echo "Pay check stops, blips and mixed invoices passed.\n";$nativeSuccessful=true;
