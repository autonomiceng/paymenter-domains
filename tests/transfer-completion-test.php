<?php
// Enom 444f31c/f7175ff transfer reconciliation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{Environment,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client,ReadCommand};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,RegistrarRegistry,HistoryAcceptances,OperationReconciliation,RenewalAuthority};
require_once __DIR__.'/Support/WireLimit.php';
$nativeMoney = static function (): array {
    $rows = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'credits', 'jobs', 'failed_jobs'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }
    return $rows;
};
$proofCommands = ['GetDomainInfo', 'GetRenew', 'GetRegLock', 'GetWPPSInfo', 'GetDomainInfo', 'GetDomainExp', 'TP_GetOrder', 'GetOrderList', 'GetOrderDetail'];
// Limiter admission is outside Client's transport catch. Driver must preserve programmer failures.
$op = $prepare(placeholderComFixtureName('dns-programmer-transfer-placeholder.com'));
$attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding);
foreach ([new Error('DNS admission error'), new TypeError('DNS admission type error'),
    new LogicException('DNS admission logic error'), new RuntimeException('DNS admission runtime error'),
    new DomainException('Unexpected DNS admission refusal'), new DomainException('Other provider refusal'),
    new class('Registrar read failed.') extends DomainException {},
    new class('Registrar request budget exhausted.') extends RuntimeException {},
    new class('Registrar request deadline reached.') extends RuntimeException {}] as $error) {
    $observe->calls = [];
    $limiter = new WireLimit(10);
    $limiter->onAcquire = static function () use ($limiter, $error): void { if ($limiter->calls === 10) { throw $error; } };
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter);
    $money = $nativeMoney(); $writes = $state->calls; $caught = null;
    try { $readBindings->reader($binding->serverId)->reconcile($attempt, $context); } catch (Throwable $failure) { $caught = $failure; }
    expect($caught === $error, 'Transfer propagates exact DNS admission programmer exception: '.$error::class);
    same([$proofCommands, 10, 9, $money, $writes], [$observe->calls, $limiter->calls, $limiter->released, $nativeMoney(), $state->calls],
        'Transfer reaches optional admission only after proof, without a DNS wire or purchase');
}
$op = $prepare(placeholderComFixtureName('dns-refusal-transfer-placeholder.com'));
$attempt = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::attempt($op, $op->domain, $binding);
foreach (['refused', 'refused-strict', 'transport', 'malformed', 'duplicate', 'shape'] as $fault) {
    $observe->fault = 'dns-'.$fault; $observe->calls = [];
    $limiter = new WireLimit(10);
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $clock->now()->modify('+250 seconds'),
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget(25), $clock, $limiter, stopOnReadFailure: $fault === 'refused-strict');
    $money = $nativeMoney(); $writes = $state->calls;
    $facts = $readBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
    $proof = \Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::completion($attempt, $facts, $clock->now());
    expect($proof !== null, 'Transfer retains proof after expected DNS failure: '.$fault);
    same(['9001', ['amount' => '8.95', 'currency' => 'USD'], '2028-01-01', '456', null],
        [$proof['order_reference'], $proof['charge'], $proof['expiry'], $proof['provider_domain_id'], $facts->domain->nameservers],
        'Transfer DNS failure preserves money and ownership without invented nameservers: '.$fault);
    same([[...$proofCommands, 'GetDNS'], 10, 10, $money, $writes],
        [$observe->calls, $limiter->calls, $limiter->released, $nativeMoney(), $state->calls], 'Transfer DNS failure releases admission without writes: '.$fault);
}
$observe->fault = '';
foreach (['budget', 'deadline'] as $fault) {
    $observe->calls = []; $instant = $clock->now(); $deadline = $instant->modify('+250 seconds');
    $limiter = new WireLimit(10);
    if ($fault === 'deadline') {
        $limiter->onAcquire = static function () use ($limiter, $clock, $deadline): void { if ($limiter->calls === 10) { $clock->instant = $deadline; } };
    }
    $context = new \Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
        \Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background, $deadline,
        new \Paymenter\Extensions\Servers\Domains\Data\RequestBudget($fault === 'budget' ? 9 : 25), $clock, $limiter);
    $facts = $readBindings->reader($binding->serverId)->reconcile($attempt, $context)->result()->evidence();
    $clock->instant = $instant;
    expect(\Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence::completion($attempt, $facts, $clock->now()) !== null, 'Transfer retains proof after expected local '.$fault.' exhaustion');
    same(null, $facts->domain->nameservers, 'Local capacity failure supplies no nameserver evidence');
    same([$proofCommands, $fault === 'budget' ? 9 : 10, $fault === 'budget' ? 9 : 10],
        [$observe->calls, $limiter->calls, $limiter->released], 'Local exhaustion occurs after proof without DNS transport');
}
foreach (['cap', 'deferred', 'observed'] as $fault) {
    $op = $prepare(placeholderComFixtureName('dns-'.$fault.'-transfer-placeholder.com'));
    $observe->fault = 'dns-'.$fault;
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
        $pollCommands = ['TP_GetOrder', ...array_values(array_filter($expectedCommands, fn($command) => $command !== 'TP_GetOrder'))];
        same([$pollCommands, 10], [$observe->calls, $limiter->calls], 'Terminal polling reuses status before the same exact proof reads: '.$fault);
        $retained = [$result->fresh()->getRawOriginal(), $result->domain->fresh()->getRawOriginal()];
        $reconcile->run($op->id); $engine->execute($op->id);
        same([$pollCommands, 10, $money, $writes, $retained], [$observe->calls, $limiter->calls, $nativeMoney(), $state->calls,
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
    if (str_starts_with($fault,'reject')) {
        same($fault==='reject_owned'?'transfer_rejection_contradiction':'transfer_rejection_unreconciled',$r->policy['reconciliation']['reason'],'A charge alone needs finality and fee evidence; ownership still conflicts');
        same(null,$refunds->creditRejected($r->id),'A rejection and charge read alone never returns money');
    }
}
$op=$prepare(placeholderComFixtureName('sent-rejected-transfer-placeholder.com')); $observe->fault='reject'; $r=$reconcile->run($op->id);
same('uncertain',$r->state,'Rejected status and empty history cannot prove finality or the failed fee'); same('transfer_pending',$r->domain->status,'No invented terminal transfer observation');
same(null,$refunds->creditRejected($r->id),'Unproved finality cannot return money'); commerceReject(fn()=>Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($r->domain->domain));
same(null,$r->fresh()->funding['refund']??null,'Sent rejection never credited'); same($r->domain->domain,$r->domain->fresh()->reservation_name,'Sent rejection retains reservation');
// Existing native fixture is needed for audited account/attempt locks and the real payment-return lifecycle.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(AccountBindings::class,$readBindings);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class,$refunds);
$staff=commerceUser();$permissions=['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update'];
$role=\App\Models\Role::create(['name'=>'Transfer answers','permissions'=>$permissions]);$staff->update(['role_id'=>$role->id]);
$settings=new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites($native,$sellers,$readBindings);
$review=static fn($row)=>new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview($staff->id,$row->server_id,null,'enom_answer',
    \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ticket($staff->id,$row->server_id,null,'enom_answer',\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp($row->server_id,null)),'Record final synthetic transfer answer');
$answer=static fn($row)=>['operation_id'=>$row->id,'attempt_hash'=>\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::identity($row),
    'outcome'=>'transfer_rejected','order_reference'=>'7001','case_reference'=>'synthetic-transfer-case','evidence'=>'Enom checked this account and transfer attempt: finally rejected; no later completion; USD charge and settled refund as recorded.',
    'confirmed'=>true,'final_confirmed'=>true,'charge'=>'8.95','registrar_refund'=>'3.00'];
$answerChecks=[];$observe->fault='down';
foreach(['+1 day','+8 days'] as $delay){
    $clock->instant=$now->modify($delay);$r=$reconcile->run($r->id);$poll=$r->policy['reconciliation'];
    $answerChecks[]=[$poll['transfer_status'],$poll['reason'],$poll['final_check']??null,\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::eligible($r,$clock->now())];
}
$changed=clone $r;$changed->policy=[...$changed->policy,'reconciliation'=>[...$changed->policy['reconciliation'],'reason'=>'transfer_authority_changed']];
same([['rejected','transfer_status_unavailable',null,true],['rejected','transfer_deadline_passed','done',true],false],
    [...$answerChecks,\Paymenter\Extensions\Servers\Domains\Services\EnomAnswers::eligible($changed,$clock->now())],'A failed read, including the final check, keeps the rejected-transfer answer available; changed authority does not');
$observe->fault='reject';
$writes=$state->calls;$attempt=$r->attempt;$beforeCredit=\App\Models\Credit::where('user_id',$user->id)->sum('amount');
$clock->instant=$now->modify('+8 days');same(null,$refunds->creditRejected($r->id),'Elapsed time never settles a sent rejection');
$input=$answer($r);$input['charge']='8.951';
try{$settings->run($review($r),$input,$clock->now());expect(false,'Unsettled fee precision must stay with staff');}
catch(\DomainException $e){expect(str_contains($e->getMessage(),'leave this request open'),'Unproved money gives staff a plain next step');}
foreach(['finality','fee','refund','reference','stale','permission','not_processed'] as $fault){
    $input=$answer($r);
    if($fault==='finality'){$input['final_confirmed']=false;}
    if($fault==='fee'){unset($input['charge']);}
    if($fault==='refund'){$input['registrar_refund']='9.00';}
    if($fault==='reference'){$input['order_reference']='9001';}
    if($fault==='stale'){$input['attempt_hash']=str_repeat('0',64);}
    if($fault==='permission'){$role->update(['permissions'=>array_diff($permissions,['admin.invoices.update'])]);}
    if($fault==='not_processed'){$input['outcome']='not_processed';$input['order_reference']='';}
    commerceReject(fn()=>$settings->run($review($r),$input,$clock->now()));$role->update(['permissions'=>$permissions]);
    same(null,$r->fresh()->funding['refund']??null,'Incomplete or conflicting support evidence never pays');
}
$settings->run($review($r),$answer($r),$clock->now());$r->refresh();
same(['rejected','transfer_rejected','cancelled'],[$r->state,$r->domain->status,$r->domain->service->status],'Final rejected transfer closes the native service');
same($attempt,$r->attempt,'Sent attempt is preserved, never rewritten as unsent');
same(['amount'=>'5.95','currency'=>'USD'],$r->cost['actual'],'One net failed fee is absorbed and retained');
same($staff->id,$r->policy['enom_answer']['actor_id'],'Finality and fee evidence retain staff attribution');
same($r->amount,$r->funding['refund']['amount'],'Full customer payment returned without deducting the registrar fee');
same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$beforeCredit)+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($r->amount),
    \Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)\App\Models\Credit::where('user_id',$user->id)->sum('amount')),'Credit bridge returns exact retail once');
commerceReject(fn()=>$settings->run($review($r),$answer($r),$clock->now()));
same(null,$refunds->creditRejected($r->id),'Repeated recovery never funds a second return');
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($r,$r->domain));
commerceReject(fn()=>$inputs->submitCode(tap(\Illuminate\Http\Request::create('/transfer','POST',['transfer_code'=>'Changed']),fn($q)=>$q->setUserResolver(fn()=>$user)),$r->id));
$engine->execute($r->id);same($writes,$state->calls,'Settlement never replays awaiting_code or another purchase');
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($r->domain->domain));
commerceReject(fn()=>$r->update(['policy'=>array_diff_key($r->policy,['enom_answer'=>true])]));
// The same finality proof uses the existing original-receipt port, including recovery after a lost refund reply.
require __DIR__.'/Support/money-receipt.php';
$gateway=\App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Transfer receipt fixture','type'=>'gateway','enabled'=>true]);
$original=$prepare(placeholderComFixtureName('rejected-original-refund-placeholder.com'));$observe->fault='reject_charge';$original=$reconcile->run($original->id);
\App\Models\InvoiceTransaction::where('invoice_id',$original->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>'synthetic-transfer-receipt']);
$lost=false;try{$settings->run($review($original),$answer($original),$clock->now());}
catch(RuntimeException $e){same('Synthetic reply lost after acceptance',$e->getMessage(),'The lost gateway reply is exercised');$lost=true;}
expect($lost,'Lost refund reply must surface');
$refunds->retryPending();$original->refresh();
same('original_payment',$original->funding['refund']['to'],'Capable gateway returns the original receipt');
same(0,\Paymenter\Extensions\Servers\Domains\Services\ReceiptReturns::outstanding($original),'Lost refund reply recovers to a settled return');
same(1,MoneyReceiptGateway::$sends,'Recovery returns one customer payment for one finally failed transfer');
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
// A pending wait stays quiet only while its reads work and will continue.
Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$raised=static function($op)use($clock):bool {
    $d=$op->domain->fresh();$now=$clock->now()->getTimestamp();
    return Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::retained(['domain'=>$d,'operations'=>$d->operations()->get()],$now)!==[];
};
// A pending recovery must not manufacture the separate changed-charge anomaly.
$op=$prepare(placeholderComFixtureName('quiet-wait-placeholder.com'));$observe->charge=$op->attempt['authorized_cost']['amount'];$observe->fault='pending';$reconcile->run($op->id);
expect(!$raised($op),'A healthy pending read stays quiet');
$clock->instant=new DateTimeImmutable('@'.$op->fresh()->policy['reconciliation']['next_at']);
$observe->fault='down';$r=$reconcile->run($op->id);same('pending',$r->policy['reconciliation']['transfer_status'],'A failed read keeps the older status');
expect($raised($op),'A failed read alerts even with an older pending status');
$clock->instant=new DateTimeImmutable('@'.$r->policy['reconciliation']['next_at']);
$observe->fault='pending';$reconcile->run($op->id);expect(!$raised($op),'A read that works ends the failure');
$clock->instant=$clock->now()->modify('+25 hours');expect($raised($op),'A day with no read alerts');
$reconcile->run($op->id);expect(!$raised($op),'A fresh read clears the stale wait');
for($i=0;$i<2;$i++){$clock->instant=new DateTimeImmutable('@'.$op->fresh()->policy['reconciliation']['next_at']);$reconcile->run($op->id);}
same('transfer_pending',$op->fresh()->policy['reconciliation']['reason'],'Healthy pending reads continue past the old limit');
expect(!$raised($op),'Continuing healthy polling stays quiet');
$clock->instant=new DateTimeImmutable('@'.$op->fresh()->policy['reconciliation']['next_at']);$observe->fault='status';$reconcile->run($op->id);
$calls=$observe->calls;$clock->instant=new DateTimeImmutable('@'.$op->fresh()->policy['reconciliation']['next_at']);$reconcile->run($op->id);
same($calls,$observe->calls,'Unexpected results stop after the poll limit');expect($raised($op),'Stopped anomalous polling alerts');
$first=$prepare(placeholderComFixtureName('first-read-down-placeholder.com'));$observe->fault='down';$reconcile->run($first->id);expect($raised($first),'An accepted transfer whose first read fails alerts');
// Our transfer polling allowance yields before exhausting the live account's shared daily guard.
\Paymenter\Extensions\Servers\Domains\Services\EnomLiveCallSchema::install();
$liveAccount=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$binding->serverId)->sole();$uid=$liveAccount->stable_identity;
config(['domains.enom_identity.environment'=>'live','domains.enom_live.catalogue'=>true,'domains.enom_live.site'=>config('app.url'),
    'domains.enom_live.accounts'=>[$uid=>['fingerprint'=>$binding->fingerprint,'daily_call_cap'=>100,'daily_spend_limit_usd'=>'1000.00']]]);
$day=\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::day();
DB::table(\Paymenter\Extensions\Servers\Domains\Services\EnomLiveCallSchema::TABLE)->insert(['account'=>hash('sha256',$uid),'day'=>$day,'used'=>50,'spend_micros'=>0]);
$pollBudget=new \Paymenter\Extensions\Servers\Domains\Services\TransferReadBudget($clock);
try{$pollBudget->acquire($binding,\Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background);expect(false,'Transfer must leave daily headroom');}
catch(\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred){}
same($day,\Paymenter\Extensions\Servers\Domains\Services\EnomLiveCalls::reserve($binding),'Other paid/read work retains the shared daily allowance');
echo "Transfer unique-charge completion, native funding races, terminal refusal and renewal anchors passed.\n"; $nativeSuccessful=true;
