<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
expect(is_file(__DIR__.'/../Domains/Services/ComplimentaryAccounts.php'),'Account complimentary funding must be implemented');
// Native checkout, invoice observers and retained financial locks require the real Paymenter fixture.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice,InvoiceTransaction,Role,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,CartAdmission,InvoiceBinding,InvoiceFunding,NativeDomainGuard,HolderPricing};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock=new FakeClock($now);Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now); Queue::fake();
$staff=commerceUser();$role=Role::create(['name'=>'Fund domains','permissions'=>['admin.domains.fund']]);$staff->update(['role_id'=>$role->id]);
$user=commerceUser();$other=commerceUser();
commerceReject(fn()=>ComplimentaryAccounts::set($user->id,$other,true,'Approved gift',$clock));
commerceReject(fn()=>ComplimentaryAccounts::set($user->id,$staff,true,'',$clock));
ComplimentaryAccounts::set($user->id,$staff,true,'Approved gift',$clock);
same(true,ComplimentaryAccounts::current($user->id)['enabled'],'Account switch saved');
$admission=activeFixtureAdmission($native,$sellers,activeFixtureEligibility($bindings,$clock),$clock);$admission->boot();
$binder=new InvoiceBinding($admission,$native,$prices,$clock);$binder->boot();app()->instance(InvoiceBinding::class,$binder);
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$product=$native->product($tlds[0],'register');$hosting=commerceHosting();
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('comp-account-placeholder.com'));commerceAdd($cart,$hosting,'hosting');
$checkout=commerceCheckout($user,$cart);$checkout->assertHasNoErrors();
expect(Domain::where('domain','comp-account-placeholder.com')->exists(),'Complimentary checkout must commit: '.json_encode($checkout->effects['dispatches']??[]));
$d=Domain::where('domain','comp-account-placeholder.com')->sole();$op=$d->operations()->sole();
same(['complimentary','0.00','complimentary'],[$op->funding_basis,$op->amount,$d->price_mode],'Only this account receives complimentary domain authority');
$gift=Invoice::findOrFail($op->invoice_id);same(['paid',0.0,0],[$gift->status,(float)$gift->total,$gift->transactions()->count()],'Gift invoice is paid with no payment transaction');
$hostingInvoice=Invoice::where('id','!=',$gift->id)->sole();same(['pending',5.0],[$hostingInvoice->status,(float)$hostingInvoice->total],'Hosting still charges its normal price');
// Native checkout and owner DTOs must agree before the registrar has finished registration.
$cataloguePorts=$ports;require __DIR__.'/Support/owner-pages.php';$ports=$cataloguePorts;
$pending=$owners->present($d->fresh());
expect($pending->order['complimentary']??false,'Pending registration retains its issued complimentary basis');
$notice=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($pending);
expect(str_contains($notice['text']??'','Complimentary.'),'Pending registration notice states complimentary even while choosing nameservers');
same($pending->actions['setup'],$notice['action']['url'],'Complimentary notice preserves the pending setup action');
$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
$expires=Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::expires($summary);
expect(str_contains($expires['cause']??'','Complimentary.'),'Pending list expiry states the same complimentary funding');
$proof=fn()=>DB::transaction(function()use($op,$d){$f=InvoiceFunding::lock($op->invoice_id);return $f->requireInitial($op->fresh(),$d->fresh());});
same('complimentary',$proof()['basis'],'Zero invoice uses complimentary funding only');
Event::dispatch(new App\Events\Invoice\Paid($gift));Event::dispatch(new App\Events\InvoiceItem\Created($gift->items()->sole()));
same(1,$d->operations()->count(),'Repeated invoice events cannot create another funding obligation');
ComplimentaryAccounts::set($user->id,$staff,false,'Gift ended',$clock);
same('standard',$d->fresh()->price_mode,'Switch off restores future standard terms');same('complimentary',$proof()['basis'],'An issued term retains its funding after switch off');
// The real native claim and preflight accept the frozen account grant at zero, after switch-off.
$claims=new \Paymenter\Extensions\Servers\Domains\Services\OperationClaims($bindings,$clock,$caps);
$claim=$claims->claim($op->id);expect($claim!==null,'Complimentary acquisition receives the existing guarded claim');
[$claimed,$held,$binding]=$claim;
$registry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$registry->register('enom',['token'],new class implements \Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier {
    public function verify(\Paymenter\Extensions\Servers\Domains\Data\Environment $environment,array $credentials):string{return $credentials['token'];}
},function($b)use($op,$clock){
    $query=\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($op,$op->domain);
    $quote=new \Paymenter\Extensions\Servers\Domains\Data\QuoteEvidence($query,new \Paymenter\Extensions\Servers\Domains\Data\Money('6.00','USD'),\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+120 seconds'));
    return new \Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($b,new \Paymenter\Extensions\Servers\Domains\Data\Capabilities($b,[]),new \Paymenter\Extensions\Servers\Domains\Data\DriverResult($b,\Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,\Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,\Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,new \Paymenter\Extensions\Servers\Domains\Data\Evidence(quote:$quote,balance:new \Paymenter\Extensions\Servers\Domains\Data\Money('100.00','USD'),available:true,premium:false)));
});
$features=new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration:true);
$accepted=new \Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding,true,true,25,catalogue:$caps);
$preflight=new \Paymenter\Extensions\Servers\Domains\Services\RegistrationPreflight(new \Paymenter\Extensions\Servers\Domains\Services\AccountBindings($registry),$features,$accepted);
$context=new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+300 seconds'),new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(10),$clock);
$ready=$preflight->inspect($claimed,$held,$context);$sealed=$claims->seal($claimed,$ready,$clock->now(),$preflight);
expect($sealed!==null,'Complimentary registration seals through existing registrar safeguards');same('complimentary',$sealed->funding['seal']['basis'],'The invoice did not supply a second payment basis');
$history=DB::table('domains_complimentary_accounts')->where('user_id',$user->id)->orderBy('id')->get();
same([1,0],$history->pluck('enabled')->map(fn($v)=>(int)$v)->all(),'Every change retains on/off history');same($staff->id,(int)$history->last()->actor_id,'Audit records the staff actor');
$cart=commerceCart($other);commerceAdd($cart,$product,placeholderComFixtureName('ordinary-account-placeholder.com'));commerceCheckout($other,$cart);
$ordinary=Domain::where('domain','ordinary-account-placeholder.com')->sole()->operations()->sole();same('paid_invoice',$ordinary->funding_basis,'Other accounts retain paid invoice funding');expect((float)$ordinary->amount>0,'Other accounts still pay');
$coupon=App\Models\Coupon::create(['code'=>'FREE-DOMAIN-REFUSED','type'=>'percentage','value'=>100,'applies_to'=>'price','recurring'=>0]);
$cart=commerceCart($other);commerceAdd($cart,$product,placeholderComFixtureName('ordinary-free-placeholder.com'));$cart->update(['coupon_id'=>$coupon->id]);$before=Domain::count();commerceCheckout($other,$cart);same($before,Domain::count(),'Other account remains refused at zero net');
echo "Account grant, mixed checkout, retained funding and ordinary pricing passed\n";$nativeSuccessful=true;
