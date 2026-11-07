<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerRenewalController;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, OwnedDomains, RenewalCycles, RuntimeIneligible, RuntimeReason};
// Real native checkout, controller rendering and locked financial rows need the installed fixture.
$registrationNames=['owner-renew-placeholder.com','owner-renew-late-placeholder.com','owner-last-day-on-placeholder.com','owner-last-day-off-placeholder.com','owner-late-credited-placeholder.com'];
require __DIR__.'/Support/owner-renewal-fixture.php';
// Complete the independent native registrations before renewal cases advance the fake clock.
$lastDays=[];
foreach ([true,false] as $autoRenew) {
    [$registration]=$prepare(placeholderComFixtureName('owner-last-day-'.($autoRenew?'on':'off').'-placeholder.com'));
    same('succeeded',$reconcile->run($registration->id)->state,'Late owner registration confirmed');
    $lastDays[(int)$autoRenew]=$registration->domain()->firstOrFail();
}
[$creditedSource]=$prepare(placeholderComFixtureName('owner-late-credited-placeholder.com'));same('succeeded',$reconcile->run($creditedSource->id)->state,'Domain ready');
storefrontViews();
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
// Off: no form, no key in use, and a direct POST refuses with plain copy and no debt.
$setting('domains_manual_renewals_enabled',false);$setting('domains_billing_enabled',true);
same([],$owners->present($d->fresh())->renewNow,'Manual renewals off hide the request');
$html=$page($d);$noRenewalForm($html,$d,'Off page shows no renewal form');
$before=$ledger();$refused($d,['years'=>'1'],'You can’t renew online right now. Contact support to renew this domain.');
same($before,$ledger(),'Off request creates no invoice');

// No exact multi-year quotes: offer annual renewal while preserving the registry horizon checks.
$setting('domains_manual_renewals_enabled',true);$dto=$owners->present($d->fresh());
same(['url'=>route('domains.domains.renew',$d->id),'terms'=>[1],'order_by'=>'2027-10-17'],$dto->renewNow,'Request offers horizon-bounded terms until the day before expiry');
$html=$page($d);
foreach(['Renew now','>Renew domain<','>Domain<','>'.$d->domain.'<','>Years<',
    'You can renew online until <time datetime="2027-10-17">Oct 17, 2027</time>.','Continue to payment','action="'.route('domains.domains.renew',$d->id).'"','name="_request_key"'] as $text){expect(str_contains($html,$text),'Owner form shows '.$text);}
$form=storefrontDom($html);$options=$form->query('//select[@id="renew-years"]/option');
same(['1'],array_map(fn($option)=>$option->getAttribute('value'),iterator_to_array($options)),'Owner form offers exactly the supported annual term');
same('1 year',trim((string)$options->item(0)?->textContent),'Owner form names the annual term');
foreach (range(2,10) as $years) { same(0,$form->query('//select[@id="renew-years"]/option[@value="'.$years.'"]')->length,'Unsupported longer term is not offered'); }
same(range(1,10),DomainBilling::terms(new DateTimeImmutable('2027-10-18'),new DateTimeImmutable('2027-10-18T00:00:00Z')),'Horizon admits ten years from expiry day');
same([1,2],DomainBilling::terms(new DateTimeImmutable('2035-08-01'),new DateTimeImmutable('2027-09-01T12:00:00Z')),'Far expiry narrows the term list');
same([],DomainBilling::terms(new DateTimeImmutable('2037-10-18'),new DateTimeImmutable('2027-09-01T12:00:00Z')),'Expiry past the horizon offers no term');

// Term bounds and malformed input refuse before or at billing; none creates debt.
$before=$ledger();
foreach(['0','01','abc','',' 2','2.0','-1','100'] as $years){$refused($d,['years'=>$years],'Choose how many years to renew for.');}
$refused($d,[],'Choose how many years to renew for.');$refused($d,['years'=>['2']],'Choose how many years to renew for.');
foreach(['10','11','99'] as $years){$refused($d,['years'=>$years],'Domains can be registered for at most 10 years ahead. Choose a shorter term.');}
foreach (range(2,9) as $years) { $refused($d,['years'=>(string)$years],OwnerRenewalController::UNAVAILABLE); }
same($before,$ledger(),'Refused terms create no invoice');

// Foreign owner, wrong intent and another domain's key are refused before billing.
try{$controller->renew($req($d,['years'=>'1'],$other),$d->id);expect(false,'Foreign owner refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
$replay(fn()=>$controller->renew($req($d,['years'=>'1'],null,'nameservers'),$d->id));
$wrong=$req($late,['years'=>'1']);$replay(fn()=>$controller->renew($wrong,$d->id));
same($before,$ledger(),'Foreign and mismatched intents create no invoice');

// Processing off is an internal refusal: generic copy, no reason code.
$bindings->setProcessing($server->id,false);
same([],$owners->present($d->fresh())->renewNow,'Processing off hides the request');
expect(!str_contains($page($d),'Continue to payment'),'Processing off page has no request');$refused($d,['years'=>'1'],OwnerRenewalController::UNAVAILABLE);$bindings->setProcessing($server->id, true, syntheticActivation());
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
    expect(!str_contains($page($d),'Continue to payment'),'Ineligible page has no request: '.$case);
    $refused($d,['years'=>'1'],OwnerRenewalController::UNAVAILABLE);same($unchanged,$ledger(),'Ineligible page and POST preserve debt: '.$case);
    DB::table('domains_domains')->where('id',$d->id)->update($rawDomain);
    if($prior){DB::table('domains_operations')->where('id',$prior->id)->delete();}
    DB::table('services')->where('id',$d->service_id)->update($rawService);
}

// Success sends the owner to the invoice; duplicates return the same invoice.
$first=$req($d,['years'=>'1']);$stale=clone $first;$response=$controller->renew($first,$d->id);
$op=Operation::where('domain_id',$d->id)->where('action','renew')->sole();$invoice=Invoice::findOrFail($op->invoice_id);
same(route('invoices.show',$invoice),$response->getTargetUrl(),'Owner lands on the renewal invoice');
same([1,'awaiting_payment','2027-10-11',(int)$user->id],[(int)$op->term,$op->state,$invoice->due_at->format('Y-m-d'),(int)$invoice->user_id],'Requested term, due date and owner recorded');
same('Domain renewal: owner-renew-placeholder.com (1 year, Oct 18, 2027 to Oct 18, 2028)',$invoice->items->sole()->description,'Invoice names the requested term');
$replay(fn()=>$controller->renew($stale,$d->id));$issued=$ledger();
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'1']),$d->id)->getTargetUrl(),'Fresh duplicate returns the same invoice');
same($issued,$ledger(),'Duplicate submit creates no second invoice');
$refused($d,['years'=>'3'],'This domain already has a renewal invoice for a different term. Pay that invoice, or contact support to change it.');
same($issued,$ledger(),'Different term on an issued cycle creates no invoice');
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Issued cycle links its invoice instead of a form');
$html=$page($d);expect(str_contains($html,'Pay invoice')&&str_contains($html,'href="'.route('invoices.show',$invoice).'"')&&!str_contains($html,'Continue to payment'),'Issued page links the invoice');
expect(str_contains($html,'Invoice ready. Due Oct 11, 2027.')&&!str_contains($html,'Early renewal not paid'),'Customer reads the same early renewal words as staff');
$setting('domains_manual_renewals_enabled',false);
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Off retains the issued-invoice link');
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'1']),$d->id)->getTargetUrl(),'Turning requests off still returns the issued invoice');
same($issued,$ledger(),'Off duplicate creates nothing');$setting('domains_manual_renewals_enabled',true);

// Auto-renew off: billing refuses manual requests for a stopped renewal, so the page offers none; turning it on restores the form.
$policy=$late->fresh()->renewal_policy??[];$late->update(['renewal_policy'=>[...$policy,'renewals_stopped'=>true]]);$dto=$owners->present($late->fresh());
same(['Off',[]],[$dto->renewal,$dto->renewNow],'Auto-renew off hides the request billing refuses');
$html=$page($late);$noRenewalForm($html,$late,'Auto-renew off page shows no renewal form');
$refused($late,['years'=>'1'],OwnerRenewalController::UNAVAILABLE);same($issued,$ledger(),'Auto-renew off request creates no invoice');
$late->update(['renewal_policy'=>$policy]);$dto=$owners->present($late->fresh());
same(['On',[1]],[$dto->renewal,$dto->renewNow['terms']??null],'Auto-renew on offers the request again');

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
    expect(!str_contains($html,'Continue to payment')&&!str_contains($html,'Pay invoice'),$label.' shows no form or link');};
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);same(['status'=>'paid'],$cycle(),'Paid cycle shows payment received');
$shows(['Paid','Updates when the renewal completes'],'Paid page');
DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'held']);
same(['status'=>'review'],$cycle(),'Held cycle shows review');$shows(['Under review','Renewal being checked. No action needed.'],'Review page');
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
same([1],$cycle()['terms']??null,'Retired unpaid cycle offers a replacement request');
$html=$page($d);expect(str_contains($html,'Continue to payment')&&!str_contains($html,'Invoice cancelled'),'Retired page shows the form, not the status');
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

// Late annual requests share the preview's expiry limit with the writer.
foreach ([true,false] as $autoRenew) {
    $lastDay=$lastDays[(int)$autoRenew];
    $lastDay->update(['renewal_policy'=>[...($lastDay->renewal_policy??[]),'owner_auto_renew'=>$autoRenew]]);
    $clock->instant=new DateTimeImmutable('2027-10-17T23:59:59Z');
    same(['terms'=>[1],'order_by'=>'2027-10-17'],$billing->requestOptions($lastDay),'Last-day preview: '.($autoRenew?'On':'Off'));
    $response=$controller->renew($req($lastDay,['years'=>'1']),$lastDay->id);
    $lastOp=Operation::where('domain_id',$lastDay->id)->where('action','renew')->sole();$lastInvoice=Invoice::findOrFail($lastOp->invoice_id);
    same(route('invoices.show',$lastInvoice),$response->getTargetUrl(),'Last-day POST accepts annual request');
    same('2027-10-17',$lastInvoice->due_at->format('Y-m-d'),'Late invoice is due today');
    same($lastInvoice->id,$billing->invoice($lastDay->id,$user,1)->id,'Late duplicate reuses invoice');
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
$domain=$creditedSource->domain()->firstOrFail();
$clock->instant=new DateTimeImmutable('2027-08-01T12:00:00Z');$closed=$billing->invoice($domain->id,$user,1);$op=Operation::where('invoice_id',$closed->id)->sole();DB::table('domains_operations')->where('id',$op->id)->update(['created_at'=>'2027-08-01 12:00:00']);
$clock->instant=new DateTimeImmutable('2027-08-09T12:00:00Z');$retire->closeEarlyUnpaid($domain->id);same('cancelled',$closed->fresh()->status,'The week closes the invoice');
App\Helpers\ExtensionHelper::addPayment($closed->id,null,'1.00');$refunds->creditRejected($op->id);
expect(str_contains($page($domain),'We added $1.00 to account credit. You can apply it on an invoice.'),'Customer page says where the money went');
echo "Owner renewal off, terms, foreign, replay, success, duplicate, issued link, auto-renew off, cycle states and calendar refusals passed.\n";$nativeSuccessful=true;
