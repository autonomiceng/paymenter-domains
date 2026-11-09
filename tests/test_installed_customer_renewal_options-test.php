<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native ownership, encrypted account binding and Blade must run together to catch hidden renewal actions.
require __DIR__.'/Support/reconciliation-fixture.php';
$cataloguePorts=$ports;
require __DIR__.'/Support/owner-pages.php';
use App\Models\{Invoice,Role};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;

[$registration]=$prepare(placeholderComFixtureName('customer-renewal-options-placeholder.com'));
same('succeeded',$reconcile->run($registration->id)->state,'Registration has its confirmed billing anchor');
$d=$registration->domain()->firstOrFail();$server=$product->server;
storefrontViews();$session=app('request')->session();
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'),$cataloguePorts);
$binding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole());
$query=new Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'renew');
$cost=new Paymenter\Extensions\Servers\Domains\Catalogue\Cost('renew',1,true,'8.0000',Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$clock->now(),$clock->now()->modify('+30 days'));
$cataloguePorts->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,'com',[$cost]),$cataloguePorts->readOffers($query,Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
$staff=commerceUser();$role=Role::create(['name'=>'Funding reviewer','permissions'=>['admin.domains.manage','admin.domains.fund']]);$staff->update(['role_id'=>$role->id]);
$request=Request::create('/domains/'.$d->id,'GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession($session);
$render=fn()=>(new OwnedDomainController($owners))->show($request,$d->id)->render();
// Renewal On and no renewal invoice yet: the page says when it issues, then that it is missing. After expiry the list shows Expired
// and the page still says the invoice was never issued, never that it issues on a passed date.
$dates=Paymenter\Extensions\Servers\Domains\Services\DomainBilling::calendarFor(['domain'=>$d->fresh(),'settings'=>$server->fresh()->settings]);
$expires=$d->fresh()->provider_expires_at->format('Y-m-d');$at=$clock->instant;$contact=Paymenter\Extensions\Servers\Domains\Presentation\Missing::support(null);
expect($d->fresh()->provenance!=='adopted'&&$dates['issue']<$expires,'A registered domain with its renewal invoice issued before expiry');
$facts=static function(string $day)use($clock,$user,$d,$owners):array {
    $clock->instant=new DateTimeImmutable($day.'T12:00:00Z');
    $s=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
    return [$s,Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::renewal($s),Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($owners->present($d->fresh()),$s,null)];
};
$date=static fn(string $day)=>CarbonImmutable::parse($day,'UTC')->format('M j, Y');
try{
    [$s,$row,$next]=$facts((new DateTimeImmutable($dates['issue']))->modify('-1 day')->format('Y-m-d'));
    same(['Issues '.$date($dates['issue']).'. Due '.$date($dates['due']).'.',false],[$next['value']??null,$row['invoiceMissing']],'Before its issue date the page says when the invoice issues');
    [$s,$row,$next]=$facts((new DateTimeImmutable($dates['issue']))->modify('+1 day')->format('Y-m-d'));
    same([true,'Not issued yet. Expected '.$date($dates['issue']).'.',$contact],[$row['invoiceMissing'],$next['cause']??null,$next['fix']??null],'Past its issue date the list and page say the invoice is missing');
    [$s,$row,$next]=$facts((new DateTimeImmutable($expires))->modify('+1 day')->format('Y-m-d'));
    same([false,false,'expired'],[$s->renewalInvoiceMissing,$row['invoiceMissing'],$s->statusState],'After expiry the list shows Expired, not a missing invoice');
    same([true,'Not issued yet. Expected '.$date($dates['issue']).'.',$contact],[$next['missing']??false,$next['cause']??null,$next['fix']??null],'After expiry the page still says the invoice was never issued');
}finally{$clock->instant=$at;}
foreach([false,true] as $complimentary){
    if($complimentary){ComplimentaryAccounts::set($user->id,$staff,true,'Synthetic funding authorization',$clock);}
    $before=Invoice::count();same(0,DB::transactionLevel(),'Customer page starts outside financial locks');
    $html=$render();
    expect(str_contains($html,'>Renew now</a>'),'Eligible '.($complimentary?'complimentary':'paid').' customer sees Renew now');
    expect(str_contains($html,'<option value="1"'),'Customer renewal offers its supported annual term');
    same([1],$ownerBilling->requestOptions($d->fresh())['terms'],'Advisory terms agree with the native page');
    same($complimentary?'0.00':'10.40',$ownerBilling->renewalChoices($d->fresh())['next_annual'],'Customer sees the authorized renewal price');
    same($before,Invoice::count(),'Advisory page creates no invoice');
    $bindings->setProcessing($server->id,false);
    expect(!str_contains($render(),'>Renew now</a>'),'Processing off still hides customer renewal');
    commerceReject(fn()=>$ownerBilling->invoice($d->id,$user,1));
    same($before,Invoice::count(),'Processing off cannot issue debt');
    $bindings->setProcessing($server->id,true,syntheticActivation());
}
// Read-only advice never relaxes the transaction requirement of the default writer guard.
$account=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole();
try{$bindings->requireProcessingLocked($server->fresh(),$account);expect(false,'Writer guard requires a transaction');}
catch(LogicException $e){same('Locked processing requires an outer transaction.',$e->getMessage(),'Writer guard preserved');}
$invoice=$ownerBilling->invoice($d->id,$user,1);
same(['paid',0.0],[$invoice->status,(float)$invoice->total],'Authorized complimentary renewal still uses the guarded invoice writer');
// The zero informational receipt has its own issue date, not the standard unpaid-debt calendar.
$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
expect(!$summary->renewalInvoiceMissing,'Funded complimentary renewal is not a missing invoice on the list');
$row=Paymenter\Extensions\Servers\Domains\Presentation\DomainListRow::renewal($summary);
same('Next invoice: Paid',$row['note'],'List and customer page agree on the issued renewal state');
same('Paid',Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($owners->present($d->fresh()),$summary,null)['value'],'Detail shows the same paid receipt');
// An issued invoice is never called missing after expiry.
try{[$s,$row,$next]=$facts((new DateTimeImmutable($expires))->modify('+1 day')->format('Y-m-d'));same(['Paid',false],[$next['value']??null,$s->renewalInvoiceOverdue],'After expiry an issued invoice keeps its own state');}finally{$clock->instant=$at;}
// An informational receipt cannot hide a changed/nonzero line or a foreign owner.
$item=$invoice->items()->sole();$item->forceFill(['price'=>'1.00'])->saveQuietly();
$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
expect($summary->renewalInvoiceReview && !$summary->renewalInvoicePaid,'Changed complimentary receipt remains under review');
same('Under review',Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($owners->present($d->fresh()),$summary,null)['value'],'Changed receipt detail stays under review');
// The customer's actual page keeps the refusal: the rendered Renewal card says Under review, never Paid, and moves nothing.
$money=static fn():string=>serialize([DB::table('invoices')->get(),DB::table('invoice_items')->get(),DB::table('invoice_transactions')->get(),DB::table('credits')->get(),\Paymenter\Extensions\Servers\Domains\Models\Operation::all()->map->getRawOriginal()->all()]);
$unchanged=$money();$dom=new DOMDocument;@$dom->loadHTML($render());
$card=(new DOMXPath($dom))->query('//section[@id="renewal"]//dt[normalize-space(text())="Next invoice"]/following-sibling::dd[1]')->item(0);
$card=trim(preg_replace('/\s+/',' ',(string)$card?->textContent));
expect(str_starts_with($card,'Under review')&&!str_contains($card,'Paid'),'Rendered changed receipt stays under review, got: '.$card);
same($unchanged,$money(),'Rendering the changed receipt moves no money and changes no request');
$item->forceFill(['price'=>'0.00'])->saveQuietly();$invoice->forceFill(['user_id'=>$staff->id])->saveQuietly();
$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
expect($summary->renewalInvoiceReview && !$summary->renewalInvoicePaid,'Foreign receipt cannot supply list payment state');
same('Under review',Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($owners->present($d->fresh()),$summary,null)['value'],'Foreign receipt detail stays under review');
$invoice->forceFill(['user_id'=>$user->id])->saveQuietly();
$held=\Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id',$invoice->id)->sole();$held->update(['state'=>'uncertain']);
$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
expect($summary->renewalInvoiceReview&&!$summary->renewalInvoicePaid,'Held complimentary receipt remains Under review on list');
same('Under review',Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($owners->present($d->fresh()),$summary,null)['value'],'Held complimentary detail matches list');

// Cancellation is only a presentation verdict after complete ledger membership validation.
$held->update(['state'=>'awaiting_payment']);$invoice->forceFill(['status'=>'cancelled'])->saveQuietly();
$summary=(new Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
// Complimentary cancelled receipts are not ordinary debt: they remain under review.
same('Under review',Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($owners->present($d->fresh()),$summary,null)['value'],'Cancelled complimentary receipt cannot masquerade as ordinary cancelled debt');

$nativeSuccessful=true;echo "Native paid and complimentary customer renewal options passed.\n";
