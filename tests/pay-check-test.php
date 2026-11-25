<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Full fixture: the stop has to be proven on the real Livewire pay call, native invoice rows and the holding locks.
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/acquisition-retirement.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Livewire\Invoices\Show;
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{Cache,DB,View};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,Query};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, OperationClaims, PayCheck, PricingRule, RegistrarRegistry, RetailLine, RuntimeWork};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

$live=(object)['available'=>true,'premium'=>false,'cost'=>'0.01','fail'=>false,'reads'=>0,'query'=>null];
$registry=new RegistrarRegistry;
$verifier=new class implements AccountVerifier {public function verify(Environment $e,#[SensitiveParameter] array $credentials):string{return 'verified-stable-id';}};
$payReader=static function(AccountBinding $b,array $c)use($live,$clock):FakeRegistrar {
    $quote=$live->query?new QuoteEvidence($live->query,new Money($live->cost,'USD'),CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds')):null;
    $reader=new FakeRegistrar($b,new Capabilities($b,[]),new DriverResult($b,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(quote:$quote,available:$live->available,premium:$live->premium)));
    $reader->beforeRead=static function(string $method)use($live):void {
        same(0,DB::transactionLevel(),'The live read holds no database lock');
        if($method==='availability'){$live->reads++;}
        if($live->fail){throw new RuntimeException('Synthetic registrar timeout');}
    };
    return $reader;
};
$registry->register('enom',['token'],$verifier,$payReader,registrationPreflightFactory:$payReader);
require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
// Initialize the genuine installed holding route before the isolated pay-check fixture replaces its adapter.
Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed();
// Pay checks and checkout share this explicit active catalogue composition.
$payCheck=new class(new AccountBindings($registry),$clock) extends PayCheck {use OfferCommerceRoute;};
$payCheck->fixtureOffers=$offers;app()->instance(PayCheck::class,$payCheck);PayCheck::boot();
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
// Every case starts from the same checkout fixture, pay routes, live registrar read and USD 500 credit. Routes and the
// pay check hook are registered once, before the checkpoint. Each case pins the prepared clock first.
$pinned=[$clock->now(),Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->instant,$native,$immutable]=$pinned;Carbon\Carbon::setTestNow($native);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=compact('pin','live','new','pay','stop','funds','cash','fresh','money','product','user','clock','ports','offers','writer','assertNoSend');
// Taken, on each of the three pay paths: credit, a gateway and a saved card.
$cases['paths']=static function()use($fixed):void {extract($fixed);
    $pin();
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
    // A second customer can buy the name that was released. Fixed details keep the case effects the same on every run.
    $first=$op;$fresh();$other=App\Models\User::factory()->create(['first_name'=>'Second','last_name'=>'Customer','email'=>'second-customer@example.com']);$cart=commerceCart($other);commerceAdd($cart,$product,$first->domain->domain,1);commerceCheckout($other,$cart);
    same([(int)$other->id,'registration_pending'],Domain::where('reservation_name',$first->domain->domain)->get(['user_id','status'])->map(fn($d)=>[(int)$d->user_id,$d->status])->sole(),'The next customer holds the released name');
};
// The price went up, the name turned premium, a registrar blip, a double click and a page load.
$cases['checks']=static function()use($fixed):void {extract($fixed);
    $pin();
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
};
// Real money on the invoice: the existing paths own it.
$cases['money']=static function()use($fixed):void {extract($fixed);
    $pin();
    $fresh();$live->available=false;$op=$new(placeholderComFixtureName('part-paid-placeholder.com'),paid:false);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);$stop(Invoice::findOrFail($op->invoice_id));
    same(['awaiting_payment',0],[$op->fresh()->state,$live->reads],'A partly paid order stays open, with no read');
    $funds();$pay(Invoice::findOrFail($op->invoice_id));same(['paid',0],[Invoice::findOrFail($op->invoice_id)->status,$live->reads],'A TEST partly paid invoice still accepts the rest without a name read');
    same(null,$stop(Invoice::findOrFail($op->invoice_id)),'A fully paid TEST invoice stays outside the pay gate');same(0,$live->reads,'A fully paid TEST invoice makes no name read');
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
};
// An invoice issued before Orders went off stays payable at its original amount and sends nothing.
$cases['orders-off']=static function()use($fixed):void {extract($fixed);
    $pin();
    $op=$new('walk3-issued-placeholder.com',false);$invoice=App\Models\Invoice::findOrFail($op->invoice_id);
    $fresh();$funds();
    Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>false]);
    $binding=AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole());
    $query=new Query($binding,['com'],'register');
    // Use the staff pricing command, which republishes retail but retains native prices while Orders is off.
    $ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:true),[...PricingRule::DEFAULT,'markup'=>'50.00']),
        $ports->readOffers($query,Context::Staff)[0]->revision);
    $nativePrice=$product->plans()->where('billing_period',1)->sole()->prices()->where('currency_code','USD')->sole();
    same($op->amount,RetailLine::decimal($nativePrice->price),'Orders off retains the original native price');
    same('9.00000000',(string)Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$op->server_id,'tld'=>'com','action'=>'register','term'=>1])->sole()->retail,'The staff edit changes current retail');
    $before=PricingRule::minor($cash());
    same(null,$stop($invoice),'An issued invoice remains payable while Orders is off');
    $pay($invoice)->assertHasNoErrors();
    same('paid',$invoice->fresh()->status,'The issued invoice accepts payment');
    same(PricingRule::minor($op->amount),$before-PricingRule::minor($cash()),'Native payment collects the original invoice amount');
    same($op->amount,RetailLine::decimal($invoice->fresh()->transactions()->sole()->amount),'The retained payment uses the issued amount');
    $assertNoSend($op);
    same(0,$writer->calls,'Orders off sends no registrar request');
    same(['held','processing'],[$op->fresh()->state,$op->fresh()->safe_code],'Paid work stays held while Orders is off');
    commerceReject(fn()=>$offers->offer('com','register',1));
};
nativeFixtureCases($cases,compact('clock','live','registry','payCheck','offers','ports','writer','preflight','engine','claims','admission','invoiceBinding','retirement','user','product'));
echo "Pay check stops, blips, mixed invoices and Orders off payment passed.\n";$nativeSuccessful=true;
