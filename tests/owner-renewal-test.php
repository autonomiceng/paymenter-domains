<?php
// Native owner renewal matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerRenewalController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard, OwnedDomains, RenewalCycles, RuntimeIneligible, RuntimeReason};
$ownerRenewalDomains=2;
require __DIR__.'/Support/owner-renewal.php';

// Off: no form, no key in use, and a direct POST refuses with plain copy and no debt.
$setting('domains_manual_renewals_enabled',false);$setting('domains_billing_enabled',true);
same([],$owners->present($d->fresh())->renewNow,'Manual renewals off hide the request');
$html=$page($d);
same(0,storefrontDom($html)->query('//form[@data-renewal-form] | //a[@data-dialog-open and contains(@href,"?edit=renew#edit-renew")]')->length,'Off page shows no renewal form or opening action');
$before=$ledger();$refused($d,['years'=>'1'],'You can’t renew online right now. Contact support to renew this domain.');
same($before,$ledger(),'Off request creates no invoice');

// On: form offers only terms inside the ten-year registry horizon.
$setting('domains_manual_renewals_enabled',true);$dto=$owners->present($d->fresh());
same(['url'=>route('domains.domains.renew',$d->id),'terms'=>range(1,9),'order_by'=>'2027-10-17'],$dto->renewNow,'Request offers horizon-bounded terms until the day before expiry');
$html=$page($d);
foreach(['Renew now','>Renew domain<','>Years<','<option value="9"','<option value="1"','Continue to payment','action="'.route('domains.domains.renew',$d->id).'"','name="_request_key"'] as $text){expect(str_contains($html,$text),'Owner form shows '.$text);}
$renewDom=storefrontDom($html);
same('Renew online by Oct 17, 2027.',trim($renewDom->query('//dialog[@id="edit-renew"]//dl/div[dt="Expires"]/dd/span[@class="domains-sub"]')->item(0)?->textContent??''),'The last online renewal day sits in the dialog Expires row');
expect(!str_contains($html,'renew online until'),'No separate unpadded renewal deadline line');
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
expect(!str_contains($page($d),'Continue to payment'),'Processing off page has no request');$refused($d,['years'=>'2'],OwnerRenewalController::UNAVAILABLE);$bindings->setProcessing($server->id, true, syntheticActivation());
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
$html=$page($d);expect(str_contains($html,'Pay invoice')&&str_contains($html,'href="'.route('invoices.show',$invoice).'"')&&!str_contains($html,'Continue to payment'),'Issued page links the invoice');
expect(str_contains($html,'Invoice ready. Due Oct 11, 2027.')&&!str_contains($html,'Early renewal not paid'),'Customer reads the same early renewal words as staff');
$setting('domains_manual_renewals_enabled',false);
same(['invoice'=>route('invoices.show',$invoice)],$owners->present($d->fresh())->renewNow,'Off retains the issued-invoice link');
same(route('invoices.show',$invoice),$controller->renew($req($d,['years'=>'2']),$d->id)->getTargetUrl(),'Turning requests off still returns the issued invoice');
same($issued,$ledger(),'Off duplicate creates nothing');$setting('domains_manual_renewals_enabled',true);


foreach([Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::class,App\Jobs\Server\CreateJob::class] as $job){Queue::assertNotPushed($job);}
$nativeSuccessful=true;echo "owner-renewal-test.php: passed.\n";
