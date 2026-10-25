<?php
// Enom 444f31c/f7175ff transfer reconciliation, stricter unique charge; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
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
        if ($command === ReadCommand::Nameservers && $s->fault === 'dns-cap') { throw new \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit; }
        $expiry=$s->fault==='short'?'2027-01-01':'2028-01-01'; $owner=in_array($s->fault,['owner','reject','reject_charge'],true)?'other-account':'account-enom';
        $item='<Description>'.$s->name.'</Description><ProductType>Transfer</ProductType><Quantity>1</Quantity><Status>Successful</Status><OrderStatus>Closed</OrderStatus><AmountPaid>8.95</AmountPaid><ExpiryDateTime>'.$expiry.'</ExpiryDateTime>';
        [$label,$tld]=explode('.',$s->name,2); $header=str_starts_with($s->fault,'reject')?'2':($s->fault==='pending'?'5':'3'); $detail=str_starts_with($s->fault,'reject')?'32':($s->fault==='pending'?'100':'5');
        $data=match($command){
            ReadCommand::Info=>'<GetDomainInfo><DomainName DomainNameID="456">'.$s->name.'</DomainName><Status><Belongs-To>'.$owner.'</Belongs-To><Expiration>'.$expiry.'</Expiration></Status></GetDomainInfo>',
            ReadCommand::Nameservers=>'<dns>ns1.provider.example</dns><dns>ns2.provider.example</dns>',
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
require_once __DIR__.'/Support/WireLimit.php';
$nativeMoney = static function (): array {
    $rows = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'credits', 'jobs', 'failed_jobs'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }
    return $rows;
};
$proofCommands = ['GetDomainInfo', 'GetRenew', 'GetRegLock', 'GetWPPSInfo', 'GetDomainInfo', 'GetDomainExp', 'TP_GetOrder', 'GetOrderList', 'GetOrderDetail'];
foreach (['cap', 'deferred', 'observed'] as $fault) {
    $op = $prepare(placeholderComFixtureName('dns-'.$fault.'-transfer-placeholder.com'));
    $observe->fault = $fault === 'cap' ? 'dns-cap' : '';
    $attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding);
    $limiter = new WireLimit($fault === 'deferred' ? 9 : 10);
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
    $facts = $readBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
    $proof = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::completion($attempt, $facts, $clock->now());
    expect($proof !== null, 'Optional transfer DNS '.$fault.' retains completion proof');
    same(['9001', ['amount' => '8.95', 'currency' => 'USD'], '2028-01-01', '456', 'accepted_history'],
        [$proof['order_reference'], $proof['charge'], $proof['expiry'], $proof['provider_domain_id'], $proof['source']], 'Transfer retains original financial and domain proof: '.$fault);
    same(['7001', 'completed', true], [$facts->transferReference, $facts->transfer->value, $facts->historyComplete], 'Transfer retains original evidence: '.$fault);
    $expectedCommands = $fault === 'deferred' ? $proofCommands : [...$proofCommands, 'GetDNS'];
    same([$expectedCommands, 10, $fault === 'deferred' ? 9 : 10], [$observe->calls, $limiter->calls, $limiter->released], 'Exact transfer proof wires and final admission: '.$fault);
    same($fault === 'observed' ? ['ns1.provider.example', 'ns2.provider.example'] : null, $facts->domain->nameservers?->nameservers, 'Only successful GetDNS supplies observed values: '.$fault);
    if ($fault === 'observed') { same(['observed', 'enom_getdns'], [$facts->domain->nameservers->state, $facts->domain->nameservers->source], 'GetDNS provenance retained'); }
    $observe->calls = []; $limiter = new WireLimit($fault === 'deferred' ? 9 : 10);
    $savedBindings = app()->getBindings();
    $runtimeClass = \Paymenter\Extensions\Servers\Domains\Services\Runtime::class;
    $limiterClass = \Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class;
    $savedInstances = [];
    foreach ([$runtimeClass, $limiterClass] as $class) { if (app()->bound($class)) { $savedInstances[$class] = app($class); } }
    app()->instance($runtimeClass, new stdClass); app()->instance($limiterClass, $limiter);
    try {
        $money = $nativeMoney(); $writes = $state->calls;
        $result = $reconcile->run($op->id);
        same('succeeded', $result->fresh()->state, 'Transfer persists proven completion after DNS '.$fault);
        same($proof, $result->attempt['reconciliation'], 'Persisted proof is the original proof: '.$fault);
        same(['9001', ['amount' => '8.95', 'currency' => 'USD'], '2028-01-01', '456'],
            [$result->provider_order_id, $result->cost['actual'], $result->confirmed_expires_at->format('Y-m-d'), $result->domain->fresh()->provider_domain_id], 'Persisted order, USD charge, expiry and domain ID: '.$fault);
        same('7001', $result->attempt['evidence']['transferReference'], 'Persisted transfer reference survives optional read failure');
        same($fault === 'observed' ? 'observed' : null, $result->domain->fresh()->observation['nameservers']['state'] ?? null, 'No invented observed DNS: '.$fault);
        same([$money, $writes], [$nativeMoney(), $state->calls], 'Transfer preserves native money and never purchases again: '.$fault);
        same([$expectedCommands, 10], [$observe->calls, $limiter->calls], 'Persistence uses exact proof reads: '.$fault);
        $retained = [$result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()];
        $reconcile->run($op->id); $engine->execute($op->id);
        same([$expectedCommands, 10, $money, $writes, $retained], [$observe->calls, $limiter->calls, $nativeMoney(), $state->calls,
            [$result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()]], 'Completed transfer repeat adds no I/O or writes: '.$fault);
    } finally {
        foreach ([$runtimeClass, $limiterClass] as $class) {
            app()->offsetUnset($class);
            if (isset($savedBindings[$class])) { app()->bind($class, $savedBindings[$class]['concrete'], $savedBindings[$class]['shared']); }
            if (isset($savedInstances[$class])) { app()->instance($class, $savedInstances[$class]); }
        }
    }
}
$op = $prepare(placeholderComFixtureName('dns-unproven-transfer-placeholder.com'));
$observe->fault = 'charge';
$attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding);
$context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding, \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,
    $clock->now()->modify('+250 seconds'), new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock);
$facts = $readBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
same(null, \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::completion($attempt, $facts, $clock->now()), 'Missing charge cannot prove completion');
same(null, $facts->domain->nameservers, 'Unproven completion never enriches DNS');
expect(!in_array('GetDNS', $observe->calls, true), 'Missing financial proof never reads DNS');
$op = $prepare(placeholderComFixtureName('dns-preproof-transfer-placeholder.com'));
$attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding); $limiter = new WireLimit(8);
$context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
    \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
    new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
try { $readBindings->reader($binding->serverId)->reconcile($attempt, $context); throw new LogicException('Pre-proof refusal was swallowed'); }
catch (\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred) {}
same([array_slice($proofCommands, 0, 8), 9, 8], [$observe->calls, $limiter->calls, $limiter->released], 'Pre-proof transfer refusal still escapes before order/DNS');
require __DIR__.'/Support/transfer-watch.php';
$predecessor=transferWatchFixture('full-transfer-placeholder.com',(int)$user->id);$op=$prepare(placeholderComFixtureName('full-transfer-placeholder.com'));$r=$reconcile->run($op->id);
same('succeeded',$r->state,'Status, exact unique original charge, expiry and ownership complete'); same('9001',$r->provider_order_id,'Independent billing order ID');
same('8.95',$r->cost['actual']['amount'],'Exact charged USD amount'); same('2028-01-01',$r->confirmed_expires_at->format('Y-m-d'),'Full year added to original expiry');
same('active',$r->domain->status,'Active only after proof'); same('2028-01-01',$r->domain->renewal_policy['billing_anchor']['expiry'],'Transfer billing anchor');
same(['ns1.provider.example','ns2.provider.example'],$r->domain->fresh()->observation['nameservers']['nameservers']??null,'Enom GetDNS values are saved on completed transfer');
same('observed',$r->domain->fresh()->observation['nameservers']['state']??null,'Enom transfer nameservers retain observed provenance');
same(null,App\Models\Service::find($r->service_id)->expires_at,'Native expiry disabled'); expect(RenewalAuthority::completed($r,$r->domain,$binding),'Transfer anchor accepted by renewal consumers');
$completed=$r; $calls=$observe->calls; $reconcile->run($r->id); same($calls,$observe->calls,'Completion refresh sends nothing');
$copy=clone $r; $copy->attempt=[...$copy->attempt,'reconciliation'=>[...$copy->attempt['reconciliation'],'transfer_status'=>'pending']]; expect(!RenewalAuthority::completed($copy,$copy->domain,$binding),'Succeeded label lacks status proof');
foreach (['pending','status','charge','duplicate','short','owner','expiry','late','nohistory','reversed','race','reject_owned','reject_charge'] as $fault) {
    $op=$prepare(placeholderComFixtureName('unresolved-transfer-'.str_replace('_','-',$fault).'-placeholder.com')); $observe->fault=$fault;
    if ($fault==='nohistory') { App\Models\Server::find($op->server_id)->settings()->where('key',HistoryAcceptances::KEY.'_transfer')->delete(); }
    if ($fault==='reversed') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
    if ($fault==='race') { $observe->during=fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
    $r=$reconcile->run($op->id); same('uncertain',$r->state,'Missing/contradictory evidence stays unresolved'); same(null,$r->domain->provider_expires_at,'No inferred expiry'); same(null,$r->cost['actual']??null,'No inferred charge');
    same(null,$r->domain->fresh()->observation['nameservers']??null,'Unproven Enom transfers do not save nameserver facts');
    if (in_array($fault,['pending','status','owner','reject','reject_charge','reject_owned'],true)) { expect(!in_array('GetDNS',$observe->calls,true),'Nameservers are read only after completed transfer ownership'); }
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
