<?php
// Synthetic exact-order support recovery; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
require_once __DIR__.'/Support/acquisition-evidence-fixture.php';
require_once __DIR__.'/Support/native-financial-snapshot.php';
use App\Models\Credit;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\{Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\EnomAnswerAction;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Environment,ExecutionRecord,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client,Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport,TransportResponse};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationAttempts, OperationWriter, ReadToken, StepKey, Transition};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomAnswers,HistoryAcceptances,OperationReconciliation,ReconciliationEvidence,Refunds,RegistrarCheck,RenewalAuthority,StaffSettingsReview as Review,StaffSettingsWrites};
app()->instance(Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);
$staff=commerceUser();$role=App\Models\Role::create(['name'=>'Purchase answers','permissions'=>['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.enom_answer','admin.domains.manage','admin.invoices.update']]);$staff->update(['role_id'=>$role->id]);
$settings=new StaffSettingsWrites($native,$sellers,$bindings);
$review=static fn($op)=>new Review($staff->id,$op->server_id,null,'enom_answer',Review::ticket($staff->id,$op->server_id,null,'enom_answer',Review::stamp($op->server_id,null)),'Record exact synthetic purchase answer');
$input=static fn($op,$outcome)=>['operation_id'=>$op->id,'attempt_hash'=>EnomAnswers::identity($op),'outcome'=>$outcome,
    'order_reference'=>$outcome==='accepted'?'9001':'','processed_at'=>'2026-10-18T05:00:01-07:00',
    'case_reference'=>'synthetic-purchase-case','evidence'=>'Registrar confirmed the account, domain, sealed attempt, exact order number and processing time with UTC offset.','confirmed'=>true];
$wireState=(object)['name'=>'','calls'=>[],'fault'=>'','orders'=>[],'during'=>null];
$wire=new class($wireState) implements DeadlineTransport {
    public function __construct(private $s){}
    public function send($e,$c,$r,#[SensitiveParameter] string $key):TransportResponse{throw new LogicException;}
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $key,int $ms):TransportResponse{
        same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Readback stays outside financial locks');$s=$this->s;$s->calls[]=$r->command;
        if ($s->during !== null) { $hook = $s->during; $s->during = null; $hook(); }
        $order = $r->parameters()['order_number'] ?? null;
        $reply=match($r->command){
            'orderDetails'=>$order === '9002' && $s->fault === 'incomplete' ? ['code'=>280] : [
                'order_number'=>$s->fault==='id'?'9002':$order,'order_date'=>$s->fault==='time'?'2026-10-18 05:00:02':'2026-10-18 05:00:01','total'=>'99.00',
                'order_details'=>[['description'=>($s->fault==='name'?'other.example.com':$s->name).' - registration','years_qty'=>$s->fault==='term'?'2':'1',
                    'status'=>$s->fault==='status' || $order === '9002' ? 'Credited' : 'Processed','subtotal'=>'99.00']]],
            'listOrders'=>['order'=>array_map(static fn($id)=>['order_number'=>$id],$s->orders)],
            'getDomainInfo'=>($s->orders || $s->fault==='unowned') ? ['code'=>280] : ['created'=>'2026-10-18','expires'=>$s->fault==='expiry'?'2026-10-18':'2027-10-18','status'=>'Active','auto_renew'=>'No','private'=>'No','locked'=>'Yes'],
            default=>throw new LogicException('Exact support order must not enumerate history'),
        }+['code'=>300,'detail'=>'synthetic'];
        return new TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>$reply]));
    }
};
$readRegistry=new \Paymenter\Extensions\Servers\Domains\Services\RegistrarRegistry;
$readRegistry->register('namesilo',['token'],$verifier,fn($b,$c)=>new Driver(new Client($b,$c['token'],$wire),['renew'],$clock),authenticator:new class implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {public function authenticate(Environment $environment,array $credentials):void { /* Synthetic local authentication. */ }});
$reads=new AccountBindings($readRegistry);$reconcile=new OperationReconciliation($claims,$reads,$clock);
foreach(['accepted','not_processed','invalid_claim','expired_read','inherited_read','late_observation'] as $scenario){
    $outcome = $scenario === 'accepted' ? 'accepted' : 'not_processed';
    $clock->instant=new DateTimeImmutable('2026-10-18T12:00:00Z');$makeEngine($binding,$exchange);
    $registration->mode=$outcome==='accepted'?'300':'unknown';
    $op=$runRegistration($nsNew(placeholderComFixtureName('support-'.str_replace('_','-',$scenario).'-placeholder.com')));$attempt=$op->attempt;$calls=$registration->calls;
    $wireState->name=$op->domain->domain;$wireState->calls=[];$wireState->fault=$outcome==='not_processed'?'unowned':'';
    commerceReject(fn()=>$settings->run($review($op),$input($op,$outcome),$clock->now()));
    $clock->instant=$clock->now()->modify('+25 hours');
    // Support is the fallback when the order history cannot confirm the purchase.
    App\Models\Server::find($op->server_id)->settings()->where('key',HistoryAcceptances::KEY.'_register')->delete();
    expect(EnomAnswers::eligible($op,$clock->now()),'NameSilo no-ID purchase accepts a final account-bound support answer');
    // TEST NameSilo registrations can read the built-in order history, so staff record an answer only after one status check.
    $early=null;try{$settings->run($review($op),$input($op,$outcome),$clock->now());}catch(DomainException $e){$early=$e->getMessage();}
    same('Use Check status to read this attempt first, then record the registrar’s answer.',$early,'An answer waits for one status check');
    $reconcile->run($op->id);$op->refresh();
    same('listOrders',$wireState->calls[0]??null,'One status check read the order history');expect(!in_array('registerDomain',$wireState->calls,true),'A status check never sends');
    expect(RegistrarCheck::attempted($op),'The status check is saved for this attempt');
    same(null,$op->attempt['reconciliation']??null,'An empty order history settles nothing');
    if ($scenario === 'invalid_claim') {
        // Restore the installed legacy format with a saved check for the same malformed claim.
        restoreAcquisitionFixture([...$op->getRawOriginal(),
            'attempt' => Crypt::encryptString(json_encode([...$attempt, 'claim_key' => 'invalid-legacy-claim'], JSON_THROW_ON_ERROR)),
            'funding' => Crypt::encryptString(json_encode($op->funding, JSON_THROW_ON_ERROR)),
            'started_at' => OperationAttempts::at($op->started_at), 'request_fingerprint' => $op->request_fingerprint]);
        $op->refresh();
        $policy = $op->policy;
        $policy['status_check']['attempt_identity'] = EnomAnswers::identity($op);
        $op->update(['policy' => $policy]);
        $snapshot = static fn () => [$op->fresh()->getRawOriginal(), $op->domain->fresh()->getRawOriginal(),
            Credit::where('user_id', $op->domain->user_id)->sum('amount'),
            OperationAttempts::sealed((int) $op->id, new StepKey('register', 1))];
        $before = $snapshot();
        $refused = false;
        try { $settings->run($review($op), $input($op, $outcome), $clock->now()); }
        catch (DomainException) { $refused = true; }
        expect($refused, 'A negative answer requires a sealed claim that can retain it');
        same($before, $snapshot(), 'An unadoptable sealed claim keeps its request, holding and payment unchanged');
        continue;
    }
    $wireState->calls=[];
    if($outcome==='accepted'){
        $bad=$input($op,'not_processed');commerceReject(fn()=>$settings->run($review($op),$bad,$clock->now()));
        foreach(['','2026-10-18 05:00:01','2026-10-19T05:00:01-07:00'] as $date){$bad=$input($op,$outcome);$bad['processed_at']=$date;commerceReject(fn()=>$settings->run($review($op),$bad,$clock->now()));}
    }
    if (in_array($scenario, ['expired_read', 'inherited_read'], true)) {
        $token = OperationAttempts::sealed((int) $op->id, new StepKey('register', 1))->token;
        $writer = new OperationWriter($clock);
        $underLock = static function (Closure $work) use ($op) {
            return DB::transaction(static function () use ($op, $work) {
                $op->newQuery()->whereKey($op->id)->lockForUpdate()->firstOrFail();
                return $work();
            });
        };
        $openRead = static fn () => $underLock(fn () => $writer->openRead($token, $clock->now()->modify('+30 seconds')));
        $read = $openRead();
        expect($read instanceof ReadToken, 'A worker opens a read before exiting');
        commerceReject(fn () => $settings->run($review($op), $input($op, $outcome), $clock->now()));
        same(null, OperationAttempts::evidence((int) $op->id, $token->attemptId, 'settlement'), 'An active read blocks the staff settlement');
        $clock->instant = $clock->now()->modify('+30 seconds');
        if ($scenario === 'inherited_read') {
            $successor = $openRead();
            expect($successor->inheritedUnresolved, 'The next worker inherits the orphaned read');
            expect($underLock(fn () => $writer->closeRead($successor)), 'The successor finishes without positive proof');
        }
    }
    if ($scenario === 'late_observation') {
        $token = OperationAttempts::sealed((int) $op->id, new StepKey('register', 1))->token;
        // Save the observation after the answer row is written, before the operation's rejection is saved.
        Operation::updating(static function (Operation $saving) use ($op, $token, $clock): void {
            if ($saving->id !== $op->id || !$saving->isDirty('state') || $saving->state !== 'rejected') { return; }
            same('not_processed', OperationAttempts::evidence($token->operationId, $token->attemptId, 'settlement')['outcome'] ?? null,
                'The staff answer precedes the observation');
            same(Transition::Applied, OperationWriter::at($clock->now())->recordObservation($token, ['owned' => true, 'expiry' => '2027-10-18'], $clock->now()),
                'The sealed attempt retains the positive registrar observation');
        });
        $before = nativeFinancialSnapshot($op);
        $refused = false;
        try { $settings->run($review($op), $input($op, $outcome), $clock->now()); }
        catch (DomainException) { $refused = true; }
        $op->refresh();
        same('positive', OperationAttempts::evidence($token->operationId, $token->attemptId, 'observation')['outcome'] ?? null,
            'The positive observation is present when the return runs');
        expect($refused, 'Positive registrar evidence on the sealed registration blocks the payment return');
        same(null, $op->funding['refund'] ?? null, 'A refused return leaves no refund record');
        same($before, nativeFinancialSnapshot($op), 'A refused return preserves the holding and payment');
        continue;
    }
    $settings->run($review($op),$input($op,$outcome),$clock->now());$op->refresh();
    same($attempt,$op->attempt,'Answer never changes the original sent attempt');
    $sealed = OperationAttempts::sealed((int) $op->id, new StepKey('register', 1));
    $kind = $outcome === 'accepted' ? 'answer' : 'settlement';
    same($outcome, OperationAttempts::evidence((int) $op->id, $sealed->token->attemptId, $kind)['outcome'] ?? null,
        'Audited answer addresses the original registration attempt');
    if (in_array($scenario, ['expired_read', 'inherited_read'], true)) {
        same([null, true], [$sealed->readToken, $sealed->readUnresolved],
            'The staff settlement fences the orphaned reader and retains its uncertainty');
    }
    if($outcome==='accepted'){
        $a=ReconciliationEvidence::attempt($op,$op->domain,$binding);same(ExecutionRecord::StaffAnswer,$a->execution,'Exact order carries staff provenance');
        foreach(['id','name','term','status','time','expiry'] as $fault){
            $wireState->fault=$fault;$r=$reconcile->run($op->id);expect(in_array($r->state,['pending','uncertain'],true),'A support answer never overrides mismatching readback: '.$fault);
            $p=$r->policy;$p['reconciliation']['polls']=0;$r->update(['policy'=>$p]);
        }
        $wireState->fault='';$r=$reconcile->run($op->id);same('succeeded',$r->state,'Exact supported order and owned full term resolve absent history');
        same(['9001','staff_answer','99.00'],[$r->provider_order_id,$r->attempt['reconciliation']['source'],$r->cost['actual']['amount']],'Read charge and supported order retained');
        expect(RenewalAuthority::completed($r,$r->domain,$binding),'Completion independently reconstructs exact support time and order');
        expect(!in_array('listOrders',$wireState->calls,true),'No unsupported historical search');
    }else{
        expect(isset($op->funding['refund']),'Final no-order/no-charge answer returns the payment');
        same(null,app(Refunds::class)->creditRejected($op->id),'Negative answer returns once');
    }
    $engine->execute($op->id);same($calls,$registration->calls,'Support never repeats registerDomain');
}
// A prior empty check allows a support answer. A later matching charge must survive missing ownership,
// competing or unreadable order details, and a concurrent check that loses the read claim.
$failures = [];
foreach (['credited', 'incomplete', 'overlap'] as $scenario) {
    $clock->instant = new DateTimeImmutable('2026-10-18T12:00:00Z'); $makeEngine($binding, $exchange);
    $registration->mode = 'unknown';
    $op = $runRegistration($nsNew(placeholderComFixtureName('support-charge-'.$scenario.'-placeholder.com')));
    $clock->instant = $clock->now()->modify('+25 hours');
    HistoryAcceptances::save(new HistoryAcceptance($binding,'America/Los_Angeles',0,172800,'namesilo_registration_v1'));
    $wireState->name = $op->domain->domain; $wireState->orders = []; $wireState->fault = 'unowned';
    $reconcile->run($op->id); $op->refresh();
    expect(RegistrarCheck::attempted($op), 'The empty first check is bound to the original attempt');
    $policy = $op->policy;
    $policy['reconciliation']['polls'] = OperationReconciliation::POLL_LIMIT - 1;
    $op->update(['policy'=>$policy]);
    $wireState->orders = $scenario === 'overlap' ? ['9001'] : ['9001','9002'];
    $wireState->fault = $scenario;
    if ($scenario === 'overlap') {
        $wireState->during = static function () use ($op, $reconcile, &$failures): void {
            $policy = $op->fresh()->policy;
            if ($reconcile->run($op->id)->policy !== $policy) { $failures[] = 'Busy check changed the active reader’s policy'; }
        };
    }
    $result = $reconcile->run($op->id);
    $sealed = OperationAttempts::sealed((int) $op->id, new StepKey('register', 1));
    $charge = OperationAttempts::evidence((int) $op->id, $sealed->token->attemptId, 'acquisition_charge');
    $match = $scenario === 'overlap' ? 'exact' : 'ambiguous';
    if (($charge['outcome'] ?? null) !== $match || array_column($charge['evidence']['orders'] ?? [], 'reference') !== ['9001']) {
        $failures[] = 'Matching charge lost: '.$scenario;
    }
    if (OperationAttempts::evidence((int) $op->id, $sealed->token->attemptId, 'settlement') !== null) {
        $failures[] = 'Charge alone settled the registration: '.$scenario;
    }
    $action = EnomAnswerAction::make($result, Review::stamp($op->server_id, null));
    $outcomes = $action->getSchema(Schema::make(new class extends Livewire\Component implements Filament\Schemas\Contracts\HasSchemas {
        use Filament\Schemas\Concerns\InteractsWithSchemas;
    }))->getFlatFields(withHidden: true)['outcome']->getOptions();
    if (array_keys($outcomes) !== ['accepted']) { $failures[] = 'Staff offered a negative answer: '.$scenario; }
    try { $settings->run($review($result), $input($result, 'not_processed'), $clock->now()); }
    catch (DomainException) { /* A matching charge rules out an audited negative answer. */ }
    $after = $result->fresh();
    if ($after->state !== $result->state || isset($after->funding['refund'])) { $failures[] = 'Matching charge allowed a refund: '.$scenario; }
}
expect($failures === [], implode(PHP_EOL, $failures));
echo "NameSilo support answers retain account, attempt, exact order/time and original financial evidence.\n";$nativeSuccessful=true;
