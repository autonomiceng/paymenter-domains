<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/acquisition-retirement.php';
use App\Models\{Credit, Invoice, InvoiceItem, InvoiceTransaction, Service};
use Illuminate\Support\Facades\{Cache, DB, Event, Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionClosureKind, AcquisitionResultSchema, OperationWriter, Transition};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement, PricingRule, Refunds, RuntimeSchema};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
RuntimeSchema::install(); Queue::fake();
$refunds = new Refunds($clock); app()->instance(Refunds::class, $refunds);
$seed=$new(placeholderComFixtureName('scaling-seed-placeholder.com'),paid:false);
$seedDomain=$seed->domain;
$rows=[];
foreach(['operations'=>['domains_operations',$seed->id], 'domains'=>['domains_domains',$seed->domain_id], 'services'=>['services',$seed->service_id], 'invoices'=>['invoices',$seed->invoice_id], 'items'=>['invoice_items',$seed->invoice_item_id], 'orders'=>['orders',Service::findOrFail($seed->service_id)->order_id]] as $k=>[$table,$id]){$rows[$k]=(array)DB::table($table)->find($id);}
$rows['property']=(array)DB::table('properties')->where('model_type',(new Service)->getMorphClass())->where('model_id',$seed->service_id)->where('key','domain')->sole();
DB::table('domains_operations')->where('id',$seed->id)->update(['state'=>'cancelled']);
$account=Account::where('server_id',$seed->server_id)->sole();
$insert=static function(string $table,array $row,array $replace=[]):int {unset($row['id']);return DB::table($table)->insertGetId(array_replace($row,$replace));};
$serial=0;
$make=static function(int $owner,string $mode)use($rows,$seed,$seedDomain,$insert,$clock,&$serial):Operation {
 $serial++;$name='scale-'.$serial.'-placeholder.com';$key='scale-operation-'.$serial;
 $closed=str_starts_with($mode,'closed');
 $orderId=$insert('orders',$rows['orders'],['user_id'=>$owner]);
 $serviceId=$insert('services',$rows['services'],['user_id'=>$owner,'order_id'=>$orderId,'status'=>$closed?'cancelled':'pending']);
 $insert('properties',$rows['property'],['model_id'=>$serviceId,'value'=>$name]);
 $invoiceId=$insert('invoices',$rows['invoices'],['user_id'=>$owner,'number'=>null,'status'=>$closed?'cancelled':'paid']);
 $itemId=$insert('invoice_items',$rows['items'],['invoice_id'=>$invoiceId,'reference_id'=>$serviceId]);
 $c=[...$seed->policy['checkout'],'user_id'=>$owner,'order_id'=>$orderId,'service_id'=>$serviceId,'invoice_id'=>$invoiceId,'invoice_item_id'=>$itemId,'name'=>$name];
 $d=clone $seedDomain;$d->setRawAttributes($rows['domains']);
 $d->forceFill(['user_id'=>$owner,'service_id'=>$serviceId,'domain'=>$name,'reservation_name'=>$closed?null:$name,'status'=>$closed?'cancelled':'registration_pending','snapshot'=>[...$seedDomain->snapshot,'checkout'=>$c]]);
 $domainId=$insert('domains_domains',$d->getAttributes());
 $paymentId=DB::table('invoice_transactions')->insertGetId(['invoice_id'=>$invoiceId,'amount'=>$seed->amount,'fee'=>'0.00','status'=>'succeeded']);
 $op=clone $seed;$op->setRawAttributes($rows['operations']);
 $op->forceFill(['operation_key'=>$key,'domain_id'=>$domainId,'service_id'=>$serviceId,'invoice_id'=>$invoiceId,'invoice_item_id'=>$itemId,'state'=>'rejected','safe_code'=>$closed?UnpaidAcquisitionProof::CODE:'refund_approved','completed_at'=>$clock->now(),'policy'=>[...$seed->policy,'checkout'=>$c]]);
 $proof=['operation_key'=>$key,'generation'=>$op->authority_generation,'attempt_id'=>null];
 if($closed){$proof+=['invoice_id'=>$invoiceId,'transactions'=>[],'unfunded'=>true];}
 $op->forceFill(['attempt'=>null,'funding'=>$mode==='closed_settled'?['late_credits'=>[['payments'=>[['id'=>$paymentId,'amount_minor'=>PricingRule::minor($op->amount)]],'amount'=>$op->amount,'currency'=>'USD','to'=>'account_credit','credit_id'=>null,'at'=>$clock->now()->format(DATE_ATOM)]]]:[]]);
 $persisted=Operation::findOrFail($insert('domains_operations',$op->getAttributes()));
 AcquisitionResultSchema::insert((int) $persisted->id);
 DB::transaction(static function () use ($clock, $closed, $persisted, $proof): void {
     same(Transition::Applied, OperationWriter::at($clock->now())->recordTerminal((int) $persisted->id,
         ($closed ? AcquisitionClosureKind::Unpaid : AcquisitionClosureKind::Unsent)->value, $proof), 'The measured request has a typed terminal');
 });
 return $persisted;
};

// Fixed before measurement. Required rows come from the fixture's exact invoice identities.
// These constants do not depend on the collections selected by HoldingLocks.
const MONEY_SCALE_ROWS = 4;
const MONEY_SCALE_STATEMENTS = 100;
const MONEY_SCALE_PAGE_STATEMENTS = 20;
const MONEY_SCALE_ROW_STATEMENTS = 10;
// Frozen complete-pass and per-decision SQL ceilings, originally measured at 11ed0dd8.
// The optional research matrix uses the same process: only the first N5/C1 refund is cold.
const MONEY_SCALE_SQL_CEILINGS = [
    'refund:fresh:N5:C1' => [298, 61], // re-frozen at 1f6e4bc9: #584 added one statement to the cold first refund
    'refund:settled:N5:C1' => [201, 40],
    'closed:fresh:N5:C1' => [225, 44], // re-frozen: the deferred refund queue adds two statements per pass, Schema::hasTable(domains_money_work) and the queued() read; the renewal receipt sweep adds one
    'closed:settled:N5:C1' => [269, 53], // re-frozen: the deferred refund queue adds two statements per pass, Schema::hasTable(domains_money_work) and the queued() read
    'refund:fresh:N15:C1' => [886, 59],
    'refund:settled:N15:C1' => [601, 40],
    'closed:fresh:N15:C1' => [665, 44], // re-frozen: the deferred refund queue adds two statements per pass, Schema::hasTable(domains_money_work) and the queued() read; the renewal receipt sweep adds one
    'closed:settled:N15:C1' => [799, 53], // re-frozen: the deferred refund queue adds two statements per pass, Schema::hasTable(domains_money_work) and the queued() read
    'refund:fresh:N5:C2' => [591, 59],
    'refund:settled:N5:C2' => [401, 40],
    'closed:fresh:N5:C2' => [445, 44], // re-frozen: the deferred refund queue adds two statements per pass, Schema::hasTable(domains_money_work) and the queued() read; the renewal receipt sweep adds one
    'closed:settled:N5:C2' => [534, 53], // re-frozen: the deferred refund queue adds two statements per pass, Schema::hasTable(domains_money_work) and the queued() read
    'refund:fresh:N5:C4' => [1181, 59],
    'refund:settled:N5:C4' => [801, 40],
    'closed:fresh:N5:C4' => [884, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N5:C4' => [1064, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'refund:fresh:N20:C1' => [1181, 59],
    'refund:settled:N20:C1' => [801, 40],
    'closed:fresh:N20:C1' => [884, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N20:C1' => [1064, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'refund:fresh:N20:C2' => [2361, 59],
    'refund:settled:N20:C2' => [1601, 40],
    'closed:fresh:N20:C2' => [1764, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N20:C2' => [2124, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'refund:fresh:N20:C4' => [4721, 59],
    'refund:settled:N20:C4' => [3201, 40],
    'closed:fresh:N20:C4' => [3524, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N20:C4' => [4244, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'refund:fresh:N50:C1' => [2951, 59],
    'refund:settled:N50:C1' => [2001, 40],
    'closed:fresh:N50:C1' => [2204, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N50:C1' => [2654, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'refund:fresh:N50:C2' => [5901, 59],
    'refund:settled:N50:C2' => [4001, 40],
    'closed:fresh:N50:C2' => [4408, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N50:C2' => [5308, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'refund:fresh:N50:C4' => [11802, 59],
    'refund:settled:N50:C4' => [8002, 40],
    'closed:fresh:N50:C4' => [8812, 44], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
    'closed:settled:N50:C4' => [10612, 53], // re-frozen: the deferred refund queue adds two statements per page, Schema::hasTable(domains_money_work) and the queued() read; same count at 27f31f26
];
const MONEY_SCALE_HISTORY_CEILINGS = [
    'refund:fixed-target' => [[40, 1, 1, 1], [40]],
    'closed:fixed-target' => [[53, 1, 1, 2], [53]],
];
final class MoneyScaleMeter
{
    public static bool $active = false;
    public static array $total = [], $decisions = [], $domains = [];
    public static function zero(): array { return ['sql'=>0, 'Invoice'=>0, 'InvoiceItem'=>0, 'InvoiceTransaction'=>0]; }
    public static function start(array $ops): void
    {
        self::$total = self::zero(); self::$decisions = []; self::$domains = [];
        foreach ($ops as $op) {
            self::$decisions[$op->id] = self::zero(); self::$domains[$op->domain_id] = $op->id;
        }
        self::$active = true;
    }
    public static function hit(string $kind): void
    {
        if (!self::$active) { return; }
        self::$total[$kind]++;
        // Attribute the entire real caller, including repeated contexts and inspection fallthrough.
        // No production hooks or query-predicate assumptions are needed. Arguments stay in process.
        $id = null;
        foreach (debug_backtrace(0, 64) as $frame) {
            if (($frame['class'] ?? '') === Refunds::class) {
                if ($frame['function'] === 'creditRejected') { $id = $frame['args'][0]; break; }
                if ($frame['function'] === 'creditClosed') { $id = $frame['args'][0]->id; break; }
            }
            if (($frame['class'] ?? '') === AcquisitionRetirement::class && $frame['function'] === 'inspect') {
                $id = self::$domains[$frame['args'][0]] ?? null; break;
            }
        }
        if ($id !== null) {
            expect(isset(self::$decisions[$id]), 'Every measured decision belongs to the scheduled fixture');
            self::$decisions[$id][$kind]++;
        }
    }
}
DB::listen(static fn()=>MoneyScaleMeter::hit('sql'));
foreach ([Invoice::class, InvoiceItem::class, InvoiceTransaction::class] as $model) {
    Event::listen('eloquent.retrieved: '.$model, static fn()=>MoneyScaleMeter::hit(class_basename($model)));
}
$failures = [];
$bound = static function(bool $ok, string $key, array $facts) use (&$failures): void {
    if (!$ok) { $failures[] = $key; echo 'MONEY_SCALE_RED '.json_encode(['case'=>$key, ...$facts], JSON_THROW_ON_ERROR)."\n"; }
};
$checkCeilings = static function(string $key, array $counts, array $ceilings) use ($bound): void {
    foreach (array_keys(MoneyScaleMeter::zero()) as $index=>$class) {
        $ceiling = $ceilings[$index] ?? null;
        if ($ceiling === null) { continue; }
        $bound($counts[$class] <= $ceiling, $key.':'.$class.':ceiling', ['actual'=>$counts[$class], 'ceiling'=>$ceiling]);
    }
};
$measure = static function(string $label, array $ops, array $required, Closure $run) use ($bound): mixed {
    MoneyScaleMeter::start($ops); $start = hrtime(true);
    try { [$result, $pages] = $run(); }
    finally { MoneyScaleMeter::$active = false; }
    $counts = MoneyScaleMeter::$total; $decisions = MoneyScaleMeter::$decisions;
    [$passCeiling, $decisionCeiling] = MONEY_SCALE_SQL_CEILINGS[$label];
    $bound($counts['sql'] <= $passCeiling, $label.':pass:sql', ['actual'=>$counts['sql'], 'ceiling'=>$passCeiling]);
    $seconds = (hrtime(true)-$start)/1e9;
    $sum = ['Invoice'=>0, 'InvoiceItem'=>0, 'InvoiceTransaction'=>0];
    foreach ($required as $rows) { foreach ($sum as $class=>$n) { $sum[$class] += $rows[$class]; } }
    $statementLimit = MONEY_SCALE_STATEMENTS*count($ops) + MONEY_SCALE_PAGE_STATEMENTS*$pages + MONEY_SCALE_ROW_STATEMENTS*array_sum($sum);
    expect($counts['sql'] <= $statementLimit, 'Fixed complete-pass SQL bound: '.$label);
    foreach ($sum as $class=>$rows) {
        $bound($counts[$class] <= MONEY_SCALE_ROWS*$rows, $label.':pass:'.$class, ['actual'=>$counts[$class], 'limit'=>MONEY_SCALE_ROWS*$rows]);
    }
    foreach ($decisions as $id=>$countsForDecision) {
        $bound($countsForDecision['sql'] <= $decisionCeiling, $label.':decision:'.$id.':sql', ['actual'=>$countsForDecision['sql'], 'ceiling'=>$decisionCeiling]);
        expect($countsForDecision['sql'] > 0, 'Each scheduled decision was executed and measured');
        expect($countsForDecision['sql'] <= MONEY_SCALE_STATEMENTS + MONEY_SCALE_PAGE_STATEMENTS + MONEY_SCALE_ROW_STATEMENTS*array_sum($required[$id]), 'Fixed per-decision SQL bound');
        foreach ($sum as $class=>$rows) {
            $bound($countsForDecision[$class] <= MONEY_SCALE_ROWS*$required[$id][$class], $label.':decision:'.$id.':'.$class,
                ['actual'=>$countsForDecision[$class], 'limit'=>MONEY_SCALE_ROWS*$required[$id][$class]]);
        }
    }
    echo 'MONEY_SCALE_CELL '.json_encode(['case'=>$label, 'pages'=>$pages, 'required'=>$sum, 'counts'=>$counts,
        'decision_min'=>array_combine(array_keys($counts),array_map(static fn($k)=>min(array_column($decisions,$k)), array_keys($counts))),
        'decision_max'=>array_combine(array_keys($counts),array_map(static fn($k)=>max(array_column($decisions,$k)), array_keys($counts))), 'seconds'=>$seconds], JSON_THROW_ON_ERROR)."\n";
    return $result;
};
$requiredFor = static function(array $ops): array {
    $required = [];
    foreach ($ops as $op) {
        // Assert independently retained identities, not the implementation's loaded model collections.
        same(1, DB::table('invoices')->where('id',$op->invoice_id)->count(), 'Fixture target invoice exists');
        $required[$op->id] = ['Invoice'=>1,
            'InvoiceItem'=>DB::table('invoice_items')->where('invoice_id',$op->invoice_id)->count(),
            'InvoiceTransaction'=>DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->count()];
        same(1,$required[$op->id]['InvoiceItem'],'One exact fixture line');
        same(1,$required[$op->id]['InvoiceTransaction'],'One exact fixture receipt');
    }
    return $required;
};
$pass = static function(array $ops, bool $closed) use ($refunds,$account): array {
    $pages = 0; $done = 0;
    if ($closed) {
        $cursor = 'domains.closed-credit-cursor.'.$account->server_id; Cache::forget($cursor);
        do { $done += count($refunds->creditRetired($account)); $pages++; } while (Cache::get($cursor,0) !== 0);
    } else {
        $after = $ops[0]->id-1;
        // Queue selection includes settled replays. Advance by the fixture identities, not return count.
        foreach (array_chunk($ops,100) as $page) {
            $done += $refunds->retryPending($after,100); $after = end($page)->id; $pages++;
        }
    }
    same($closed ? intdiv(count($ops),100)+1 : (int)ceil(count($ops)/100), $pages, 'Complete pass consumes all cursor pages');
    return [$done,$pages];
};
$checkMoney = static function(array $ops, array $owners, bool $closed, array $before = []): array {
    $funding = [];
    foreach ($owners as $owner) {
        $owned = array_filter($ops,static fn($op)=>$op->domain->user_id === $owner);
        same(array_sum(array_map(static fn($op)=>PricingRule::minor($op->amount),$owned)),
            PricingRule::minor(Credit::where('user_id',$owner)->where('currency_code','USD')->sole()->amount), 'Exact credit per owner');
    }
    foreach ($ops as $op) {
        $saved = $op->fresh(); $funding[$op->id] = $saved->funding;
        if ($before) { same($before[$op->id],$saved->funding,'Settled replay preserves complete immutable funding'); }
        $plans = $closed ? $saved->funding['late_credits'] ?? [] : [$saved->funding['refund'] ?? []];
        same(1,count($plans),'Exactly one retained credit plan'); $plan = $plans[0];
        same($op->amount,$plan['amount'],'Plan amount exact'); same('USD',$plan['currency'],'Plan currency exact');
        same('account_credit',$plan['to'],'Receipt returns as account credit');
        $receipt = DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->sole();
        same([['id'=>$receipt->id,'amount_minor'=>PricingRule::minor($op->amount)]],$plan['payments'],'Plan covers the exact native receipt identity');
        same(PricingRule::minor($op->amount),PricingRule::minor((string)$receipt->amount),'Native receipt remains unchanged'); same('succeeded',$receipt->status,'Native receipt stays successful');
        same('cancelled',Service::findOrFail($op->service_id)->status,'Credited native service is closed');
        same(false,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Valid money fixture needs no repair');
    }
    return $funding;
};
foreach ($moneyScaleCells as [$n,$customers]) { foreach ([false,true] as $closed) {
    $ops = []; $owners = [];
    for ($c=0;$c<$customers;$c++) {
        $owner = commerceUser(); $owners[] = $owner->id;
        Credit::create(['user_id'=>$owner->id,'currency_code'=>'USD','amount'=>'0.00']);
        for ($j=0;$j<$n;$j++) { $ops[] = $make($owner->id,$closed ? 'closed_fresh' : 'refund_fresh'); }
    }
    $required = $requiredFor($ops);
    foreach (['fresh','settled'] as $phase) {
        $label = ($closed ? 'closed' : 'refund').':'.$phase.':N'.$n.':C'.$customers;
        $done = $measure($label,$ops,$required,static fn()=>$pass($ops,$closed));
        same($phase === 'fresh' ? count($ops) : 0,$done,'Complete pass returns each fresh payment exactly once');
        $funding = $checkMoney($ops,$owners,$closed,$phase === 'settled' ? $funding : []);
    }
    DB::table('domains_operations')->whereIn('id',array_column($ops,'id'))->update(['state'=>'cancelled']);
}}
// Keep the exact target fixed while unrelated invoices/items/payments grow on the same owner.
foreach ($moneyScaleHistory ? [false,true] : [] as $closed) {
    $owner = commerceUser(); Credit::create(['user_id'=>$owner->id,'currency_code'=>'USD','amount'=>'0.00']);
    $target = $make($owner->id,$closed ? 'closed_fresh' : 'refund_fresh');
    $pass([$target],$closed); $before = $checkMoney([$target],[$owner->id],$closed);
    $previous = null;
    foreach ([0,10] as $history) {
        for ($j=0;$j<$history;$j++) {
            $other = $make($owner->id,$closed ? 'closed_fresh' : 'refund_fresh');
            DB::table('domains_operations')->where('id',$other->id)->update(['state'=>'cancelled']);
        }
        MoneyScaleMeter::start([$target]);
        try { $pass([$target],$closed); } finally { MoneyScaleMeter::$active = false; }
        $counts = MoneyScaleMeter::$decisions[$target->id];
        $label = ($closed ? 'closed' : 'refund').':fixed-target';
        // Freeze both endpoints: allowing the before count to grow would conceal regressions.
        $checkCeilings($label.($history === 0 ? ':before' : ':after'), $counts,
            MONEY_SCALE_HISTORY_CEILINGS[$label][$history === 0 ? 0 : 1]);
        if ($previous !== null) {
            expect($counts['sql'] <= $previous['sql'], 'Unrelated owner history does not add per-decision SQL statements');
            foreach (['Invoice','InvoiceItem','InvoiceTransaction'] as $class) {
                $key = $label.':history:'.$class;
                $bound($counts[$class] <= $previous[$class],$key,['before'=>$previous[$class],'after'=>$counts[$class]]);
            }
            echo 'MONEY_SCALE_HISTORY '.json_encode(['case'=>$label,'before'=>$previous,'after'=>$counts],JSON_THROW_ON_ERROR)."\n";
        }
        $previous = $counts; $checkMoney([$target],[$owner->id],$closed,$before);
    }
    DB::table('domains_operations')->where('id',$target->id)->update(['state'=>'cancelled']);
}
expect($failures === [], 'Money scaling exceeded: '.implode(', ', $failures));
same(0,$writer->calls,'No provider writes'); $nativeSuccessful = true;
