<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native app, no network.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Invoice,ServiceCancellation};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB,Route,Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarBalances as Page;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Capabilities,Disposition,DriverResult,Environment,Evidence,Money,RequestBudget,RequestContext,RequestOrigin,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Price};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,BalanceForecast,BalanceReads,BalanceSchema,CatalogueAcceptances,ObservationSchema,RegistrarBalances,RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
extract(commerceFixture(true));$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
app()->instance(AccountBindings::class,$bindings);BalanceSchema::install();ObservationSchema::install();
$a=Account::where('server_id',$tlds[0]->server_id)->sole();$b=Account::where('server_id',$tlds[1]->server_id)->sole();
$binding=CatalogueAcceptances::binding($a);$other=CatalogueAcceptances::binding($b);
$row=static fn($a)=>DB::table(BalanceSchema::TABLE)->where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->first();
$forecast=static fn()=>BalanceForecast::for($a,$clock->now());
$user=commerceUser();$product=$native->product($tlds[0],'register');
$order=App\Models\Order::create(['user_id'=>$user->id,'currency_code'=>'USD']);
$seed=App\Models\Service::create(['user_id'=>$user->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$native->annualPlan($product)->id,
    'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'active']);
$make=static function(string $name,?string $expiry='2026-11-20',?Account $account=null)use($seed,$a):Domain {
    $account??=$a;$s=$seed->replicate();$s->status='active';$s->save();
    return Domain::create(['domain'=>$name,'sld'=>substr($name,0,-4),'tld'=>'com','user_id'=>$s->user_id,'service_id'=>$s->id,
        'server_id'=>$account->server_id,'registrar_key'=>$account->registrar_key,'account_fingerprint'=>$account->account_fingerprint,
        'status'=>'active','provider_expires_at'=>$expiry,'renewal_policy'=>[]]);
};
$renew=static function(Domain $d,string $state='awaiting_payment',array $extra=[])use($user):Operation {
    $invoice=Invoice::create(['user_id'=>$user->id,'status'=>'pending','currency_code'=>'USD','due_at'=>'2027-05-01']);
    return Operation::create([...$d->only(['server_id','registrar_key','account_fingerprint','service_id','authority_generation']),
        'domain_id'=>$d->id,'operation_key'=>'balance-renew-'.$d->id,'action'=>'renew','term'=>1,'currency'=>'USD','amount'=>'10.00',
        'invoice_id'=>$invoice->id,'funding_basis'=>'paid_invoice','state'=>$state,'base_expires_at'=>$d->provider_expires_at,
        'payload'=>[],'policy'=>[],'attempt'=>[],'funding'=>[],...$extra]);
};
$d=$make('balance-window.com');
same('8.000000',$forecast()['total'],'Paymenter due in 26 days counts despite expiry in 33');
same(1,$forecast()['count'],'One forecast holding');
foreach ([['2026-11-15','2026-12-15',true],['2026-12-15','2026-11-15',true],
    ['2026-11-15',null,true],[null,'2026-11-15',true],[null,null,null],
    ['2026-11-18',null,false],['2026-11-17',null,true],['2026-10-17','2026-11-15',true]] as [$due,$expiry,$expected]) {
    same($expected,BalanceForecast::withinWindow($due,$expiry,$clock->now()),'Earlier known date determines inclusive UTC30-day window');
}
BalanceReads::record($binding,new Money('8.00','USD'),$clock->now(),'preflight');
same('OK',RegistrarBalances::facts($a,$clock->now())['status'],'Equal balance is OK');
BalanceReads::record($binding,new Money('7.99','USD'),$clock->now(),'renewal');
same('Low for upcoming renewals',RegistrarBalances::facts($a,$clock->now())['status'],'One cent short is Low');
BalanceReads::record($other,new Money('777.00','USD'),$clock->now(),'manual');
same('7.99',$row($a)->amount,'Two servers isolated');same('777.00',$row($b)->amount,'Other server unchanged');
expect(BalanceForecast::compare('999999999999999999.000001','999999999999999999.000002')<0,'Six decimal digits at maximum magnitude stay exact');
$foreign=new AccountBinding('enom',$binding->serverId,Environment::Test,'PRIVATE-OTHER-LOGIN');
DB::table(BalanceSchema::TABLE)->insert(['server_id'=>$binding->serverId,'account_fingerprint'=>$foreign->fingerprint,'amount'=>'123.45','currency'=>'USD']);
BalanceReads::record($foreign,new Money('999.00','USD'),$clock->now(),'manual');
same('123.45',DB::table(BalanceSchema::TABLE)->where('account_fingerprint',$foreign->fingerprint)->value('amount'),'Other fingerprint on same server never overwritten');
same('7.99',$row($a)->amount,'Same server account isolation');
BalanceReads::record($binding,null,$clock->now(),'manual','PRIVATE-PROVIDER-REPLY');
same('7.99',$row($a)->amount,'Failure retains last value');same('read_failed',$row($a)->last_error_code,'Failure category allowlist');
same('Unknown',RegistrarBalances::facts($a,$clock->now())['status'],'Failure status unknown');
$failed=new DriverResult($binding,Disposition::Unknown,SendState::PossiblySent,ResultReason::Unverified);
$failedReader=new FakeRegistrar($binding,new Capabilities($binding,[]),$failed);
$context=new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+25 seconds'),new RequestBudget(1),$clock);
expect(BalanceReads::read($failedReader,$context,'preflight')===$failed,'Recording preserves the exact failed driver result for existing guards');
same(['balance'],$failedReader->calls,'Failed balance result is read exactly once');same('7.99',$row($a)->amount,'Failed provider result retains the last balance');
BalanceReads::record($binding,new Money('8.00','USD'),$clock->now()->modify('-1 second'),'manual');
same('read_failed',$row($a)->last_error_code,'Old completion cannot replace newer failure');
$rate=Price::where('server_id',$a->server_id)->where('action','renew')->sole();$saved=$rate->wholesale;
$rate->update(['wholesale'=>null,'revision'=>$rate->revision+1]);same(1,$forecast()['unknown'],'Unknown cost is counted');
expect(str_contains(RegistrarBalances::facts($a,$clock->now())['status'],'Upcoming renewals unknown'),'Status includes unknown cost');$rate->update(['wholesale'=>$saved,'revision'=>$rate->revision+1]);
$d->update(['billing_managed'=>false]);
$overdue=$make('balance-overdue.com','2026-10-20');
same('8.000000',$forecast()['total'],'Past Paymenter due date and future expiry without open invoice counts as due now');
$overdue->update(['billing_managed'=>false]);
$undated=$make('balance-undated.com',null);same(1,$forecast()['unknown'],'Neither date is unknown, not zero');$dueOnly=$renew($undated);same(0,$forecast()['unknown'],'An open obligation with only its invoice due date is known');
DB::table('invoices')->where('id',$dueOnly->invoice_id)->update(['due_at'=>null]);same(1,$forecast()['unknown'],'An open obligation with neither date is unknown');
$undated->update(['billing_managed'=>false]);
$server=App\Models\Server::find($a->server_id);$server->settings()->create(['key'=>'domains_due_days','value'=>'invalid']);
$d->update(['billing_managed'=>true,'provider_expires_at'=>'2026-11-15']);same('8.000000',$forecast()['total'],'Only confirmed expiry remains known with invalid calendar');
$server->settings()->where('key','domains_due_days')->delete();$d->update(['billing_managed'=>false]);
$far=$make('balance-open.com','2027-06-01');$op=$renew($far,'held',['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent']]);
same('8.000000',$forecast()['total'],'Open unsent obligation outside horizon counts');
$op->update(['attempt'=>['seal'=>'sealed','dispatch_attempted_at'=>$clock->now()->format(DATE_ATOM)]]);
same(0,$forecast()['count'],'Sent obligation excluded');
$far->update(['billing_managed'=>false]);$proven=$make('balance-prewire.com','2027-06-01');
$proof=['safe_code'=>'renewal_definitely_unsent','lease_expires_at'=>null,'started_at'=>$clock->now(),'request_fingerprint'=>str_repeat('a',64),
    'attempt'=>['claim_key'=>str_repeat('b',32),'seal'=>'sealed','dispatch_attempted_at'=>$clock->now()->format(DATE_ATOM),
        'send_state'=>'possibly_sent','authorized_cost'=>'8.00','renewal_mode'=>'ordinary',
        'execution'=>['disposition'=>'rejected','send_state'=>'definitely_unsent','reason'=>'unsent','discrepancies'=>[]]],
    'funding'=>['claim'=>['amount'=>'10.00'],'seal'=>['amount'=>'10.00']],'cost'=>['estimated'=>'8.00','currency'=>'USD','unit'=>'term_total']];
$renew($proven,'held',$proof);
same('8.000000',$forecast()['total'],'Validated sealed prewire refusal remains open outside horizon');
$proven->update(['billing_managed'=>false]);$unproven=$make('balance-unproven.com','2027-06-01');
$proof['attempt']['execution']['discrepancies']=['unknown'];$renew($unproven,'held',$proof);
same(0,$forecast()['count'],'Unvalidated sealed refusal cannot borrow definitely-unsent proof');
$unproven->update(['billing_managed'=>false]);
$stopped=$make('balance-stopped.com');$stopped->update(['renewal_policy'=>['renewals_stopped'=>true]]);
same(0,$forecast()['count'],'Stopped holding excludes unissued renewals');$issued=$renew($stopped,'held');
same(1,$forecast()['count'],'Stopped holding counts its issued unsent obligation exactly once');same('8.000000',$forecast()['total'],'Issued obligation retains its expected spending');
DB::table('invoices')->where('id',$issued->invoice_id)->update(['status'=>'cancelled']);
same(0,$forecast()['count'],'Cancelled invoice cannot spend even while its issued operation remains held on a stopped holding');
$stopped->update(['renewal_policy'=>[]]);same(0,$forecast()['count'],'Cancelled invoice cycle cannot be counted again as an unissued renewal');
$stopped->update(['renewal_policy'=>['renewals_stopped'=>true]]);
$issued->update(['state'=>'succeeded']);$stopped->update(['provider_expires_at'=>'2027-11-20']);
same(0,BalanceForecast::for($a,$clock->now()->modify('+1 year'))['count'],'Next cycle within its next-year window is not forecast on stopped holding');$stopped->update(['billing_managed'=>false]);
foreach(['pending','uncertain','succeeded','rejected'] as $state){$h=$make('balance-'.$state.'.com','2026-11-01');$renew($h,$state);same(0,$forecast()['count'],'Sent/completed/retired cycle excluded');$h->update(['billing_managed'=>false]);}
$off=$make('balance-off.com');$renew($off);$cancel=ServiceCancellation::create(['service_id'=>$off->service_id,'type'=>'end_of_period','reason'=>'Synthetic']);
same(1,$forecast()['count'],'Native end-of-period stop preserves issued obligation');
$cancel->update(['type'=>'immediate']);same(0,$forecast()['count'],'Immediate cancellation excludes unsendable issued obligation');$cancel->delete();
$off->update(['status'=>'retired']);same(0,$forecast()['count'],'Retired holding excluded');
$off->update(['status'=>'active']);$off->service->update(['status'=>'cancelled']);same(0,$forecast()['count'],'Cancelled service excluded');
$otherDomain=$make('balance-other.com','2026-11-01',$b);same(0,$forecast()['count'],'Forecast respects second server');$otherDomain->update(['billing_managed'=>false]);
// Daily and manual reads use the same provider-login limiter, without requiring write activation.
$readState=(object)['calls'=>0,'fail'=>false];$registry=new RegistrarRegistry;
$verify=new class implements AccountVerifier {public function verify(Environment $e,array $c):string{return $c['token'];}};
foreach(['enom','namesilo'] as $key){$registry->register($key,['token'],$verify,static function($binding,$credentials)use($readState):FakeRegistrar {
    same(0,DB::transactionLevel(),'Balance read outside transaction');$readState->calls++;
    if($readState->fail){throw new RuntimeException('PRIVATE-PROVIDER-REPLY');}
    return new FakeRegistrar($binding,new Capabilities($binding,[]),new DriverResult($binding,Disposition::Accepted,SendState::Sent,ResultReason::Acknowledged,new Evidence(balance:new Money('6.00','USD'))));
});}
$manualBindings=new AccountBindings($registry);$limiter=new AccountReadBudget($clock);$balances=new RegistrarBalances($manualBindings,$clock,$limiter);
app()->instance(Clock::class,$clock);app()->instance(RegistrarBalances::class,$balances);
staffPanel();$panel=Filament::getCurrentPanel();$panel->pages([Page::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>Page::registerRoutes($panel));app('router')->getRoutes()->refreshNameLookups();
$staff=staffUser([Staff::VIEW]);$denied=staffUser([]);Auth::login($denied);
try{$balances->refresh($a->server_id);expect(false,'Refresh must reject missing permission');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Permission refusal');}
same(0,$readState->calls,'Refused refresh never resolves reader');Livewire::actingAs($denied)->test(Page::class)->assertForbidden();
Auth::login($staff);same('refreshed',$balances->refresh($a->server_id),'Permitted manual read');same('manual',$row($a)->source,'Manual source');
same('rate_limited',$balances->refresh($a->server_id),'60-second refresh cooldown');same(1,$readState->calls,'Cooldown adds no provider read');
$clock->instant=$clock->now()->modify('+60 seconds');same('refreshed',$balances->refresh($a->server_id),'Exact60-second boundary permits refresh');
$lease=$limiter->acquire($binding,RequestOrigin::Interactive);$calls=$readState->calls;$balances->daily($a);same($calls+1,$readState->calls,'Daily resolves one reader');
same(null,$row($a)->last_error_code,'Local quota refusal preserves last successful read');same('manual',$row($a)->source,'Deferral records no provider result');
same($clock->now()->getTimestamp()+60,(int)$row($a)->daily_due_at,'Deferred daily read retries after one minute');$limiter->release($binding,RequestOrigin::Interactive,$lease);
$calls=$readState->calls;$balances->daily($a);same($calls,$readState->calls,'Deferred daily read avoids immediate retries');
$clock->instant=$clock->now()->modify('+60 seconds');$balances->daily($a);same('daily',$row($a)->source,'Daily source');same(null,$row($a)->last_error_code,'Daily read success');
$calls=$readState->calls;$balances->daily($a);same($calls,$readState->calls,'Successful daily read not retried within24 hours');
$clock->instant=$clock->now()->modify('+60 seconds');$readState->fail=true;same('configuration_unavailable',$balances->refresh($a->server_id),'Reader configuration failure is safe');
same('6.00',$row($a)->amount,'Daily value retained on refresh failure');$readState->fail=false;
// Render actual Filament page; neither credentials, fingerprints nor provider error text reach HTML or Livewire state.
$render=Livewire::actingAs($staff)->test(Page::class)->assertSee('Registrar balances')->assertSee('Add funds')->assertSee('Refresh now')->assertSee('Unknown');
$html=$render->html();foreach(['https://cp.enom.com/','https://www.namesilo.com/cart/add-funds','noopener noreferrer','account #'.$a->server_id] as $needle){expect(str_contains($html,$needle),'Rendered account structure and official external links');}
foreach(['PRIVATE-PROVIDER-REPLY','account-enom','account-namesilo',$a->account_fingerprint,$foreign->fingerprint,'domains_credentials'] as $secret){expect(!str_contains($html,$secret),'HTML and state redact private evidence');}
// A panel flag alone cannot make Paymenter's differently shaped native inbox compatible.
expect(Schema::hasTable('notifications'),'Native notification storage exists');$panel->databaseNotifications();
$d->update(['billing_managed'=>true,'provider_expires_at'=>'2026-11-01']);
$rate->update(['revision'=>$rate->revision+1,'observed_at'=>$clock->now(),'fresh_until'=>$clock->now()->modify('+3 days')]);
$clock->instant=$clock->now()->modify('+1 day');$balances->daily($a);
same(0,DB::table('notifications')->count(),'Incompatible native inbox safely keeps dashboard-only status');
same('Low for upcoming renewals',RegistrarBalances::facts($a,$clock->now())['status'],'Dashboard warning survives unavailable inbox');
$role=$staff->role;$role->update(['permissions'=>[]]);try{$balances->refresh($a->server_id);expect(false,'Permission revoked between renders must refuse');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'Current permission reloaded');}
BalanceSchema::install();same('6.00',$row($a)->amount,'Schema reinstall retains balance');
$migration=require dirname(__DIR__).'/Domains/database/migrations/2026_10_19_000015_create_registrar_balances.php';$migration->down();expect(Schema::hasTable(BalanceSchema::TABLE),'Rollback retains ledger');
echo "Registrar balances, forecast, exact money, permissions, daily spacing and Filament rendering passed\n";$nativeSuccessful=true;
