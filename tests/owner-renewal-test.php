<?php
// Native owner renewal request; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/owner-pages.php';
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, OwnedDomains, RenewalCycles, RuntimeIneligible, RuntimeReason};
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();
$billing=new DomainBilling($bindings,$guard,$clock);Queue::fake();
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

// Off: no form, no key in use, and a direct POST refuses with plain copy and no debt.
$setting('domains_manual_renewals_enabled',false);$setting('domains_billing_enabled',true);
same([],$owners->present($d->fresh())->renewNow,'Manual renewals off hide the request');
$html=$page($d);expect(!str_contains($html,'Renew now')&&!str_contains($html,route('domains.domains.renew',$d->id)),'Off page shows no renewal form');
$before=$ledger();$refused($d,['years'=>'1'],'You can’t renew online right now. Contact support to renew this domain.');
same($before,$ledger(),'Off request creates no invoice');

// On: form offers only terms inside the ten-year registry horizon.
$setting('domains_manual_renewals_enabled',true);$dto=$owners->present($d->fresh());
same(['url'=>route('domains.domains.renew',$d->id),'terms'=>range(1,9),'order_by'=>'2027-10-17'],$dto->renewNow,'Request offers horizon-bounded terms until the day before expiry');
$html=$page($d);
foreach(['Renew now','>Renew '.$d->domain.'<','>Years<','<option value="9">9 years</option>','<option value="1">1 year</option>',
    'You can renew online until <time datetime="2027-10-17">Oct 17, 2027</time>.','Create invoice','action="'.route('domains.domains.renew',$d->id).'"','name="_request_key"'] as $text){expect(str_contains($html,$text),'Owner form shows '.$text);}
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
$bindings->setProcessing($server->id,false);
same([],$owners->present($d->fresh())->renewNow,'Processing off hides the request');
expect(!str_contains($page($d),'Create invoice'),'Processing off page has no request');$refused($d,['years'=>'2'],OwnerRenewalController::UNAVAILABLE);$bindings->setProcessing($server->id, true, syntheticActivation());
same($before,$ledger(),'Paused processing creates no invoice');
// Raw rows retain invalid authority that normal model guards would refuse.
$policy=$d->fresh()->renewal_policy;
foreach (['spending','anchor','prior','price','service','domain'] as $case) {
    $rawDomain=$d->fresh()->getRawOriginal();$rawSource=$source->fresh()->getRawOriginal();$rawService=$d->service->fresh()->getRawOriginal();
    if($case==='spending'){$d->update(['renewal_policy'=>[...$policy,'automatic_spend_paused'=>true]]);}
    if($case==='anchor'){$d->update(['renewal_policy'=>[...$policy,'billing_anchor'=>[]]]);}
    $prior=null;
    if($case==='prior'){
        $prior=Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,
            'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,
            'operation_key'=>'owner-unresolved-nameservers','action'=>'nameservers','term'=>1,'state'=>'uncertain','currency'=>'USD','amount'=>'0.00',
            'attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);
        same($rawSource,$source->fresh()->getRawOriginal(),'Separate prior outcome keeps completed anchor valid');
    }
    if($case==='price'){DB::table('domains_domains')->where('id',$d->id)->update(['price_mode'=>'complimentary']);}
    if($case==='service'){$d->service->fresh()->update(['status'=>'suspended']);}
    if($case==='domain'){$d->update(['status'=>'needs_review']);}
    $unchanged=$ledger();same([],$owners->present($d->fresh())->renewNow,'Ineligible request hidden: '.$case);
    expect(!str_contains($page($d),'Create invoice'),'Ineligible page has no request: '.$case);
    $refused($d,['years'=>'1'],OwnerRenewalController::UNAVAILABLE);same($unchanged,$ledger(),'Ineligible page and POST preserve debt: '.$case);
    DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);
    if($prior){DB::table('domains_operations')->where('id',$prior->id)->delete();}
    DB::table('services')->where('id',$d->service_id)->update($rawService);
}

// Success sends the owner to the invoice; duplicates return the same invoice.
$first=$req($d,['years'=>'2']);$stale=clone $first;$response=$controller->renew($first,$d->id);
$op=Operation::where('domain_id',$d->id)->where('action','renew')->sole();$invoice=Invoice::findOrFail($op->invoice_id);
same(route('invoices.show',$invoice),$response->getTargetUrl(),'Owner lands on the renewal invoice');
same([2,'awaiting_payment','2027-10-11',(int)$user->id],[(int)$op->term,$op->state,$invoice->due_at->format('Y-m-d'),(int)$invoice->user_id],'Requested term, due date and owner recorded');
same('Domain renewal: owner-renew-placeholder.com (2 years, Oct 18, 2027 to Oct 18, 2029)',$invoice->items->sole()->description,'Invoice names the requested term');
$replay(fn()=>$controller->renew($stale,$d->id));$issued=$ledger();
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'2']),$d->id)->getTargetUrl(),'Fresh duplicate returns the same invoice');
same($issued,$ledger(),'Duplicate submit creates no second invoice');
$refused($d,['years'=>'3'],'This domain already has a renewal invoice for a different term. Pay that invoice, or contact support to change it.');
same($issued,$ledger(),'Different term on an issued cycle creates no invoice');
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Issued cycle links its invoice instead of a form');
$html=$page($d);expect(str_contains($html,'Pay invoice')&&str_contains($html,'href="'.route('invoices.show',$invoice).'"')&&!str_contains($html,'Create invoice'),'Issued page links the invoice');
expect(str_contains($html,'Early renewal not paid. Nothing is due until Oct 11, 2027.')&&!str_contains($html,'Due Oct 11, 2027'),'Customer reads the same early renewal words as staff');
$setting('domains_manual_renewals_enabled',false);
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Off retains the issued-invoice link');
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'2']),$d->id)->getTargetUrl(),'Turning requests off still returns the issued invoice');
same($issued,$ledger(),'Off duplicate creates nothing');$setting('domains_manual_renewals_enabled',true);

// Retained cycles remain visible under every gate that prevents creating a new invoice.
$cycleStates=['pending'=>['state'=>'awaiting_payment','result'=>['invoice'=>route('invoices.show',$invoice)],'heading'=>'Pay invoice'],
    'paid'=>['state'=>'pending','result'=>['status'=>'paid'],'heading'=>'Updates when the renewal completes'],
    'cancelled'=>['state'=>'awaiting_payment','result'=>['status'=>'cancelled'],'heading'=>'Invoice cancelled'],
    'held'=>['state'=>'held','result'=>['status'=>'review'],'heading'=>'Renewal being checked']];
foreach(['manual','stop','service','domain','cutoff','calendar'] as $gate){
    $rawDomain=$d->fresh()->getRawOriginal();$rawService=$d->service->fresh()->getRawOriginal();$savedTime=$clock->instant;
    if($gate==='manual'){$setting('domains_manual_renewals_enabled',false);}
    if($gate==='stop'){$d->update(['renewal_policy'=>[...$d->renewal_policy,'renewals_stopped'=>true]]);}
    if($gate==='service'){$d->service->fresh()->update(['status'=>'suspended']);}
    if($gate==='domain'){$d->update(['status'=>'needs_review']);}
    if($gate==='cutoff'){$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');}
    if($gate==='calendar'){$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>'invalid','type'=>'string']);}
    foreach($cycleStates as $state=>$expected){
        DB::table('invoices')->where('id',$invoice->id)->update(['status'=>$state==='held'?'pending':$state]);
        DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$expected['state']]);$unchanged=$ledger();
        $expired=$gate==='cutoff'&&in_array($state,['pending','held'],true);
        same($expired?['status'=>'cancelled']:$expected['result'],$owners->present($d->fresh())->renewNow,'Retained '.$state.' survives '.$gate);
        $html=$page($d);same(1,substr_count($html,'>'.($expired?'Invoice cancelled':$expected['heading']).'<'),'One retained heading: '.$state.' '.$gate);
        expect(!str_contains($html,'Create invoice'),'Retained cycle has no new request: '.$state.' '.$gate);
        same($unchanged,$ledger(),'Retained presentation is read-only: '.$state.' '.$gate);
    }
    DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);DB::table('services')->where('id',$d->service_id)->update($rawService);
    $clock->instant=$savedTime;$setting('domains_manual_renewals_enabled',true);$server->settings()->where('key','domains_due_days')->delete();
}
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment']);

// Raw retained rows model inconsistent stored data; guarded model edits remain forbidden.
$rawInvoice=$invoice->fresh()->getRawOriginal();$rawItem=$invoice->items()->sole()->getRawOriginal();$rawOp=$op->fresh()->getRawOriginal();
foreach(['missing_item','membership','currency','amount','due','missing_due','base','missing_base','expected','missing_expected','service','server','account','generation','owner'] as $case){
    if($case==='missing_item'){DB::table('invoice_items')->where('id',$rawItem['id'])->delete();}
    if($case==='membership'){DB::table('invoice_items')->insert([...$rawItem,'id'=>999999]);}
    if($case==='currency'){DB::table('invoices')->where('id',$invoice->id)->update(['currency_code'=>'EUR']);}
    if($case==='amount'){DB::table('invoice_items')->where('id',$rawItem['id'])->update(['price'=>'0.01']);}
    if($case==='due'){DB::table('invoices')->where('id',$invoice->id)->update(['due_at'=>'2027-10-12']);}
    if($case==='missing_due'){DB::table('invoices')->where('id',$invoice->id)->update(['due_at'=>null]);}
    if($case==='missing_base'){DB::table('domains_operations')->where('id',$op->id)->update(['base_expires_at'=>null]);}
    if($case==='missing_expected'){DB::table('domains_operations')->where('id',$op->id)->update(['expected_expires_at'=>null]);}
    if($case==='base'){DB::table('domains_operations')->where('id',$op->id)->update(['base_expires_at'=>'2027-10-19']);}
    if($case==='expected'){DB::table('domains_operations')->where('id',$op->id)->update(['expected_expires_at'=>'2030-10-18']);}
    if(in_array($case,['service','server','account','generation'],true)){
        $field=['service'=>'service_id','server'=>'server_id','account'=>'account_fingerprint','generation'=>'authority_generation'][$case];
        DB::table('domains_operations')->where('id',$op->id)->update([$field=>$case==='account'?str_repeat('0',64):999999]);
    }
    if($case==='owner'){DB::table('invoices')->where('id',$invoice->id)->update(['user_id'=>$other->id]);}
    $unchanged=$ledger();same(['status'=>'review'],$owners->present($d->fresh())->renewNow,'Invalid retained invoice shows review: '.$case);
    $html=$page($d);expect(str_contains($html,'Renewal being checked')&&!str_contains($html,'Pay invoice')&&!str_contains($html,'Create invoice'),'Invalid retained page offers no billing action: '.$case);
    $refused($d,['years'=>'2'],'This renewal is under review. Contact support if you need an update on this renewal.');same($unchanged,$ledger(),'Invalid retained data creates no debt: '.$case);
    DB::table('invoice_items')->where('id',999999)->delete();DB::table('invoice_items')->updateOrInsert(['id'=>$rawItem['id']],$rawItem);
    DB::table('invoices')->where('id',$invoice->id)->update($rawInvoice);DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
}
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Restored retained invoice is payable');
// Expiry alone cannot retire an in-flight payment, collection or registrar operation.
$expiryResults=[];$expiryExpected=[];$savedTime=$clock->instant;
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');
foreach(['processing','auto_pay_uncertain','pending','uncertain','retireable','cancelled_native','cancelled_native_uncertain'] as $case){
    $paymentId=null;
    if($case==='processing'){$paymentId=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$invoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing','created_at'=>now(),'updated_at'=>now()]);}
    if($case==='auto_pay_uncertain'){
        $funding=$op->fresh()->funding;
        same('skipped',$funding['auto_pay']['status'],'Manual renewal starts with definite non-collection');
        $op->funding=[...$funding,'auto_pay'=>[...$funding['auto_pay'],'status'=>'uncertain','reason'=>null]];
        DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>$op->getAttributes()['funding']]);
        same('uncertain',$op->fresh()->funding['auto_pay']['status'],'Uncertain collection evidence is stored');
    }
    if(in_array($case,['pending','uncertain','cancelled_native_uncertain'],true)){DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$case==='cancelled_native_uncertain'?'uncertain':$case]);}
    if(str_starts_with($case,'cancelled_native')){DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);}
    $expected=$case==='retireable'||str_starts_with($case,'cancelled_native')?'cancelled':'review';$unchanged=$ledger();
    foreach([1,2] as $pass){
        $label=$case.':'.$pass;$html=$page($d);
        $expiryExpected[$label]=[['status'=>$expected],$expected==='cancelled'?1:0,$expected==='review'?1:0];
        $expiryResults[$label]=[$bounded(fn()=>$owners->present($d->fresh())->renewNow),substr_count($html,'>Invoice cancelled<'),substr_count($html,'>Renewal being checked<')];
        expect(!str_contains($html,'Pay invoice')&&!str_contains($html,'Create invoice'),'Expired '.$label.' offers no billing action');
        same($unchanged,$ledger(),'Repeated expired '.$label.' presentation preserves all evidence');
    }
    if($paymentId!==null){DB::table('invoice_transactions')->where('id',$paymentId)->delete();}
    DB::table('invoices')->where('id',$invoice->id)->update($rawInvoice);DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
}
$clock->instant=$savedTime;
same(14,count($expiryResults),'Every expired blocker and control is read twice');
same($expiryExpected,$expiryResults,'Expired renewal status requires complete unpaid-retirement proof: '.json_encode($expiryResults,JSON_THROW_ON_ERROR));
foreach(['processing','succeeded','failed'] as $paymentState){
    $id=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$invoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>$paymentState,'created_at'=>now(),'updated_at'=>now()]);
    $unchanged=$ledger();same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Pending invoice remains payable with partial or unsettled payment: '.$paymentState);
    same($unchanged,$ledger(),'Payment presentation preserves evidence: '.$paymentState);DB::table('invoice_transactions')->where('id',$id)->delete();
}
foreach(['queued','running','pending','uncertain'] as $operationState){
    DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$operationState]);
    same(['status'=>'review'],$owners->present($d->fresh())->renewNow,'Pending debt without payment-wait state shows review: '.$operationState);
}
DB::table('domains_operations')->where('id',$op->id)->update($rawOp);
$rawDomain=$d->fresh()->getRawOriginal();DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>null]);
same(['status'=>'review'],$bounded(fn()=>$owners->present($d->fresh())->renewNow),'Retained cycle with missing expiry shows review');DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);
$rawLate=$late->fresh()->getRawOriginal();DB::table('domains_domains')->where('id',$late->id)->update(['provider_expires_at'=>null]);
same([],$bounded(fn()=>$owners->present($late->fresh())->renewNow),'Missing expiry without a renewal has no cycle');DB::table('domains_domains')->where('id',$late->id)->update($rawLate);


// Auto-renew off: billing refuses manual requests for a stopped renewal, so the page offers none; turning it on restores the form.
$policy=$late->fresh()->renewal_policy??[];$late->update(['renewal_policy'=>[...$policy,'renewals_stopped'=>true]]);$dto=$owners->present($late->fresh());
same(['Off',[]],[$dto->renewal,$dto->renewNow],'Auto-renew off hides the request billing refuses');
$html=$page($late);expect(!str_contains($html,'Renew now')&&!str_contains($html,route('domains.domains.renew',$late->id)),'Auto-renew off page shows no renewal form');
$refused($late,['years'=>'1'],OwnerRenewalController::UNAVAILABLE);same($issued,$ledger(),'Auto-renew off request creates no invoice');
$late->update(['renewal_policy'=>$policy]);$dto=$owners->present($late->fresh());
same(['On',range(1,9)],[$dto->renewal,$dto->renewNow['terms']??null],'Auto-renew on offers the request again');

// Outside the calendar: hidden on the expiry date and refused with plain copy.
$clock->instant=new DateTimeImmutable('2027-10-17T23:00:00Z');expect($owners->present($late->fresh())->renewNow!==[],'Last request day still offers renewal');
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');same([],$owners->present($late->fresh())->renewNow,'Expiry date hides the request');
$refused($late,['years'=>'1'],'This domain is too close to its expiry date to renew online. Contact support to renew it.');
same($issued,$ledger(),'Outside calendar creates no invoice');
same(1,Invoice::whereIn('id',Operation::where('action','renew')->pluck('invoice_id'))->count(),'Exactly one renewal invoice issued');

// Cycle states: paid, review and cancelled invoices each show one status; a retired unpaid cycle offers a replacement request.
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');$cycle=static fn()=>$owners->present($d->fresh())->renewNow;
$shows=static function(array $texts,string $label)use($page,$d):void {$html=$page($d);
    foreach($texts as $text){expect(str_contains($html,$text),$label.' shows '.$text);}
    expect(!str_contains($html,'Create invoice')&&!str_contains($html,'Pay invoice'),$label.' shows no form or link');};
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);same(['status'=>'paid'],$cycle(),'Paid cycle shows payment received');
$shows(['Paid','Updates when the renewal completes'],'Paid page');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'held']);
same(['status'=>'review'],$cycle(),'Held cycle shows review');$shows(['Renewal being checked','Contact support'],'Review page');
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'awaiting_payment']);DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'cancelled']);$cancelled=$ledger();
same(['status'=>'cancelled'],$cycle(),'Cancelled invoice shows its status');$shows(['Invoice cancelled','Contact support'],'Cancelled page');
$refused($d,['years'=>'2'],OwnerRenewalController::CANCELLED);same($cancelled,$ledger(),'Cancelled cycle request creates no invoice');
(new RenewalCycles($bindings,$clock))->retireUnpaid($op->id);
DB::table('invoice_transactions')->insert(['invoice_id'=>$invoice->id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded','created_at'=>now(),'updated_at'=>now()]);
same(['status'=>'review'],$cycle(),'Retired cycle with payment history shows review');DB::table('invoice_transactions')->where('invoice_id',$invoice->id)->delete();
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');same(['status'=>'cancelled'],$cycle(),'Retired unpaid cycle remains visible after cutoff');
$shows(['Invoice cancelled'],'Retired cutoff page');
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');$setting('domains_manual_renewals_enabled',false);
same(['status'=>'cancelled'],$cycle(),'Retired unpaid cycle remains visible with requests off');$setting('domains_manual_renewals_enabled',true);
$server->settings()->updateOrCreate(['key'=>'domains_due_days'],['value'=>'invalid','type'=>'string']);same(['status'=>'cancelled'],$cycle(),'Retired unpaid cycle remains visible with invalid calendar');$server->settings()->where('key','domains_due_days')->delete();
same(range(1,9),$cycle()['terms']??null,'Retired unpaid cycle offers a replacement request');
$html=$page($d);expect(str_contains($html,'Create invoice')&&!str_contains($html,'Invoice cancelled'),'Retired page shows the form, not the status');
// Replacement proof covers every debt, even beyond one page or under an unexpected key.
$copies=[];$copyInvoices=[];$copyItems=[];
$duplicate=static function(Operation $original,string $key,?string $base=null)use(&$copyInvoices,&$copyItems):Operation {
    $invoice=Invoice::findOrFail($original->invoice_id)->getRawOriginal();unset($invoice['id'],$invoice['number']);
    $invoiceId=DB::table('invoices')->insertGetId($invoice);$copyInvoices[]=$invoiceId;
    $item=\App\Models\InvoiceItem::findOrFail($original->invoice_item_id)->getRawOriginal();unset($item['id']);$item['invoice_id']=$invoiceId;
    $itemId=DB::table('invoice_items')->insertGetId($item);$copyItems[]=$itemId;
    $copy=$original->replicate();$copy->operation_key=$key;$copy->invoice_id=$invoiceId;$copy->invoice_item_id=$itemId;
    $attempt=$copy->attempt;$attempt['terminal']['operation_key']=$key;$attempt['terminal']['invoice_id']=$invoiceId;$copy->attempt=$attempt;
    if($base!==null){$copy->base_expires_at=$base;}$copy->save();return $copy;
};
for($i=1;$i<=101;$i++){
    $copy=$duplicate($op->fresh(),$i===101?'owner-cycle-unexpected':$op->operation_key.':replacement:'.$i);$copies[]=$copy->id;
}
foreach([1,2] as $pass){
    same(null,$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Every retired debt permits replacement: '.$pass);
    same(['status'=>'cancelled'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh(),true)),'Large retired cycle retains its status: '.$pass);
}
DB::table('domains_operations')->where('id',$copy->id)->update(['safe_code'=>'needs_review']);
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Unexpected-key debt on the second page blocks replacement');
DB::table('domains_operations')->where('id',$copy->id)->update(['safe_code'=>'renewal_cancelled_unpaid']);
DB::table('domains_operations')->where('id',$copy->id)->update(['base_expires_at'=>'not-a-date']);
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Malformed retained expiry still requires review');
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh(),true)),'Malformed retained expiry has no cancelled fallback');
DB::table('domains_operations')->where('id',$copy->id)->update(['base_expires_at'=>$op->getRawOriginal('base_expires_at')]);
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'needs_review']);
same(['status'=>'review'],$bounded(fn()=>OwnedDomains::renewalCycle($d->fresh())),'Old debt outside the newest 100 blocks replacement');
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'renewal_cancelled_unpaid']);
DB::table('domains_operations')->whereIn('id',$copies)->delete();
DB::table('invoice_items')->whereIn('id',$copyItems)->delete();DB::table('invoices')->whereIn('id',$copyInvoices)->delete();
$response=$controller->renew($req($d,['years'=>'1']),$d->id);$replacement=Operation::where('domain_id',$d->id)->where('action','renew')->orderByDesc('id')->first();
$replacementUrl=route('invoices.show',Invoice::findOrFail($replacement->invoice_id));
same([$op->operation_key.':replacement:1',1,$replacementUrl],[$replacement->operation_key,(int)$replacement->term,$response->getTargetUrl()],'Replacement request lands on its own invoice');
same(['invoice'=>$replacementUrl],$cycle(),'Replacement cycle links its invoice');
$savedTime=$clock->instant;$rawReplacement=$replacement->getRawOriginal();
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');
DB::table('domains_operations')->where('id',$replacement->id)->update(['state'=>'uncertain']);$unchanged=$ledger();
foreach([1,2] as $pass){
    same(['status'=>'review'],$bounded(fn()=>$cycle()),'Expired replacement preserves uncertain outcome: '.$pass);
    same($unchanged,$ledger(),'Repeated replacement read preserves retired and current debt: '.$pass);
}
DB::table('domains_operations')->where('id',$replacement->id)->update($rawReplacement);$clock->instant=$savedTime;
same(['invoice'=>$replacementUrl],$cycle(),'Restored replacement still owns its invoice');
// Newer history for other cycles cannot hide the current invoice.
$copies=[];$copyInvoices=[];$copyItems=[];
for($i=0;$i<105;$i++){
    $copy=$duplicate($op->fresh(),'renew:'.$d->id.':g'.$d->authority_generation.':2026-10-18:replacement:'.$i,'2026-10-18');$copies[]=$copy->id;
}
foreach([strtoupper($replacement->operation_key),strtoupper($op->operation_key),$op->operation_key.'0:replacement:1'] as $key){
    $copy=$duplicate($op->fresh(),$key);$copies[]=$copy->id;
}
same(['invoice'=>$replacementUrl],$bounded(fn()=>$cycle()),'Large unrelated history preserves the current invoice');
$html=$page($d);expect(str_contains($html,'Pay invoice'),'Large history page still links the current invoice');
DB::table('domains_operations')->whereIn('id',$copies)->delete();
DB::table('invoice_items')->whereIn('id',$copyItems)->delete();DB::table('invoices')->whereIn('id',$copyInvoices)->delete();

// Late requests keep horizon-bounded terms and share the preview's expiry limit with the writer.
foreach ([true,false] as $autoRenew) {
    [$lateRegistration]=$prepare(placeholderComFixtureName('owner-last-day-'.($autoRenew?'on':'off').'-placeholder.com'));
    same('succeeded',$reconcile->run($lateRegistration->id)->state,'Late owner registration confirmed');
    $lastDay=$lateRegistration->domain()->firstOrFail();
    $lastDay->update(['renewal_policy'=>[...($lastDay->renewal_policy??[]),'owner_auto_renew'=>$autoRenew]]);
    $clock->instant=new DateTimeImmutable('2027-10-17T23:59:59Z');
    same(['terms'=>range(1,9),'order_by'=>'2027-10-17'],$billing->requestOptions($lastDay),'Last-day preview: '.($autoRenew?'On':'Off'));
    $response=$controller->renew($req($lastDay,['years'=>'2']),$lastDay->id);
    $lastOp=Operation::where('domain_id',$lastDay->id)->where('action','renew')->sole();$lastInvoice=Invoice::findOrFail($lastOp->invoice_id);
    same(route('invoices.show',$lastInvoice),$response->getTargetUrl(),'Last-day POST accepts two-year request');
    same('2027-10-17',$lastInvoice->due_at->format('Y-m-d'),'Late invoice is due today');
    same($lastInvoice->id,$billing->invoice($lastDay->id,$user,2)->id,'Late duplicate reuses invoice');
    if($autoRenew){same($lastInvoice->id,$billing->invoice($lastDay->id)->id,'Scheduled billing reuses late manual cycle');}
    else{same(null,$billing->invoice($lastDay->id),'Owner Off still skips scheduled billing');}
    same(1,Operation::where('domain_id',$lastDay->id)->where('action','renew')->count(),'One late cycle invoice');
    $clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');$before=$ledger();
    same(['status'=>'cancelled'],$owners->present($lastDay->fresh())->renewNow,'Expiry hides retained payment link before cancellation');
    $html=$page($lastDay);expect(!str_contains($html,route('invoices.show',$lastInvoice))&&!str_contains($html,'Pay invoice'),'Expiry page has no invoice link');
    expect(str_contains($html,'Invoice cancelled'),'Expiry page shows retained cancelled status');
    same('pending',$lastInvoice->fresh()->status,'Owner page leaves pending debt unchanged');
    $refused($lastDay,['years'=>'2'],'This domain is too close to its expiry date to renew online. Contact support to renew it.');
    same($before,$ledger(),'Expired duplicate and page are read-only');
}

// Owner copy for every billing refusal hides reasons and internal messages.
foreach([[new RuntimeIneligible(RuntimeReason::SpendingPaused),OwnerRenewalController::UNAVAILABLE],[new RuntimeIneligible(RuntimeReason::LifecycleNotReady),OwnerRenewalController::UNAVAILABLE],
    [new RuntimeIneligible(RuntimeReason::MissingAnchor),OwnerRenewalController::UNAVAILABLE],[new DomainException('Domain renewal is unavailable.'),OwnerRenewalController::UNAVAILABLE],
    [new DomainException('Renewal is off for this adopted registration.'),'Auto-renew is off for this domain. Contact support if you want to renew it.'],
    [new RuntimeException('Renewal term exceeds the registry horizon.'),OwnerRenewalController::UNAVAILABLE]] as [$error,$copy]){same($copy,OwnerRenewalController::refusal($error),'Owner copy for '.$error->getMessage());}
foreach([[null,'This domain already has a renewal invoice for a different term. Pay that invoice, or contact support to change it.'],['cancelled',OwnerRenewalController::CANCELLED],
    ['review','This renewal is under review. Contact support if you need an update on this renewal.']] as [$state,$copy]){same($copy,OwnerRenewalController::refusal(new DomainException('Existing renewal obligation requires review.'),$state),'Issued cycle copy for '.($state??'term'));}
foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
// Money that lands on a closed early invoice: the customer page says where it went.
$setting('domains_manual_renewals_enabled',true);$retire=new RenewalCycles($bindings,$clock);$refunds=new Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
$clock->instant=new DateTimeImmutable('2026-10-18T12:00:00Z');[$source]=$prepare(placeholderComFixtureName('owner-late-credited-placeholder.com'));same('succeeded',$reconcile->run($source->id)->state,'Domain ready');$domain=$source->domain()->firstOrFail();
$clock->instant=new DateTimeImmutable('2027-08-01T12:00:00Z');$closed=$billing->invoice($domain->id,$user,1);$op=Operation::where('invoice_id',$closed->id)->sole();DB::table('domains_operations')->where('id',$op->id)->update(['created_at'=>'2027-08-01 12:00:00']);
$clock->instant=new DateTimeImmutable('2027-08-09T12:00:00Z');$retire->closeEarlyUnpaid($domain->id);same('cancelled',$closed->fresh()->status,'The week closes the invoice');
App\Helpers\ExtensionHelper::addPayment($closed->id,null,'1.00');$refunds->creditRejected($op->id);
expect(str_contains($page($domain),'We added $1.00 to account credit. You can apply it on an invoice.'),'Customer page says where the money went');
echo "Owner renewal off, terms, foreign, replay, success, duplicate, issued link, auto-renew off, cycle states and calendar refusals passed.\n";$nativeSuccessful=true;
