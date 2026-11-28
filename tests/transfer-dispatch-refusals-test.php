<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/transfer-dispatch-fixture.php';
require __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/fixture-checkpoint.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Illuminate\Support\Facades\{Auth, Cache, Queue};
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionLane, AcquisitionResult};
use Paymenter\Extensions\Servers\Domains\Services\{BalanceRetries, FundsWaits, OperationTerminalProof, PricingRule, Refunds, StaffOperationReview, StaffOperations, TransferInputs};
staffPanel();app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(Refunds::class,new Refunds($clock));
$permissions=[Staff::VIEW,'admin.domains.manage','admin.domains.fund','admin.invoices.update'];foreach(array_keys(StaffOperationReview::ACTIONS) as $action){$permissions[]=StaffOperationReview::permission($action);}
$staff=staffUser($permissions);Illuminate\Support\Facades\Auth::setUser($staff);$staffOperations=new StaffOperations($clock);
// The same staff action the request page runs, with its real ticket.
$act=fn($op,$action)=>$staffOperations->run($staff,$op->domain_id,$op->id,$action,StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock),'Reviewed current evidence');
$state->mode=''; $state->calls=[]; $op=$newTransfer(placeholderComFixtureName('no-paid-placeholder.com'),0,false); $makeTransfer(); $engine->execute($op->id); same([],$state->calls,'Unpaid transfer never writes');
$op=$newTransfer(placeholderComFixtureName('no-code-placeholder.com'),0,true,false); $makeTransfer(); same('awaiting_code',$engine->execute($op->id)->state,'Missing code retains editable input state'); same([],$state->calls,'Missing code never writes');
$slot='domains-write-slot:'.$binding->fingerprint; same(null,Illuminate\Support\Facades\Cache::get($slot),'Missing code reserves no slot');
foreach ([true,false] as $enabled) {
    $makeTransfer(0,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(transfer:$enabled)); Illuminate\Support\Facades\Cache::put($slot,'other-dispatch',25);
    same('awaiting_code',$engine->execute($op->id)->state,'Busy slot or disabled feature cannot trap missing code');
    same('other-dispatch',Illuminate\Support\Facades\Cache::get($slot),'Other dispatch slot untouched');
    same('awaiting_code',$claims->holdUnclaimed($op->id,'write_rate_limited')->state,'Defensive unclaimed hold preserves editable input');
}
$request=Illuminate\Http\Request::create('/internal-transfer-input','POST'); $request->request->set('transfer_code',str_repeat('x',12)); $request->setUserResolver(fn()=>$user); $inputs->submitCode($request,$op->id);
expect(Paymenter\Extensions\Servers\Domains\Services\TransferInputs::validCode($op->fresh()->payload['epp']??null),'Later owner input remains editable');
$op=$newTransfer(placeholderComFixtureName('disabled-transfer-placeholder.com')); $makeTransfer(0,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration:true));
same('held',$engine->execute($op->id)->state,'Default off transfer gate'); same([],$state->calls,'Registration permission grants no transfer permission');
$funded=$newTransfer(placeholderComFixtureName('refuse-enom-placeholder.com'));
$restoreFunded=nativeFixtureCheckpoint();$enomQuery=$state->query;
// The final refusal's refund remains in the ledger for the separate deferred order below.
foreach (['premium','unknown','funds','reverse','rules','profile','rdap404','changed'] as $fault) {
    $restoreFunded();$state->query=$enomQuery;
    $i=$fault==='profile'?1:0;
    $op=$i===1?$newTransfer(placeholderComFixtureName('refuse-profile-placeholder.com'),1):$funded->fresh();
    $makeTransfer($i); $state->calls=[]; $state->reads=0;
    $state->premium=$fault==='premium'?true:($fault==='unknown'?null:false); $state->funds=$fault==='funds'?'1.00':'100.00'; $state->mode=$fault==='profile'?'default':'';
    $state->rdap404=$fault==='rdap404';
    $state->during=static function($read)use($fault,$op,$state){
        if ($read!==2) { return; }
        if ($fault==='changed') { $state->available=false; }
        if ($fault==='reverse') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        if ($fault==='rules') { App\Models\Server::find($op->server_id)->settings()->where('key','domains_transfer_endings')->update(['value'=>'net']); }
    };
    $r=$engine->execute($op->id); same('held',$r->state,'Changed admission held before seal'); same('absent',$r->attempt['seal'],'No send seal');
    $cause=['premium'=>'premium_name','unknown'=>'registrar_read_failed','funds'=>'registrar_balance_insufficient','changed'=>'name_unavailable'][$fault]??null;
    if($cause){same($cause,$r->safe_code,'Transfer refusal keeps its cause: '.$fault);}
    same([],array_values(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Refused admission sends nothing');
    if ($fault==='funds') { same('registrar_balance_insufficient',$r->safe_code,'A transfer shortfall has its own honest hold reason'); }
    if ($fault==='rdap404') { same('transfer_not_registered',$r->safe_code,'Fresh domain-endpoint404 retains distinct unsent hold'); commerceReject(fn()=>OperationTerminalProof::encode($claims,$r->id)); commerceReject(fn()=>$act($r,'refund')); same('held',$r->fresh()->state,'A registry 404 still needs its fresh disposition'); }
    $state->rdap404=false; $state->during=null; $state->premium=false; $state->funds='100.00'; $state->available=true; $state->mode='';
    App\Models\Server::find($op->server_id)->settings()->where('key','domains_transfer_endings')->update(['value'=>'com']);
    if ($fault==='changed') {
        Illuminate\Support\Facades\Auth::setUser($staff);same('refund',RequestFacts::notice($r)['action'],'A final refusal offers Cancel and refund');
        $invoiceBefore = App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal();
        $itemsBefore = Illuminate\Support\Facades\DB::table('invoice_items')->orderBy('id')->get()->toJson();
        $transactionsBefore = Illuminate\Support\Facades\DB::table('invoice_transactions')->orderBy('id')->get()->toJson();
        same(0, App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->count(), 'No pre-existing credit hides a missing refund');
        $act($r,'refund');$terminal=$r->fresh();same('rejected',$terminal->state,'Cancel closes a claimed, unsealed, unsent transfer');
        expect(OperationTerminalProof::safe($terminal,$terminal->domain),'Cancel ends on the locked terminal proof');
        same($r->attempt['claim_key'],$terminal->attempt['claim_key'],'Cancel keeps the original claim audit');
        $credit = App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->sole();
        same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount), \Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$credit->amount), 'One exact retained account credit');
        same('paid', App\Models\Invoice::findOrFail($op->invoice_id)->status, 'Refund retains paid invoice status');
        same(App\Enums\InvoiceTransactionStatus::Succeeded, App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole()->status, 'Original successful payment remains');
        same($invoiceBefore, App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal(), 'Refund retains invoice bytes');
        same($itemsBefore, Illuminate\Support\Facades\DB::table('invoice_items')->orderBy('id')->get()->toJson(), 'Refund retains issued item bytes');
        same($transactionsBefore, Illuminate\Support\Facades\DB::table('invoice_transactions')->orderBy('id')->get()->toJson(), 'Refund retains transaction bytes');
        $afterCredit = nativeFinancialSnapshot($r);
        Illuminate\Support\Facades\DB::beginTransaction();
        try {
            Illuminate\Support\Facades\DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['description'=>'Synthetic changed line']);
            expect($afterCredit !== nativeFinancialSnapshot($r), 'Financial snapshot detects changed invoice items');
        } finally { Illuminate\Support\Facades\DB::rollBack(); }
        same($afterCredit, nativeFinancialSnapshot($r), 'Snapshot probe rolls back all item changes');
        same(null,$refunds->creditRejected($r->id),'Credit once');
        same($afterCredit, nativeFinancialSnapshot($r), 'Replay retains exact credit, invoice, item and transaction rows');
    }
}
// A local rate-limit deferral before the final refusal is not send evidence; Cancel still works.
$op=$newTransfer(placeholderComFixtureName('refuse-deferred-placeholder.com'));$makeTransfer();$state->calls=[];$state->reads=0;
same('queued',$claims->defer($claims->claim($op->id)[0])->state,'A local deferral requeues');
$state->premium=true;$r=$engine->execute($op->id);$state->premium=false;
same(['held','premium_name',1],[$r->state,$r->safe_code,count($r->attempt['capacity_deferrals'])],'A refusal after a deferral keeps its cause');
$act($r,'refund');same('rejected',$r->fresh()->state,'Cancel works after a local deferral');
expect(OperationTerminalProof::safe($r->fresh(),$r->domain->fresh()),'A deferral keeps the terminal proof');
$forged = $r->fresh();
$malformed = [...$forged->attempt, 'capacity_deferrals' => ['not-a-time']];
commerceReject(fn () => $forged->update(['attempt' => $malformed]));
unset($forged->id); $forged->attempt = $malformed;
expect(!OperationTerminalProof::safe($forged, $r->domain), 'A malformed deferral fails the retained terminal proof');
same(1,App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->count(),'Credit stays on one row');
// No automatic retry here, so the failed read has to keep a Release that works.
$op=$newTransfer(placeholderComFixtureName('refuse-unsupported-placeholder.com'),1);$makeTransfer(1);$state->calls=[];$state->reads=0;$state->premium=null;$r=$engine->execute($op->id);$state->premium=false;
same(['held','registrar_read_failed'],[$r->state,$r->safe_code],'An unsupported transfer keeps its read cause');expect(!BalanceRetries::pending($r),'No retry is promised for it');
$act($r,'release');same('queued',$r->fresh()->state,'Release readmits a transfer with no automatic retry');same($r->attempt['claim_key'],$r->fresh()->attempt['claim_key'],'Release keeps the claim audit');
$makeTransfer(1);$state->premium=null;$r=$engine->execute($op->id);same(['held','registrar_read_failed'],[$r->state,$r->safe_code],'The released order really runs its checks again');$state->premium=false;
// The same transfer as an old catch-all hold: no automatic look here, so the words and Release say so.
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'write_acceptance_required']);$r=$op->fresh();
Illuminate\Support\Facades\Auth::setUser($staff);$notice=RequestFacts::notice($r);same(['Not sent: reason not saved.','Release hold queues the test transfer to send to NameSilo. Release to check this order again.','release',null],[$notice['title'],$notice['body'],$notice['action'],$notice['link']??null],'An old hold with no automatic look claims no cause');
$act($r,'release');$makeTransfer(1);$state->available=false;$r=$engine->execute($op->id);same(['held','name_unavailable'],[$r->state,$r->safe_code],'Release finds the real answer');$state->available=true;

// A paid NameSilo transfer with a saved code can be reviewed during an open funds wait.
$restoreWait = nativeFixtureCheckpoint();
$setWaitTime = static function (DateTimeImmutable $instant) use ($clock, $ports): void {
    $clock->instant = $instant;
    Carbon\Carbon::setTestNow($instant);
    Carbon\CarbonImmutable::setTestNow($instant);
    Cache::flush();
    activeCommerceFacts($instant, $instant->modify('+1 day'), $ports);
};
$creditTotal = static fn () => App\Models\Credit::where('user_id', $user->id)->where('currency_code', 'USD')->get()
    ->sum(static fn ($credit) => PricingRule::minor((string) $credit->amount));
$observed = [];
foreach (['cutoff', 'day-two'] as $case) {
    $restoreWait();
    $setWaitTime($now);
    Queue::fake([RunOperation::class]);
    $op = $newTransfer(placeholderComFixtureName('namesilo-funds-'.$case.'-placeholder.com'), 1);
    $makeTransfer(1);
    $state->calls = [];
    $state->funds = '1.00';
    $held = $engine->execute($op->id);
    same(['held', 'registrar_balance_insufficient', AcquisitionLane::FundsWait],
        [$held->state, $held->safe_code, AcquisitionResult::continuation($held)->lane], 'Verified NameSilo shortage opens the manual funds wait');
    $saved = AcquisitionResult::continuation($held);
    same($clock->now()->getTimestamp(), $saved->shortfallAt?->getTimestamp(), 'Shortfall starts when first confirmed');
    $cutoff = $saved->shortfallAt->modify('+3 days');
    $reviewAt = $case === 'cutoff' ? $cutoff->modify('-1 second') : $saved->shortfallAt->modify('+2 days');
    $setWaitTime($reviewAt);
    Auth::setUser($staff);
    expect(TransferInputs::beforeDeadline($held, $clock->now()), 'Saved transfer code still has time');
    expect(OperationActions::relevant('release', $held->fresh()), 'Staff can review a NameSilo hold before cutoff');
    $ticket = StaffOperationReview::ticket($staff->id, $held->domain_id, $held->id, 'release',
        StaffOperationReview::stamp($held->fresh(), $held->domain->fresh()), $clock);
    $before = $creditTotal();
    $queuedJobs = static fn () => Queue::pushed(
        RunOperation::class, static fn (RunOperation $job) => $job->operationId === $held->id,
    )->count();
    $pending = $queuedJobs();
    if ($case === 'cutoff') {
        $setWaitTime($cutoff);
        $state->funds = '100.00';
    }
    Auth::setUser($staff);
    $offered = OperationActions::relevant('release', $held->fresh());
    $refused = false;
    try { $staffOperations->run($staff, $held->domain_id, $held->id, 'release', $ticket, 'Reviewed current evidence'); }
    catch (DomainException) { $refused = true; }
    $after = $held->fresh();
    $afterRelease = AcquisitionResult::continuation($after);
    $priorAudit = $after->policy['staff_actions'] ?? [];
    $newDelivery = $queuedJobs() > $pending;
    $makeTransfer(1);
    $afterDelivery = $engine->execute($held->id);
    $afterDeliveryWait = AcquisitionResult::continuation($afterDelivery);
    $setWaitTime($cutoff);
    $account = Account::where('server_id', $held->server_id)->sole();
    FundsWaits::sweep($account, $clock->now());
    FundsWaits::sweep($account, $clock->now());
    $terminal = $held->fresh();
    $observed[$case] = [
        'offered' => $offered,
        'refused' => $refused,
        'after_release' => $after->state,
        'window_retained' => $saved->window == $afterRelease->window,
        'start_retained' => $saved->shortfallAt == $afterRelease->shortfallAt,
        'delivery_kept_start' => $saved->shortfallAt == $afterDeliveryWait->shortfallAt,
        'review_audits' => count($priorAudit),
        'refusal_queued_delivery' => $refused && $newDelivery,
        'registrar_sends' => count(array_filter($state->calls, static fn ($command) => $command === 'transferDomain')),
        'terminal' => $terminal->state,
        'exact_credit' => $creditTotal() - $before === PricingRule::minor($held->amount),
        'terminal_cleared_start' => AcquisitionResult::continuation($terminal)->shortfallAt === null,
    ];
}
$restoreWait();
$setWaitTime($now);
$state->funds = '100.00';
$atCutoff = [
    'offered' => false, 'refused' => true, 'after_release' => 'held', 'window_retained' => true,
    'start_retained' => true, 'delivery_kept_start' => true, 'review_audits' => 0, 'refusal_queued_delivery' => false,
    'registrar_sends' => 0, 'terminal' => 'rejected', 'exact_credit' => true, 'terminal_cleared_start' => true,
];
$dayTwo = [...$atCutoff, 'offered' => true, 'refused' => false, 'after_release' => 'queued',
    'window_retained' => false, 'review_audits' => 1];
same(['cutoff' => $atCutoff, 'day-two' => $dayTwo], $observed,
    'Reviewed NameSilo Release keeps the original funds cutoff: '.json_encode($observed));

$op = $newTransfer(placeholderComFixtureName('expired-registry-refusal-placeholder.com'));
$makeTransfer();
$state->rdap404 = true;
$state->calls = [];
$held = $engine->execute($op->id);
same('transfer_not_registered', $held->safe_code, 'Registry absence holds the purchase before seal');
commerceReject(fn () => $act($held, 'refund'));
$clock->instant = Paymenter\Extensions\Servers\Domains\Services\TransferInputs::deadline($held, $clock->now())->modify('+1 second');
$act($held, 'refund');
$closed = $held->fresh();
expect(OperationTerminalProof::safe($closed, $closed->domain), 'After cutoff, the retained unsent purchase proves the cancellation');
same('account_credit', $closed->funding['refund']['to'], 'The paid transfer is refunded');
same([], $state->calls, 'Cutoff cancellation after a registry refusal makes no purchase');
echo "Transfer dispatch input, feature and pre-seal refusal proofs passed.\n"; $nativeSuccessful=true;
