<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Full fixture: expiry has to be proven on the real operations tick and the native invoice, service and credit rows.
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/acquisition-retirement.php';
require __DIR__.'/Support/reminder-mail.php';
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\Show;
use App\Models\{Credit,Invoice,InvoiceTransaction,Server,Service};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB,Event,Mail,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Data\{CatalogueEntry,CatalogueRate};
use Paymenter\Extensions\Servers\Domains\Mail\DomainNotice;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,CatalogueAcceptances,NativeServiceRefusal,OwnedDomains,PayCheck,PricingRule,Refunds,Reminders,RuntimePorts,RuntimeSchema,RuntimeWork,UnpaidHoldSetting,WriteHeld};

require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
if(class_exists(RuntimeSchema::class)){RuntimeSchema::install();}
Queue::fake();PayCheck::boot();
app()->instance(Refunds::class,new Refunds($clock));app()->instance(Paymenter\Extensions\Servers\Domains\Services\OperationClaims::class,$claims);
$serverId=(int)$tlds[0]->server_id;$runtime=new RuntimeWork($clock,new RuntimePorts);
$later=static function(int $minutes)use($clock):void {
    $clock->instant=$clock->now()->modify('+'.$minutes.' minutes');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
};
$tick=static function()use($runtime):void {DB::table('domains_runtime_turns')->update(['due_at'=>0]);$runtime->tickOperations();};
$cash=static fn()=>number_format((float)Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount'),2,'.','');
$open=static fn($op)=>[Invoice::whereKey($op->invoice_id)->value('status'),$op->fresh()->state,$op->domain->fresh()->reservation_name];

$old=$new(placeholderComFixtureName('hold-old-placeholder.com'),paid:false);
$debit=$new(placeholderComFixtureName('hold-debit-placeholder.com'),paid:false);InvoiceTransaction::create(['invoice_id'=>$debit->invoice_id,'amount'=>$debit->amount,'fee'=>'0.00','status'=>'processing']);
$kept=$new(placeholderComFixtureName('hold-kept-placeholder.com'),paid:false);DB::table('domains_operations')->where('id',$kept->id)->update(['state'=>'held','safe_code'=>'processing']);
$sent=$new(placeholderComFixtureName('hold-sent-placeholder.com'),paid:false);$sent->update(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);
$later(50);
$young=$new(placeholderComFixtureName('hold-young-placeholder.com'),paid:false);
$later(11);$tick();
same(['cancelled','rejected',null],$open($old),'Past the hold the name is free, on the tick the demo allows');
same(['pending','awaiting_payment',$debit->domain->domain],$open($debit),'A bank debit in flight keeps the order');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$debit->domain_id)->count(),'A bank debit in flight asks nobody');
same(['pending','held',$kept->domain->domain],$open($kept),'A saved reason keeps the order');
same(['pending','awaiting_payment',$sent->domain->domain],$open($sent),'A sent attempt keeps the order');
same(['pending','awaiting_payment',$young->domain->domain],$open($young),'Inside the hold nothing changes');
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$sent->domain_id)->where('code','acquisition_retirement_review')->count(),'A refused close goes to staff');
$later(40);$tick();same(1,DB::table('domains_obligation_alerts')->where('domain_id',$sent->domain_id)->where('code','acquisition_retirement_review')->count(),'Staff are asked once');
// The customer comes back to the closed invoice.
Livewire::actingAs($user)->test(Show::class,['invoice'=>Invoice::findOrFail($old->invoice_id)])->assertRedirect(route('domains.domains.search',['name'=>$old->domain->domain]));
same('expired',session('domains.closed.why'),'The search page says the order expired');
// Staff shorten the hold; a bad saved value never holds a name forever.
same(UnpaidHoldSetting::DEFAULT,UnpaidHoldSetting::minutes($serverId),'Sixty minutes by default');
$row=Server::findOrFail($serverId)->settings()->create(['key'=>UnpaidHoldSetting::KEY,'value'=>'5','type'=>'integer']);
$short=$new(placeholderComFixtureName('hold-short-placeholder.com'),paid:false);$later(6);$tick();
same('cancelled',Invoice::whereKey($short->invoice_id)->value('status'),'The saved hold applies');
$row->update(['value'=>'0']);same(UnpaidHoldSetting::DEFAULT,UnpaidHoldSetting::minutes($serverId),'A bad saved value uses the default');

// The registrar refuses after payment: the money comes back with no staff step.
$refused=$new(placeholderComFixtureName('refused-placeholder.com'));$before=$cash();
$preflight->during=static fn()=>throw new WriteHeld('name_unavailable');$held=$engine->execute($refused->id);$preflight->during=null;
same(['held','name_unavailable'],[$held->state,$held->safe_code],'The refusal holds before the sweep');
$tick();$tick();
same(number_format((float)$before+(float)$refused->amount,2,'.',''),number_format((float)$cash(),2,'.',''),'One credit, once');
same('cancelled',Service::whereKey($refused->service_id)->value('status'),'The refused service closes');
$fresh=$refused->fresh();same(['state'=>'cancelled','kind'=>'registration','credit'=>true],OwnedDomains::order($fresh->domain,$fresh,Invoice::find($fresh->invoice_id)),'The customer sees one plain state');
// A transfer refusal can be fixed by the customer, so it stays with staff.
$transfer=$new(placeholderComFixtureName('refused-transfer-placeholder.com'),action:'transfer');DB::table('domains_operations')->where('id',$transfer->id)->update(['state'=>'held','safe_code'=>'name_unavailable']);
$tick();same('held',$transfer->fresh()->state,'A refused transfer keeps its hold');

// A form left open pays a closed order. Each path ends in credit for each payment, once, and nothing starts.
Mail::fake();$paidEvents=0;Event::listen(App\Events\Invoice\Paid::class,function()use(&$paidEvents):void {$paidEvents++;});
AcquisitionRetirement::boot();app()->instance(Reminders::class,new Reminders($clock,$bindings));
$mails=static fn()=>Mail::queued(DomainNotice::class)->filter(fn($m)=>$m->emailTemplate->key==='domains_domain_order_refunded')->count();
$plus=static fn(string $cash,string ...$more)=>PricingRule::decimal(array_sum(array_map(PricingRule::minor(...),[$cash,...$more])));
$alerts=static fn($op)=>DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_late_money')->count();
$closed=static fn($op)=>[Invoice::whereKey($op->invoice_id)->value('status'),Service::whereKey($op->service_id)->value('status'),$op->domain->fresh()->reservation_name];
$shut=static function($op)use($retirement):void {$retirement->closeUnpaid((int)$op->invoice_id,PayCheck::notice('expired'));};
foreach(['register','transfer'] as $action){
    $late=$new(placeholderComFixtureName('late-'.$action.'-placeholder.com'),paid:false,action:$action);$before=$cash();$sent=$mails();$shut($late);
    ExtensionHelper::addPayment($late->invoice_id,null,amount:$late->amount);$tick();$tick();
    same(['cancelled','cancelled',null],$closed($late),$action.': the invoice stays closed, nothing starts and the name stays free');
    same($plus($before,$late->amount),$cash(),$action.': a full late payment becomes credit once');
    $retirement->inspect($late->domain_id);same([$sent+1,0,0],[$mails(),$paidEvents,$alerts($late)],$action.': one credit mail, no paid mail and nothing for staff');
    $fresh=$late->fresh();same(true,OwnedDomains::order($fresh->domain,$fresh,Invoice::find($fresh->invoice_id))['credit']??null,$action.': the customer sees the credit');
}
Livewire::actingAs($user)->test(Show::class,['invoice'=>Invoice::findOrFail($late->invoice_id)])->assertRedirect(route('domains.domains.search',['name'=>$late->domain->domain]));
same('paid_late',session('domains.closed')['why']??null,'A customer back from the pay form is not told they were not charged');
Notification::assertNotNotified(NativeServiceRefusal::CLOSED_INVOICE);

$parts=$new(placeholderComFixtureName('late-parts-placeholder.com'),paid:false);$before=$cash();$sent=$mails();$shut($parts);
ExtensionHelper::addPayment($parts->invoice_id,null,amount:'1.00');$tick();$tick();
same([$plus($before,'1.00'),$sent+1],[$cash(),$mails()],'A partial late payment becomes credit once');
ExtensionHelper::addFailedPayment($parts->invoice_id,null,amount:'1.00');ExtensionHelper::addProcessingPayment($parts->invoice_id,null,amount:'2.00',transactionId:'late-debit');ExtensionHelper::addPayment($parts->invoice_id,null,amount:'0.50');$tick();
same([$plus($before,'1.50'),$sent+2],[$cash(),$mails()],'A declined card or a debit in flight is not money yet');
$rest=PricingRule::decimal(PricingRule::minor($parts->amount)-350);ExtensionHelper::addPayment($parts->invoice_id,null,amount:'2.00',transactionId:'late-debit');ExtensionHelper::addPayment($parts->invoice_id,null,amount:$rest);$tick();$tick();
same([$plus($before,$parts->amount),$sent+3],[$cash(),$mails()],'Later payments credit only themselves');
same(['cancelled','cancelled',null],$closed($parts),'The payment that clears the balance starts nothing');

// Real checkout: two names and hosting on one invoice, so the stale form pays the old total while the rest sits on a new invoice.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('late-mixed-one-placeholder.com'));commerceAdd($cart,$product,placeholderComFixtureName('late-mixed-two-placeholder.com'));
$hosting=commerceHosting();$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'config_options'=>[],'checkout_config'=>[],'quantity'=>1]);
commerceCheckout($user,$cart);$before=$cash();$bill=Invoice::findOrFail(Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain','late-mixed-one-placeholder.com')->sole()->operations()->sole()->invoice_id);$total=(string)$bill->total;
$moved=$retirement->closeUnpaid((int)$bill->id,PayCheck::notice('expired'))['invoice'];$service=DB::table('invoice_items')->where('invoice_id',$moved)->value('reference_id');
ExtensionHelper::addPayment($bill->id,null,amount:$total);$tick();$tick();
same($plus($before,$total),$cash(),'The old total becomes credit once, however many names it held');
same(['cancelled','pending','pending'],[Invoice::whereKey($bill->id)->value('status'),Invoice::whereKey($moved)->value('status'),Service::whereKey($service)->value('status')],'The hosting waits on its own invoice and is not started by the old one');

$refused=$new(placeholderComFixtureName('late-refused-placeholder.com'),paid:false);$before=$cash();$shut($refused);
DB::table('domains_operations')->where('id',$refused->id)->update(['started_at'=>$clock->now()]);
ExtensionHelper::addPayment($refused->invoice_id,null,amount:$refused->amount);$tick();
same([$before,1],[$cash(),$alerts($refused)],'A request that may have started stops the credit and staff are told');

// Rows Paid before the invoice guard: core already started the services, so credit on top would pay twice. A person decides.
$stale=$new(placeholderComFixtureName('late-stale-paid-placeholder.com'),paid:false);$before=$cash();$sent=$mails();$shut($stale);ExtensionHelper::addPayment($stale->invoice_id,null,amount:$stale->amount);
DB::table('invoices')->where('id',$stale->invoice_id)->update(['status'=>'paid']);DB::table('services')->where('id',$stale->service_id)->update(['status'=>'active']);$tick();
same([$before,$sent,1],[$cash(),$mails(),$alerts($stale)],'An invoice already Paid gets no credit and staff are told');

// Real checkout across two accounts: both sweeps see the one payment, which credits once.
$other=$tlds[1];$binding=CatalogueAcceptances::binding(Account::where('server_id',$other->server_id)->sole());$kept=CatalogueAcceptances::load($binding);
$net=Tld::create(['server_id'=>$other->server_id,'registrar_key'=>$other->registrar_key,'account_fingerprint'=>$other->account_fingerprint,'tld'=>'net','enabled'=>true,'min_years'=>1,'max_years'=>1]);
$accepted=CatalogueAcceptances::save($binding,$kept->revision,$kept->endings+['net'=>['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true]]);
$rates=array_map(fn($a)=>new CatalogueRate($a,1,'6.00','synthetic',$clock->now(),$clock->now()->modify('+1 day')),['register','renew','transfer']);
$prices->import(new CatalogueEntry($binding,'net',$rates,[1],true,true),$accepted,static fn()=>null,$clock->now());
$native->publish($net->id,$clock->now());$sellers->initialize('net');$sellers->change('net',1,$other->server_id,true,true,$clock->now());
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('late-accounts-placeholder.com'));commerceAdd($cart,$native->product($net->fresh(),'register'),'late-accounts-placeholder.net');commerceCheckout($user,$cart);
$pair=Paymenter\Extensions\Servers\Domains\Models\Operation::whereIn('domain_id',Paymenter\Extensions\Servers\Domains\Models\Domain::whereIn('domain',['late-accounts-placeholder.com','late-accounts-placeholder.net'])->pluck('id'))->orderBy('id')->get();
same(2,$pair->pluck('server_id')->unique()->count(),'One invoice, two accounts');$bill=Invoice::findOrFail($pair[0]->invoice_id);$total=(string)$bill->total;$before=$cash();$sent=$mails();
$retirement->closeUnpaid((int)$bill->id,PayCheck::notice('expired'));ExtensionHelper::addPayment($bill->id,null,amount:$total);$tick();$tick();
foreach($pair as $op){$retirement->inspect($op->domain_id);}
same([$plus($before,$total),$sent+1,0,0],[$cash(),$mails(),$alerts($pair[0]),$alerts($pair[1])],'One payment becomes one whole credit and one mail, and nothing for staff');

// Staff marking a closed invoice Paid, or recording a payment on it, are told why it stays closed. Other invoices stay quiet.
staffPanel();$billing=staffUser(['admin.invoices.view','admin.invoices.viewAny','admin.invoices.update','admin.invoice_transactions.viewAny','admin.invoice_transactions.create']);
Livewire::actingAs($billing)->test(EditInvoice::class,['record'=>$late->invoice_id])->fillForm(['status'=>'paid'])->call('save');
same('cancelled',Invoice::whereKey($late->invoice_id)->value('status'),'Staff Paid does not reopen a closed order');Notification::assertNotified(NativeServiceRefusal::CLOSED_INVOICE);
session()->forget('filament.notifications');
Livewire::actingAs($billing)->test(TransactionsRelationManager::class,['ownerRecord'=>Invoice::findOrFail($late->invoice_id),'pageClass'=>EditInvoice::class])->callTableAction('create',data:['amount'=>'0.10','fee'=>'0.00']);
same('cancelled',Invoice::whereKey($late->invoice_id)->value('status'),'A payment staff record stays on the closed path');Notification::assertNotified(NativeServiceRefusal::CLOSED_INVOICE);

// Funding is encrypted, so 100 settled orders cannot be skipped in SQL: the cursor still reaches an order behind them, then wraps for a new payment on an old one.
$refunds=app(Refunds::class);$account=Account::where('server_id',$serverId)->sole();$tick();$model=(array)DB::table('domains_operations')->find($late->id);$invoiceRow=(array)DB::table('invoices')->find($late->invoice_id);
for($i=0;$i<110;$i++){
    $invoiceId=DB::table('invoices')->insertGetId([...array_diff_key($invoiceRow,['id'=>1]),'number'=>null]);
    $paidId=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$invoiceId,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
    DB::table('domains_operations')->insert([...array_diff_key($model,['id'=>1]),'operation_key'=>'settled-'.$i,'invoice_id'=>$invoiceId,'invoice_item_id'=>null,'funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['late_credits'=>[['payments'=>[['id'=>$paidId,'amount_minor'=>100]]]]]))]);
}
$behind=$new(placeholderComFixtureName('late-behind-placeholder.com'),paid:false);$shut($behind);ExtensionHelper::addPayment($behind->invoice_id,null,amount:$behind->amount);ExtensionHelper::addPayment($parts->invoice_id,null,amount:'0.25');$before=$cash();
foreach(range(1,3) as $pass){$refunds->creditRetired($account);}
same($plus($before,$behind->amount,'0.25'),$cash(),'Every order gets its turn behind 100 settled ones');
echo "Unpaid hold, refused orders and late money passed.\n";$nativeSuccessful=true;
