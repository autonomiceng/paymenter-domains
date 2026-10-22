<?php
// Synthetic vendor facts and retained slice28 financial gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/transfer-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, HistoryAcceptances, OperationReconciliation, RegistrarRegistry};
$observe=(object)['name'=>'','fault'=>'','status'=>'Transfer Completed','calls'=>[],'during'=>null,'expiration'=>['expiration'=>'2028-01-01']];
$wire=new class($observe) implements DeadlineTransport {
    public function __construct(private $s) {}
    public function send($e,$c,$r,#[SensitiveParameter] string $k): TransportResponse { throw new LogicException; }
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $k,int $t): TransportResponse {
        same(0,DB::transactionLevel(),'Read outside locks'); expect($t>0 && $t<=25000,'Read deadline bounded'); $s=$this->s; $s->calls[]=$r->command;
        if ($s->during) { $hook=$s->during; $s->during=null; $hook(); }
        $row=['description'=>$s->name.' - transfer','years_qty'=>'1','status'=>'Processed','subtotal'=>'99.00'];
        if ($s->fault==='credited') { $row['credited_amount']='0.00'; }
        if ($s->fault==='unknowncharge') { $row['status']='Pending'; }
        $reply=['code'=>300,'detail'=>'synthetic']+match($r->command) {
            'checkTransferStatus'=>['date'=>'2026-10-18','status'=>$s->status,'message'=>'synthetic']+$s->expiration,
            'getDomainInfo'=>['created'=>'2020-01-01','expires'=>$s->fault==='short'?'2027-01-01':'2028-01-01',
                'status'=>$s->fault==='inactive'?'active':'Active','auto_renew'=>$s->fault==='auto'?'Yes':'No',
                'private'=>$s->fault==='privacy'?'Yes':'No','locked'=>'Yes'],
            'listOrders'=>['order'=>in_array($s->fault,['nocharge','reject','rejectowned'],true)?[]:[['order_number'=>'9001']]]+($s->fault==='paged'?['pager'=>['page'=>1]]:[]),
            'orderDetails'=>['order_number'=>'9001','order_date'=>$s->fault==='late'?'2026-10-18 05:10:00':'2026-10-18 05:00:01',
                'total'=>'99.00','order_details'=>$s->fault==='duplicate'?[$row,$row]:[$row]],
            'getPrices'=>['com'=>['registration'=>'8.95','transfer'=>'8.95','renew'=>'8.95']],
            'getAccountBalance'=>['balance'=>'100.00'],
            default=>throw new LogicException('Unexpected read'),
        };
        if ($r->command==='getDomainInfo' && in_array($s->fault,['owner','reject','rejectcharge'],true)) { $reply=['code'=>200,'detail'=>'synthetic']; }
        return new TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>$reply]));
    }
};
$registry=new RegistrarRegistry; $registry->register('namesilo',['token'],$verifier,fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),['renew'],$clock));
$readBindings=new AccountBindings($registry); $reconcile=new OperationReconciliation($claims,$readBindings,$clock);
$prepare=static function($label)use($newTransfer,$makeTransfer,&$engine,&$binding,$clock,$now,$state,$observe){
    $clock->instant=$now; $state->rdapUnknown=false; $state->mode=''; $op=$newTransfer(placeholderComFixtureName('ns-transfer-'.$label.'-placeholder.com'),1);
    $payload=$op->payload; unset($payload['privacy']); $op->update(['payload'=>$payload]); // Actual checkout defaults privacy off at dispatch.
    $makeTransfer(1); $op=$engine->execute($op->id); same(false,$op->attempt['transfer_admission']['privacy'],'Dispatch default frozen at seal');
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,86400,'namesilo_transfer_v1'));
    $observe->name=$op->domain->domain; $observe->fault=''; $observe->status='Transfer Completed'; $observe->expiration=['expiration'=>'2028-01-01']; $observe->calls=[];
    $clock->instant=$now->modify('+6 minutes'); return $op;
};
$unresolved=static function(array $faults)use($prepare,$observe,$clock,$now,$reconcile,&$binding): void {
    foreach ($faults as $fault) {
        $op=$prepare($fault); $observe->fault=$fault;
        if (str_starts_with($fault,'needs')) { $observe->status='Domain is Locked'; $observe->fault=$fault==='needsowned'?'rejectowned':'rejectcharge'; }
        if ($fault==='expiration') { $observe->expiration=['expiration'=>'2029-01-01']; }
        if ($fault==='badexpiry') { $observe->expiration=['expiration'=>'2028-02-30']; }
        if ($fault==='nullexpiry') { $observe->expiration=['expiration'=>null]; }
        if ($fault==='pending') { $observe->status='Pending at Registry'; }
        if ($fault==='unknown') { $observe->status='Completed'; }
        if (str_starts_with($fault,'reject')) { $observe->status='Transfer Rejected'; }
        if ($fault==='nohistory') { App\Models\Server::find($op->server_id)->settings()->where('key',HistoryAcceptances::KEY.'_transfer')->delete(); }
        if ($fault==='lag') { HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',600,86400,'namesilo_transfer_v1')); }
        if ($fault==='retention') { $clock->instant=$now->modify('+2 days'); }
        if ($fault==='baseline') { $a=$op->attempt; $a['transfer_admission']['base_expiry']=null; $op->forceFill(['attempt'=>$a])->saveQuietly(); }
        if ($fault==='sealedprivacy') { $a=$op->attempt; unset($a['transfer_admission']['privacy']); $op->forceFill(['attempt'=>$a])->saveQuietly(); }
        if ($fault==='reversed') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        if ($fault==='race') { $observe->during=fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        $r=$reconcile->run($op->id); same('uncertain',$r->state,'Missing or contradictory proof stays unresolved'); same(null,$r->cost['actual']??null,'No inferred charge');
        same(null,$r->domain->provider_expires_at,'No inferred expiry'); same(null,$r->domain->renewal_policy['billing_anchor']??null,'No premature anchor');
        expect($r->policy['reconciliation']['staff_alert'],'Unresolved staff alert retained'); same(null,$r->attempt['transfer_terminal']??null,'No terminal proof');
        if (str_starts_with($fault,'reject')) { same('transfer_rejection_contradiction',$r->policy['reconciliation']['reason'],'Owned/charged rejection contradiction'); }
        if (str_starts_with($fault,'needs')) { same('transfer_status_contradiction',$r->policy['reconciliation']['reason'],'Owned/charged needs-action contradiction'); }
    }
};
