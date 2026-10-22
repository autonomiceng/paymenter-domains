<?php
// Enom 444f31c/f7175ff transfer reconciliation, stricter unique charge; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$transferEnvironment=Paymenter\Extensions\Servers\Domains\Data\Environment::Live; // All transports remain synthetic.
require __DIR__.'/Support/transfer-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, RegistrarRegistry, HistoryAcceptances, OperationReconciliation, RenewalAuthority};
$observe=(object)['name'=>'','fault'=>'','calls'=>[],'during'=>null];
$wire=new class($observe) implements Transport {
    public function __construct(private $s){}
    public function send(Environment $e, ReadCommand $command, array $p, string $uid, string $token, int $timeoutMs): string {
        same(0,DB::transactionLevel(),'Observation outside locks'); expect($timeoutMs>0 && $timeoutMs<=25000,'Bounded observation'); $s=$this->s; $s->calls[]=$command->value;
        if ($s->during) { $hook=$s->during; $s->during=null; $hook(); }
        $expiry=$s->fault==='short'?'2027-01-01':'2028-01-01'; $owner=in_array($s->fault,['owner','reject','reject_charge'],true)?'other-account':'account-enom';
        $item='<Description>'.$s->name.'</Description><ProductType>Transfer</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime>';
        [$label,$tld]=explode('.',$s->name,2); $header=str_starts_with($s->fault,'reject')?'2':($s->fault==='pending'?'5':'3'); $detail=str_starts_with($s->fault,'reject')?'32':($s->fault==='pending'?'100':'5');
        $data=match($command){
            ReadCommand::Info=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>'.$owner.'</Belongs-To><Expiration>'.$expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::Expiry=>$s->fault==='expiry'?'':'<ExpirationDate>'.$expiry.'</ExpirationDate>',
            ReadCommand::Renewal=>'<RenewName>0</RenewName>', ReadCommand::Lock=>'<Reg-Lock>1</Reg-Lock>',
            ReadCommand::Privacy=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            ReadCommand::Transfer=>$s->fault==='status'?'':'<TransferOrder><TransferOrderID>7001</TransferOrderID><StatusID>'.$header.'</StatusID><TransferOrderDetail><SLD>'.$label.'</SLD><TLD>'.$tld.'</TLD><StatusID>'.$detail.'</StatusID></TransferOrderDetail></TransferOrder>',
            ReadCommand::Orders=>'<OrderCount>1</OrderCount><OrderList><Order><OrderID>9001</OrderID></Order></OrderList>',
            // A clean rejection has complete history whose only order is for another domain.
            ReadCommand::Order=>$s->fault==='reject'?'<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><BillDate>10/18/2026 5:00:01 AM</BillDate><OrderDetail>'.str_replace($s->name,'unrelated-'.$s->name,$item).'</OrderDetail></Order>'
                :(in_array($s->fault,['charge','reject_owned'],true)?'':'<Order><OrderID>9001</OrderID><Result>true</Result><OrderBillAmount>$8.95</OrderBillAmount><BillDate>10/18/2026 '.($s->fault==='late'?'5:10:00':'5:00:01').' AM</BillDate><OrderDetail>'.$item.'</OrderDetail>'.($s->fault==='duplicate'?'<OrderDetail>'.$item.'</OrderDetail>':'').'</Order>'),
            default=>throw new LogicException('Unexpected observation command'),
        };
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$data.'</interface-response>';
    }
};
$registry=new RegistrarRegistry; $registry->register('enom',['token'],$verifier,fn($b,$c)=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(new Client($b,'account-enom',$c['token'],$wire),['com']));
$readBindings=new AccountBindings($registry); $reconcile=new OperationReconciliation($claims,$readBindings,$clock);
$prepare=static function($name)use($newTransfer,$makeTransfer,&$engine,&$binding,$clock,$now,$state,$observe){
    $clock->instant=$now; $state->rdapUnknown=false; $state->mode=''; $op=$newTransfer($name); $makeTransfer(); $r=$engine->execute($op->id);
    same('7001',$r->attempt['evidence']['transferReference'],'Transfer ID retained separately'); same(null,$r->provider_order_id,'Transfer ID never billing OrderID');
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'enom_transfer_v1'));
    $observe->name=$name; $observe->fault=''; $observe->calls=[]; $clock->instant=$now->modify('+6 minutes'); return $r;
};
require __DIR__.'/Support/transfer-watch.php';
$predecessor=transferWatchFixture('full-transfer-placeholder.com',(int)$user->id);$op=$prepare(placeholderComFixtureName('full-transfer-placeholder.com'));$r=$reconcile->run($op->id);
same('succeeded',$r->state,'Status, exact unique original charge, expiry and ownership complete'); same('9001',$r->provider_order_id,'Independent billing order ID');
same('8.95',$r->cost['actual']['amount'],'Exact charged USD amount'); same('2028-01-01',$r->confirmed_expires_at->format('Y-m-d'),'Full year added to original expiry');
same('active',$r->domain->status,'Active only after proof'); same('2028-01-01',$r->domain->renewal_policy['billing_anchor']['expiry'],'Transfer billing anchor');
same(null,App\Models\Service::find($r->service_id)->expires_at,'Native expiry disabled'); expect(RenewalAuthority::completed($r,$r->domain,$binding),'Transfer anchor accepted by renewal consumers');
$completed=$r; $calls=$observe->calls; $reconcile->run($r->id); same($calls,$observe->calls,'Completion refresh sends nothing');
$copy=clone $r; $copy->attempt=[...$copy->attempt,'reconciliation'=>[...$copy->attempt['reconciliation'],'transfer_status'=>'pending']]; expect(!RenewalAuthority::completed($copy,$copy->domain,$binding),'Succeeded label lacks status proof');
foreach (['pending','status','charge','duplicate','short','owner','expiry','late','nohistory','reversed','race','reject_owned','reject_charge'] as $fault) {
    $op=$prepare('unresolved-transfer-'.str_replace('_','-',$fault).'.com'); $observe->fault=$fault;
    if ($fault==='nohistory') { App\Models\Server::find($op->server_id)->settings()->where('key',HistoryAcceptances::KEY.'_transfer')->delete(); }
    if ($fault==='reversed') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
    if ($fault==='race') { $observe->during=fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
    $r=$reconcile->run($op->id); same('uncertain',$r->state,'Missing/contradictory evidence stays unresolved'); same(null,$r->domain->provider_expires_at,'No inferred expiry'); same(null,$r->cost['actual']??null,'No inferred charge');
    same(null,$r->attempt['transfer_terminal']??null,'Contradictory facts never become terminal');
    if (str_starts_with($fault,'reject')) { same('transfer_rejection_contradiction',$r->policy['reconciliation']['reason'],'Rejection contradiction retained'); }
}
$op=$prepare(placeholderComFixtureName('sent-rejected-transfer-placeholder.com')); $observe->fault='reject'; $r=$reconcile->run($op->id);
same('rejected',$r->state,'Bound terminal refusal retained'); same('transfer_rejected',$r->domain->status,'Terminal transfer observation');
commerceReject(fn()=>$refunds->creditRejected($r->id)); commerceReject(fn()=>Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($r->domain->domain));
same(null,$r->fresh()->funding['refund']??null,'Sent rejection never credited'); same($r->domain->domain,$r->domain->fresh()->reservation_name,'Sent rejection retains reservation');
// A rejection without complete charge history stays open: no candidates were looked for, so none were ruled out.
$op=$prepare(placeholderComFixtureName('unreconciled-rejected-transfer-placeholder.com')); $observe->fault='reject';
App\Models\Server::find($op->server_id)->settings()->where('key',HistoryAcceptances::KEY.'_transfer')->delete(); $r=$reconcile->run($op->id);
same('uncertain',$r->state,'Unreconciled rejection is not terminal'); same(null,$r->attempt['transfer_terminal']??null,'No terminal evidence without charge history');
same('transfer_rejection_unreconciled',$r->policy['reconciliation']['reason'],'Staff see why it stays open'); expect($r->domain->status!=='transfer_rejected','Domain not marked rejected yet');
// Unknown baseline is advisory for sending, never a made-up full-term anchor.
$clock->instant=$now; $op=$newTransfer(placeholderComFixtureName('missing-transfer-baseline-placeholder.com')); $makeTransfer(); $state->rdapUnknown=true; $r=$engine->execute($op->id);
$observe->name=$op->domain->domain; $observe->fault=''; $clock->instant=$now->modify('+6 minutes');
same('uncertain',$reconcile->run($op->id)->state,'Missing original expiry cannot establish added year');
// Slice25 renewal authority accepts the exact completed transfer anchor.
$renewalFacts=(new RenewalAuthority($bindings,$clock))->capture(new Paymenter\Extensions\Servers\Domains\Data\RenewalRequest($completed->domain_id,$completed->authority_generation,$completed->domain->confirmed_revision,'2028-01-01','456'));
same($completed->domain->domain,$renewalFacts[1],'Transfer anchor admitted by execution consumer');
// The actual slice-24 consumer creates one native annual renewal debt from the transfer anchor.
App\Models\Server::find($completed->server_id)->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$billing=new Paymenter\Extensions\Servers\Domains\Services\DomainBilling($bindings,$guard,$clock);
$invoice=$billing->invoice($completed->domain_id,$user); $renew=Paymenter\Extensions\Servers\Domains\Models\Operation::where('invoice_id',$invoice->id)->sole();
same('2028-01-01',$renew->base_expires_at->format('Y-m-d'),'Transfer full-term anchor starts next billing cycle');
same('2029-01-01',$renew->expected_expires_at->format('Y-m-d'),'One complete renewal year'); same('awaiting_payment',$renew->state,'Billing grants no write authority');
same($invoice->id,$billing->invoice($completed->domain_id,$user)->id,'Transfer renewal billing is idempotent');
same(null,$invoice->items->sole()->reference_id,'Renewal debt has no native service dispatch');
assertTransferWatch($predecessor,$completed,$clock->now()->getTimestamp());
echo "Transfer unique-charge completion, native funding races, terminal refusal and renewal anchors passed.\n"; $nativeSuccessful=true;
