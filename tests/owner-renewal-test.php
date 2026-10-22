<?php
// Native owner renewal request; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/owner-pages.php';
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, RuntimeIneligible, RuntimeReason};
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();
$billing=new DomainBilling($bindings,$guard,$clock);Queue::fake();
$controller=new OwnerRenewalController($owners,$billing);$session=app('request')->session();$other=commerceUser();
[$source]=$prepare('owner-renew.com');same('succeeded',$reconcile->run($source->id)->state,'Owner registration confirmed');
[$lateSource]=$prepare('owner-renew-late.com');same('succeeded',$reconcile->run($lateSource->id)->state,'Late registration confirmed');
$d=$source->domain()->firstOrFail();$late=$lateSource->domain()->firstOrFail();$server=$product->server;Queue::fake();storefrontViews();
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
$page=static function($domain)use($user,$session,$owners):string {
    $r=Request::create('/domains/'.$domain->id,'GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
    return (new OwnedDomainController($owners))->show($r,$domain->id)->render();
};
$ledger=static fn()=>serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),Operation::where('action','renew')->get()->map->getRawOriginal()->all()]);
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');

// Off: no form, no key in use, and a direct POST refuses with plain copy and no debt.
$setting('domains_manual_renewals_enabled',false);$setting('domains_billing_enabled',true);
same([],$owners->present($d->fresh())->renewNow,'Manual renewals off hide the request');
$html=$page($d);expect(!str_contains($html,'Renew now')&&!str_contains($html,route('domains.domains.renew',$d->id)),'Off page shows no renewal form');
$before=$ledger();$refused($d,['years'=>'1'],'Online renewal is not available right now. Contact support to renew this domain.');
same($before,$ledger(),'Off request creates no invoice');

// On: form offers only terms inside the ten-year registry horizon.
$setting('domains_manual_renewals_enabled',true);$dto=$owners->present($d->fresh());
same(['url'=>route('domains.domains.renew',$d->id),'terms'=>range(1,9),'order_by'=>'2027-10-10'],$dto->renewNow,'Request offers horizon-bounded terms until the day before due');
$html=$page($d);
foreach(['Renew now','Add years to this domain before it expires. We create an invoice now, and the renewal starts after it is paid.','Renew for','<option value="9">9 years</option>','<option value="1">1 year</option>',
    'You can renew online until <time datetime="2027-10-10">Oct 10, 2027</time>. The invoice shows the total before you pay.','Create renewal invoice','action="'.route('domains.domains.renew',$d->id).'"','name="_request_key"'] as $text){expect(str_contains($html,$text),'Owner form shows '.$text);}
expect(!str_contains($html,'<option value="10">'),'Term beyond the horizon is not offered');
same(range(1,10),DomainBilling::terms(new DateTimeImmutable('2027-10-18'),new DateTimeImmutable('2027-10-18T00:00:00Z')),'Horizon admits ten years from expiry day');
same([1,2],DomainBilling::terms(new DateTimeImmutable('2035-08-01'),new DateTimeImmutable('2027-09-01T12:00:00Z')),'Far expiry narrows the term list');
same([],DomainBilling::terms(new DateTimeImmutable('2037-10-18'),new DateTimeImmutable('2027-09-01T12:00:00Z')),'Expiry past the horizon offers no term');

// Term bounds and malformed input refuse before or at billing; none creates debt.
$before=$ledger();
foreach(['0','01','abc','',' 2','2.0','-1','100'] as $years){$refused($d,['years'=>$years],'Choose how many years to renew for.');}
$refused($d,[],'Choose how many years to renew for.');$refused($d,['years'=>['2']],'Choose how many years to renew for.');
foreach(['10','11','99'] as $years){$refused($d,['years'=>$years],'Domains can be registered for at most 10 years ahead. Choose a shorter term.');}
same($before,$ledger(),'Refused terms create no invoice');

// Foreign owner, wrong intent and another domain's key are refused before billing.
try{$controller->renew($req($d,['years'=>'1'],$other),$d->id);expect(false,'Foreign owner refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
$replay(fn()=>$controller->renew($req($d,['years'=>'1'],null,'nameservers'),$d->id));
$wrong=$req($late,['years'=>'1']);$replay(fn()=>$controller->renew($wrong,$d->id));
same($before,$ledger(),'Foreign and mismatched intents create no invoice');

// Processing off is an internal refusal: generic copy, no reason code.
$bindings->setProcessing($server->id,false);$refused($d,['years'=>'2'],OwnerRenewalController::UNAVAILABLE);$bindings->setProcessing($server->id,true);
same($before,$ledger(),'Paused processing creates no invoice');

// Success sends the owner to the invoice; duplicates return the same invoice.
$first=$req($d,['years'=>'2']);$stale=clone $first;$response=$controller->renew($first,$d->id);
$op=Operation::where('domain_id',$d->id)->where('action','renew')->sole();$invoice=Invoice::findOrFail($op->invoice_id);
same(route('invoices.show',$invoice),$response->getTargetUrl(),'Owner lands on the renewal invoice');
same([2,'awaiting_payment','2027-10-11',(int)$user->id],[(int)$op->term,$op->state,$invoice->due_at->format('Y-m-d'),(int)$invoice->user_id],'Requested term, due date and owner recorded');
same('Renew owner-renew.com: Oct 18, 2027 - Oct 18, 2029',$invoice->items->sole()->description,'Invoice names the requested term');
$replay(fn()=>$controller->renew($stale,$d->id));$issued=$ledger();
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'2']),$d->id)->getTargetUrl(),'Fresh duplicate returns the same invoice');
same($issued,$ledger(),'Duplicate submit creates no second invoice');
$refused($d,['years'=>'3'],'This domain already has a renewal invoice for a different term. Pay that invoice, or contact support to change it.');
same($issued,$ledger(),'Different term on an issued cycle creates no invoice');
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Issued cycle links its invoice instead of a form');
$html=$page($d);expect(str_contains($html,'View renewal invoice')&&str_contains($html,'href="'.route('invoices.show',$invoice).'"')&&!str_contains($html,'Create renewal invoice'),'Issued page links the invoice');
$setting('domains_manual_renewals_enabled',false);
same([],$owners->present($d->fresh())->renewNow,'Off hides the issued-invoice link too');
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'2']),$d->id)->getTargetUrl(),'Turning requests off still returns the issued invoice');
same($issued,$ledger(),'Off duplicate creates nothing');$setting('domains_manual_renewals_enabled',true);

// Outside the calendar: hidden from the due date and refused with plain copy.
$clock->instant=new DateTimeImmutable('2027-10-10T23:00:00Z');expect($owners->present($late->fresh())->renewNow!==[],'Last request day still offers renewal');
$clock->instant=new DateTimeImmutable('2027-10-11T00:00:00Z');same([],$owners->present($late->fresh())->renewNow,'Due date hides the request');
$refused($late,['years'=>'1'],'This domain is too close to its expiry date to renew online. Contact support to renew it.');
same($issued,$ledger(),'Outside calendar creates no invoice');
same(1,Invoice::whereIn('id',Operation::where('action','renew')->pluck('invoice_id'))->count(),'Exactly one renewal invoice issued');

// Owner copy for every billing refusal hides reasons and internal messages.
foreach([[new RuntimeIneligible(RuntimeReason::SpendingPaused),OwnerRenewalController::UNAVAILABLE],[new RuntimeIneligible(RuntimeReason::LifecycleNotReady),OwnerRenewalController::UNAVAILABLE],
    [new RuntimeIneligible(RuntimeReason::MissingAnchor),OwnerRenewalController::UNAVAILABLE],[new DomainException('Domain renewal is unavailable.'),OwnerRenewalController::UNAVAILABLE],
    [new DomainException('Renewal is off for this adopted registration.'),'Renewal is turned off for this domain. Contact support if you want to renew it.'],
    [new RuntimeException('Renewal term exceeds the registry horizon.'),OwnerRenewalController::UNAVAILABLE]] as [$error,$copy]){same($copy,OwnerRenewalController::refusal($error),'Owner copy for '.$error->getMessage());}
foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
echo "Owner renewal off, terms, foreign, replay, success, duplicate, issued link and calendar refusals passed.\n";$nativeSuccessful=true;
