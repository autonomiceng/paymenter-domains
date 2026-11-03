<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A pending order says where its payment stands, the same on the list and the page, and History says why a change failed.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage, DomainSummary, Missing, OwnedDomain, StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement, DomainSummaryStrings, OwnedDomains};

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
$rejected=fn(string $code,string $action='transfer',array $funding=[])=>OwnedDomains::order($d(['status'=>$action==='transfer'?'transfer_pending':'registration_pending']),$op(['action'=>$action,'state'=>'rejected','safe_code'=>$code,'funding'=>$funding]),$invoice('paid'));
$pill=fn(array $order)=>StatusPill::of(...OwnedDomains::orderStatus(['transfer_pending',null],$d(['status'=>'transfer_pending']),$order));
same(['Failed','Transfer did not finish'],[$pill($rejected('transfer_provider_rejected'))['label'],$pill($rejected('transfer_provider_rejected'))['reason']],'Failed transfer says so');
$refund=['refund'=>['to'=>'account_credit']];
same(['Cancelled','Order cancelled'],[$pill($rejected('refund_approved','transfer',$refund))['label'],$pill($rejected('refund_approved','transfer',$refund))['reason']],'Credited order reads Cancelled');
same(['Cancelled','Order cancelled'],[$pill($credited=OwnedDomains::order($d(['status'=>'registration_rejected']),$op(['state'=>'rejected','safe_code'=>'refund_approved','funding'=>$refund]),$invoice('paid')))['label'],$pill($credited)['reason']],'Applying the credit keeps the order Cancelled');
same([],OwnedDomains::order($d(['status'=>'registration_rejected']),$op(['state'=>'rejected','safe_code'=>'preflight_failed']),$invoice('paid')),'A failed domain stays Failed');
same([],$rejected('acquisition_cancelled_unpaid'),'Unpaid close is not a failed order');
// A completed gateway return cancels the order without calling it account credit.
foreach(['refunded','credited','pending','reserved','review'] as $result){
    $returned=OwnedDomains::order($d(),$op(['state'=>'rejected','safe_code'=>'refund_approved','funding'=>[
        'refund'=>['to'=>'original_payment','bridge_minor'=>0,'allocations'=>[['key'=>'return-key','amount_minor'=>100]]],
        'receipt_return_results'=>['return-key'=>['state'=>$result]]]]),$invoice('paid'));
    same(in_array($result,['refunded','credited'],true)?'Cancelled':'Under review',$pill($returned)['label'],'Gateway return pill agrees with '.$result);
    expect(!isset($returned['credit']),'A gateway return does not set the account credit marker');
}
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
foreach ([[$pay,'Pay invoice','Registration starts after you pay'],[$paid,null,'Fills in by itself when the registration finishes']] as [$order,$fix,$cause]) {
    $list=DomainListRow::expires($summary($order));
    same($list,DomainPage::expires($owned($order),$summary($order),'/tickets/create'),'List and page share Expires');
    same($list,DomainPage::expires($owned($order),null,'/tickets/create'),'The page says the same with no summary');
    same($cause,$list['cause'],'Expires says what is happening');
    same($fix,$list['fix']['label']??null,'Expires gives the next step, or none when nothing is owed');
    expect(str_contains($norm((string)Missing::html($list['cause'],$list['fix'])),'Missing'),'The word stays Missing');
}
$html=(string)Missing::html(...array_values(array_intersect_key(DomainListRow::expires($summary($pay)),['cause'=>1,'fix'=>1])));
$link=storefrontDom('<div>'.$html.'</div>')->query('//a[@href="/invoices/11"]')->item(0);
same('Pay invoice',trim($link->textContent),'List links to the invoice');
expect(str_contains($link->getAttribute('class'),'domains-btn--primary'),'Pay invoice is a button');
expect(!str_contains((string)Missing::html('Fills in by itself when the registration finishes',null),'<svg'),'Paid and pending is calm');
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
// Work that is ours reads blue; amber is for a step the customer owns. The Off switch is the reason we give first.
$held=static fn(int|string|null $refused)=>new OwnedDomain('held.example','Active','good',null,'',null,['show'=>'/domains/1'],[],[],[],['contacts'=>['recordCheckHeld'=>true]],false,changesUnavailable:$refused!==null,changesRefused:$refused);
same(['info','We can’t read this domain’s details right now. We are on it, nothing for you to do.'],array_values(array_intersect_key(DomainPage::notice($held(null),'/tickets/create'),['tone'=>1,'text'=>1])),'A held read is our job, so it is blue');
same(['info','Changes are on hold: DNS, nameservers and contacts are Off. Contact us to turn them on.'],array_values(array_intersect_key(DomainPage::notice($held(DomainManagement::SWITCH_OFF),'/tickets/create'),['tone'=>1,'text'=>1])),'The Off switch is named before a held read');
// Automatic retries keep paid customers reassured; only a stopped check needs the shared support notice.
foreach(['register'=>'registration','transfer'=>'transfer'] as $action=>$kind){
    foreach(['checked','deferred','scheduled','unavailable'] as $outcome){
        foreach([1793451600,null] as $retry){
            $pending=$op(['action'=>$action,'state'=>'uncertain','payload'=>['epp'=>'Good-Code-123'],
                'policy'=>['status_check'=>['outcome'=>$outcome,'next_at'=>$retry,'reason'=>'send_guard']]]);
            $order=OwnedDomains::order($d(['status'=>$kind==='transfer'?'transfer_pending':'registration_pending']),$pending,$invoice('paid'));
            $notice=DomainPage::notice($owned($order),'/tickets/create');
            if($retry!==null){
                expect(!isset($order['check']),'Scheduled retry keeps check details out of the customer order');
                same(['info','Paid. We’re finishing the '.$kind.'. There’s nothing you need to do.'],[$notice['tone'],$notice['text']],'Automatic retry needs no customer action');
            }else{
                same(Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::for($pending),$order['check'],'Stopped customer check uses the same words as staff');
                same('warn',$notice['tone'],'Stopped check points the customer to support');
            }
            same($retry,$pending->policy['status_check']['next_at'],'Customer presentation preserves staff scheduling facts');
        }
    }
}
$transferCredit=OwnedDomains::order($d(['status'=>'transfer_rejected']),$op(['action'=>'transfer','state'=>'rejected','safe_code'=>'transfer_fee_absorbed','funding'=>['refund'=>['to'=>'account_credit','amount'=>'12.00']]]),$invoice('paid'));
same('This order was cancelled. We added what you paid to account credit. You can apply it on an invoice.',DomainPage::notice($owned($transferCredit),'/tickets/create')['text']??null,'The rejected transfer page confirms the recorded credit bridge');

// History: a Failed row has a reason and a next step, and every row can show its date.
$failure=new ReflectionMethod(OwnedDomains::class,'failure');
same('The registrar did not take this change.',$failure->invoke(null,$op(['safe_code'=>'management_provider_refused'])),'Refused change');
same('The registrar did not take this change. The customer can try again.',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('management_provider_refused'),'Staff read the same sentence');
same('Not sent. Nothing changed at the registrar.',$failure->invoke(null,$op(['state'=>'rejected','safe_code'=>'management_unconfirmed','policy'=>['management'=>['action'=>'nameservers']],'attempt'=>[]])),'Customers read the staff line for a change that never went out');
same('We couldn’t make this change.',$failure->invoke(null,$op(['safe_code'=>'anything_else'])),'Any other failure still gets a reason');
$domain=new OwnedDomain('order.example','Active','ok',null,'',null,['show'=>'/domains/1'],[['label'=>'Nameservers','state'=>'Failed','date'=>'2026-10-27','note'=>'The registrar refused this change.','retry'=>true],['label'=>'Contacts','state'=>'Under review','date'=>'2026-10-20']],[],[],[],false);
$table=storefrontDom(view('domains::partials.activity',['domain'=>$domain])->render());
$steps=$table->query('//ol[@class="domains-trace"]/li');
$word=fn($li)=>trim($table->query('./span[last()]',$li)->item(0)->textContent);
expect(str_contains($norm($steps->item(0)->textContent),'The registrar refused this change. Try again. Still stuck? Contact support')&&$word($steps->item(0))==='Failed','Failed step shows reason and next step');
same('Under review',$word($steps->item(1)),'Other steps end in their word');
// Without Edit, the customer is told to ask us, never to try again.
$noEdit=storefrontDom(view('domains::partials.activity',['domain'=>new OwnedDomain('order.example','Active','ok',null,'',null,['show'=>'/domains/1'],[['label'=>'Contacts','state'=>'Failed','date'=>'2026-10-27','note'=>'Not sent. Nothing changed at the registrar.','retry'=>false]],[],[],[],false)])->render());
$step=$norm($noEdit->query('//ol[@class="domains-trace"]/li')->item(0)->textContent);
expect(str_contains($step,'Not sent. Nothing changed at the registrar. Contact support to make this change.')&&!str_contains($step,'Try again'),'A failed change the customer can\'t make asks them to contact us');
$row=['id'=>75,'pending'=>false,'text'=>'Not sent. Nothing changed at the registrar.'.Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::TRY_AGAIN];
same('Not sent. Nothing changed at the registrar. Contact support to make this change.',OwnedDomains::askUs($row,false)['text'],'The row under a setting without Edit asks them to contact us');
same($row,OwnedDomains::askUs($row,true),'With Edit, the row keeps Try again');
$done=storefrontDom(view('domains::partials.activity',['domain'=>new OwnedDomain('order.example','Active','ok',null,'',null,['show'=>'/domains/1'],[['label'=>'Renewal','state'=>'Cancelled','date'=>'2026-10-27','note'=>'We added $1.00 to account credit.']],[],[],[],false)])->render());
$step=$norm($done->query('//ol[@class="domains-trace"]/li')->item(0)->textContent);
expect(str_contains($step,'We added $1.00 to account credit.')&&!str_contains($step,'Contact support')&&!str_contains($step,'Try again'),'A settled note asks for nothing more');
same(2,$table->query('//ol[@class="domains-trace"]/li[.//time]')->length,'Both steps show a date');
$nativeSuccessful=true;echo "Order payment checks passed.\n";
