<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A pending order says where its payment stands, the same on the list and the page, and History says why a change failed.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage, DomainSummary, Missing, OwnedDomain, StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaryStrings, OwnedDomains};

storefrontViews();
$norm=static fn(string $html)=>preg_replace('/\s+/',' ',trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5)));
$d=static fn(array $more=[])=>(new Domain)->forceFill(['status'=>'registration_pending','registrar_key'=>'enom',...$more]);
$op=static fn(array $more=[])=>(new Operation)->forceFill(['action'=>'register','state'=>'queued','safe_code'=>null,'policy'=>[],'attempt'=>[],'payload'=>[],...$more]);
$invoice=static function(string $status){$i=(new Invoice)->forceFill(['id'=>11,'status'=>$status]);$i->exists=true;return $i;};

// Which order state each case reaches.
$unpaid=OwnedDomains::order($d(),$op(['state'=>'awaiting_payment']),$invoice('pending'));
same('unpaid',$unpaid['state'],'An open invoice leaves the order unpaid');
same(route('invoices.show',11),$unpaid['invoice'],'Unpaid order links straight to its invoice');
same('paid',OwnedDomains::order($d(),$op(),$invoice('paid'))['state'],'Paid and queued');
same('paid',OwnedDomains::order($d(),$op(['state'=>'held','safe_code'=>'processing']),$invoice('paid'))['state'],'Paid and held by us');
same('paid',OwnedDomains::order($d(),$op(['state'=>'awaiting_payment']),$invoice('paid'))['state'],'Paid but not picked up yet');
same('transfer',OwnedDomains::order($d(['status'=>'transfer_pending']),$op(['action'=>'transfer','payload'=>['epp'=>'Good-Code-123']]),$invoice('paid'))['kind'],'A transfer says transfer');
same('cancelled',OwnedDomains::order($d(),$op(['state'=>'awaiting_payment']),$invoice('cancelled'))['state'],'A cancelled invoice cannot be paid');
same('cancelled',OwnedDomains::order($d(),$op(['state'=>'awaiting_payment']),$invoice('pending'),true)['state'],'A cancelled service offers no payment');
same('cancelled',OwnedDomains::order($d(),$op(),$invoice('paid'),true)['state'],'A cancelled service is never being finished');
same('paid',OwnedDomains::order($d(),$op(['state'=>'running']),$invoice('paid'),true)['state'],'A sent order is still being finished');
// A failed request reads Failed to customer and staff; a credited one reads Cancelled; an unpaid close is not a failure.
$rejected=fn(string $code,string $action='transfer')=>OwnedDomains::order($d(['status'=>$action==='transfer'?'transfer_pending':'registration_pending']),$op(['action'=>$action,'state'=>'rejected','safe_code'=>$code]),$invoice('paid'));
$pill=fn(array $order)=>StatusPill::of(...OwnedDomains::orderStatus(['transfer_pending',null],$d(['status'=>'transfer_pending']),$order));
same(['Failed','Transfer did not finish'],[$pill($rejected('transfer_provider_rejected'))['label'],$pill($rejected('transfer_provider_rejected'))['reason']],'Failed transfer says so');
same(['Cancelled','Order cancelled'],[$pill($rejected('refund_approved'))['label'],$pill($rejected('refund_approved'))['reason']],'Credited order reads Cancelled');
same(['Cancelled','Order cancelled'],[$pill($credited=OwnedDomains::order($d(['status'=>'registration_rejected']),$op(['state'=>'rejected','safe_code'=>'refund_approved']),$invoice('paid')))['label'],$pill($credited)['reason']],'Applying the credit keeps the order Cancelled');
same([],OwnedDomains::order($d(['status'=>'registration_rejected']),$op(['state'=>'rejected','safe_code'=>'preflight_failed']),$invoice('paid')),'A failed domain stays Failed');
same([],$rejected('acquisition_cancelled_unpaid'),'Unpaid close is not a failed order');
// Paid stays true while the owner still has a step, and the status names the step, never the invoice.
$next=static fn(array $order)=>[$order['state']??null,$order['next']??null];
same(['paid','setup_required'],$next(OwnedDomains::order($d(),$op(['state'=>'held','safe_code'=>'contact_details_required']),$invoice('paid'))),'A hold only the owner can clear never says nothing to do');
$noCode=OwnedDomains::order($d(['status'=>'transfer_pending']),$op(['action'=>'transfer','state'=>'awaiting_payment']),$invoice('paid'));
same(['paid','awaiting_code'],$next($noCode),'A paid transfer still needs its auth code');
same('Auth code needed',StatusPill::of(...OwnedDomains::orderStatus(['awaiting_payment',null],$d(['status'=>'transfer_pending']),$noCode))['reason'],'Paid transfer never says Invoice not paid');
$noSetup=OwnedDomains::order($d(['registrar_key'=>'namesilo']),$op(['state'=>'awaiting_payment']),$invoice('paid'));
same(['paid','setup_required'],$next($noSetup),'Paid, and setup is still the owner\'s');
same('Finish setup',StatusPill::of(...OwnedDomains::orderStatus(['awaiting_payment',null],$d(),$noSetup))['reason'],'Paid setup never says Invoice not paid');
same(['registration_pending',null],OwnedDomains::orderStatus(['awaiting_payment',null],$d(),['state'=>'paid','next'=>null]),'Paid with nothing left is pending');
same(['awaiting_payment',null],OwnedDomains::orderStatus(['awaiting_payment',null],$d(),['state'=>'unpaid']),'Unpaid stays unpaid');
same([],OwnedDomains::order($d(['status'=>'active']),$op(),$invoice('paid')),'Only pending orders');
same([],OwnedDomains::order($d(),null,null),'No order, no line');

// One word and one treatment on the list and the page.
$summary=static fn(array $order)=>new DomainSummary('order.example',null,'managed','domain.registration.unknown',[],null,null,[],DomainSummaryStrings::all(),...[
    'expiry'=>null,'expiryPrecision'=>null,'expirySource'=>null,'freshness'=>[],'renewalDueAt'=>null,'renewalPrice'=>null,'relatedServices'=>[],'hostnames'=>[],'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create'],'order'=>$order,'expiryWaits'=>'registration']);
$owned=static fn(array $order)=>new OwnedDomain('order.example','Pending registration','warn',null,'',null,['show'=>'/domains/1'],[],[],[],[],false,order:$order);
$pay=['state'=>'unpaid','kind'=>'registration','invoice'=>'/invoices/11'];
$paid=['state'=>'paid','kind'=>'registration'];
foreach ([[$pay,'Pay invoice','Registration starts after you pay'],[$paid,'Contact support','Paid. We’re finishing the registration. No action needed.']] as [$order,$fix,$cause]) {
    $list=DomainListRow::expires($summary($order));
    same($list,DomainPage::expires($owned($order),$summary($order),'/tickets/create'),'List and page share Expires');
    same($list,DomainPage::expires($owned($order),null,'/tickets/create'),'The page says the same with no summary');
    same($cause,$list['cause'],'Expires says what is happening');
    same($fix,$list['fix']['label'],'Expires gives the next step');
    expect(str_contains($norm((string)Missing::html($list['cause'],$list['fix'])),'Missing'),'The word stays Missing');
}
$html=(string)Missing::html(...array_values(array_intersect_key(DomainListRow::expires($summary($pay)),['cause'=>1,'fix'=>1])));
$link=storefrontDom('<div>'.$html.'</div>')->query('//a[@href="/invoices/11"]')->item(0);
same('Pay invoice',trim($link->textContent),'List links to the invoice');
expect(str_contains($link->getAttribute('class'),'domains-btn--primary'),'Pay invoice is a button');
expect(!str_contains((string)Missing::html('Paid. We’re finishing the registration. No action needed.',Missing::support('/tickets/create')),'<svg'),'Paid and pending is calm');
expect(str_contains($html,'<svg'),'Unpaid stays red: the customer has to act');

// The page notice.
$notice=DomainPage::notice($owned($pay),'/tickets/create');
same('This order isn’t paid yet. Pay the invoice to start the registration.',$notice['text'],'Unpaid notice');
same(['label'=>'Pay invoice','url'=>'/invoices/11','button'=>true],$notice['action'],'Unpaid notice carries the Pay button');
same(['Paid. Finish setup to start the registration.',null],[($e=DomainListRow::orderExpires(['state'=>'paid','kind'=>'registration','next'=>'setup_required'],'/tickets/create'))['cause'],$e['fix']],'Expires names the owner\'s step');
same('Paid. Add the auth code to start the transfer.',DomainListRow::orderExpires(['state'=>'paid','kind'=>'transfer','next'=>'awaiting_code'],'/tickets/create')['cause'],'Expires asks for the code');
same(null,DomainPage::notice($owned(['state'=>'paid','kind'=>'registration','next'=>'setup_required']),'/tickets/create'),'Never says nothing to do while a step is left');
$notice=DomainPage::notice($owned($paid),'/tickets/create');
same('Paid. We’re finishing the registration. There’s nothing you need to do.',$notice['text'],'Paid notice says it plainly');
same(['label'=>'Contact support','url'=>'/tickets/create'],$notice['action'],'Paid notice says how to reach us');

// History: a Failed row has a reason and a next step, and every row can show its date.
$failure=new ReflectionMethod(OwnedDomains::class,'failure');
same('The registrar did not take this change.',$failure->invoke(null,$op(['safe_code'=>'management_provider_refused'])),'Refused change');
same('The registrar did not take this change. The customer can try again.',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('management_provider_refused'),'Staff read the same sentence');
same('We didn’t send this change, so nothing changed.',$failure->invoke(null,$op(['safe_code'=>'enom_test_management_call_cap'])),'Change stopped before sending');
same('We couldn’t make this change.',$failure->invoke(null,$op(['safe_code'=>'anything_else'])),'Any other failure still gets a reason');
$domain=new OwnedDomain('order.example','Active','ok',null,'',null,['show'=>'/domains/1'],[['label'=>'Nameservers','state'=>'Failed','date'=>'2026-10-27','note'=>'The registrar refused this change.'],['label'=>'Contacts','state'=>'Under review','date'=>'2026-10-20']],[],[],[],false);
$table=storefrontDom(view('domains::partials.activity',['domain'=>$domain])->render());
$cells=$table->query('//td[@data-label="Status"]');
expect(str_contains($norm($cells->item(0)->textContent),'The registrar refused this change. Try again. Still stuck? Contact support'),'Failed row shows reason and next step');
same('Under review',trim($cells->item(1)->textContent),'Other rows stay short');
same(2,$table->query('//td[@data-label="Date"][string-length(normalize-space())>0]')->length,'Both rows show a date');
$nativeSuccessful=true;echo "Order payment checks passed.\n";
