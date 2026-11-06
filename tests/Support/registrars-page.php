<?php
// Registrars list and account page: status, balance, Due in 30 days and Add funds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/native-commerce.php';
require __DIR__.'/staff-panel.php';
require_once __DIR__.'/accounts.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Actions\Testing\TestAction;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as PslPage;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,RegistrarAccount,RegistrarBalances as BalancesPage,RegistrarHistory,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, BalanceReads, BalanceSchema, ContactDetails, EnomTestSendLimit, EnomTestSendLimitSchema, ObservationSchema, RegistrarBalances, ReminderSchedules, ReminderSchema, RenewalBillingSettings, StaffSettingsReview};
extract(commerceFixture(true));$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
app()->instance(AccountBindings::class,$bindings);app()->instance(Clock::class,$clock);app()->instance(RegistrarBalances::class,new RegistrarBalances($bindings,$clock,new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock)));
$enom=Account::where('server_id',$tlds[0]->server_id)->sole();$silo=Account::where('server_id',$tlds[1]->server_id)->sole();
App\Models\Server::whereKey($enom->server_id)->update(['name'=>'Enom test account']);App\Models\Server::whereKey($silo->server_id)->update(['name'=>'NameSilo test account']);
// Native checkout still creates invoices and dispatches every admission/binding event. Only the
// unrelated PDF attachment renderer uses Paymenter's supported document-extension event here.
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\GeneratePdf::class,static function(App\Events\Invoice\GeneratePdf $event):void {
    $event->setPdfFromContent("%PDF-1.4\n% Synthetic registrar-page invoice attachment.\n%%EOF\n");
});
$user=commerceUser();$product=$native->product($tlds[0],'register');
// This page fixture needs real checkout, not a separate credit-checkbox render. Livewire's
// native update batches that property and the checkout call, as one browser request does.
$checkout=static function(App\Models\Cart $cart)use($user){
    Illuminate\Support\Once::flush();
    $params=$user->properties()->whereIn('key',['address','city','zip','country','phone'])->distinct()->count('key')===5
        ? [] : [['contact'=>commerceOrderContact($user),'save_account'=>false]];
    return Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class)
        ->update(calls:[['method'=>'checkout','params'=>$params,'path'=>'']],updates:['use_credits'=>false]);
};
// The balance forecast needs real service/domain bindings; native checkout exercises the admission and invoice hooks.
// Synthetic offer composition keeps registrar deployment and physical sends outside this page fixture.
$eligibility=new class($bindings,$clock) extends \Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility {
    public \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers {return $this->fixtureOffers;}
};$eligibility->fixtureOffers=$offers;
$admission=new class($native,$sellers,$eligibility,$clock) extends \Paymenter\Extensions\Servers\Domains\Services\CartAdmission {
    public \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers {return $this->fixtureOffers;}
};$admission->fixtureOffers=$offers;$admission->boot();
$invoiceBinding=new \Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding($admission,$native,$prices,$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);$invoiceBinding->boot();
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('registrars-due-placeholder.com'));
$checkout($cart)->assertHasNoErrors();gc_collect_cycles();gc_mem_caches();
$service=App\Models\Service::where('user_id',$user->id)->sole();$service->update(['status'=>'active']);
// One renewal due within 30 days at registrar cost 8.00; the balance is one cent short.
$domain=Domain::where('service_id',$service->id)->sole();
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'active','provider_expires_at'=>'2026-11-10']);
BalanceReads::record(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($enom),new Money('7.99','USD'),$clock->now(),'manual');
$facts=RegistrarBalances::facts($enom,$clock->now());
same('USD 7.99',$facts['balance'],'Staff money puts the currency first');same('Low balance',$facts['status'],'Balance below Due in 30 days is Low balance');
same('USD 8.00',$facts['total'],'Due in 30 days');same('Oct 18, 2026 12:00 UTC',$facts['checked'],'Checked date in staff format');same(null,$facts['cause'],'A read balance has no cause');
$siloFacts=RegistrarBalances::facts($silo,$clock->now());same(null,$siloFacts['balance'],'Unread balance is Missing');same('Not checked yet. Use Check balance.',$siloFacts['cause'],'Missing balance names its cause');
same('https://ote.namesilo.com/cart/add-funds',RegistrarBalances::fundsUrl('namesilo','test'),'NameSilo Test funds the sandbox account');
same('https://www.namesilo.com/cart/add-funds',RegistrarBalances::fundsUrl('namesilo','live'),'NameSilo Live funds the live account');
same('https://cp.enom.com/',RegistrarBalances::fundsUrl('enom','test'),'Enom funding home');
// A server without an account, a disabled server and a seeded demo account.
$empty=App\Models\Server::create(['name'=>'Spare account','extension'=>'Domains','type'=>'server','enabled'=>true]);
$off=App\Models\Server::create(['name'=>'Old account','extension'=>'Domains','type'=>'server','enabled'=>false]);
$demoId=(int)App\Models\Server::max('id')+1;fixtureAccount($demoId,'namesilo','demo-namesilo');$demo=Account::where('server_id',$demoId)->sole();
App\Models\Server::whereKey($demo->server_id)->update(['name'=>'Demo NameSilo']);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__,2).'/Domains/resources/views');
staffPanel();$panel=Filament::getCurrentPanel();$pages=[RegistrarSetup::class,RegistrarAccount::class,RegistrarHistory::class,BalancesPage::class,ActivationStatus::class,PslPage::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser([Staff::VIEW]);Auth::setUser($staff);
$sync=Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::lastSync((int)$enom->server_id);
expect($sync!==null,'Last sync uses the saved registrar price read even without a runtime tick');
\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::verify();
DB::table('domains_runtime_turns')->insert(['server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,'finished_at'=>$clock->now()->getTimestamp()+60]);
same($sync,Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::lastSync((int)$enom->server_id),'A worker tick without a read does not change Last sync');
DB::table('domains_runtime_turns')->where('server_id',$enom->server_id)->delete();
config(['settings.credits_enabled'=>false]);
$credits=collect(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::system()['failing'])->firstWhere('label','Customer credits');
expect($credits!==null&&str_contains((string)$credits['cause'],'Turn on Credits in Settings.'),'Hidden Credits page warns with the setting to change');
// slop-sweep: the row already says Customer credits Off, so the cause no longer repeats it.
expect(str_contains((string)$credits['cause'],'Credit refunds still reach the customer’s balance') && !str_contains((string)$credits['cause'],'Refunds stay'), 'Credits off explains the setting and refund destination without implying a refund failed');
config(['settings.credits_enabled'=>true]);
same(null,collect(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::system()['failing'])->firstWhere('label','Customer credits'),'Warning clears when customers can use Credits');


ReminderSchedules::enroll((int)$enom->server_id);
$admin=staffUser([Staff::VIEW,Staff::POLICY,...array_map([StaffSettingsReview::class,'permission'],array_keys(StaffSettingsReview::ACTIONS))]);
// Setup buttons show only when they can run: bind a writer that can reach the registrar.
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites(app(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class),app(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class),app(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class),true));
$siloAccount=Account::where('server_id',$silo->server_id)->sole();$siloServer=(int)$silo->server_id;
$siloRows=static fn()=>array_column(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::setup($siloAccount->fresh(),['label'=>'Active','reason'=>null]),null,'label');
Auth::setUser($staff);

// Match the legacy NameSilo gates used by the original account and activation cases.
config(['domains.namesilo_test.management'=>false,'domains.namesilo_test.renewal'=>false]);
