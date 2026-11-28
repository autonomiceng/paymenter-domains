<?php
// A paid NameSilo order held at its contact step with nothing sent: staff Release sends it once; Cancel and refund
// cancels the order and its service, then refunds once. Both are refused once anything went to NameSilo.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Staff\Release\HeldRelease;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionClosureKind,AcquisitionResult,OperationAttempts};
require __DIR__.'/Support/namesilo-registration-fixture.php';
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/money-receipt.php';
require_once __DIR__.'/Support/fixture-checkpoint.php';
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,WriteReservation};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Money};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\{ContactProfileHold,ContactStepRetries,ReadDeferred,DomainOperations,FundsWaits,OperationClaims,ProfileContactOperations,ProfileContactSchema,Refunds,RegistrationProfiles,StaffOperationReview,StaffOperations,StaffOperationWrites,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\RegistrationPreflight;
ProfileContactSchema::install();staffPanel();
app()->instance(Clock::class,$clock);app()->instance(Refunds::class,new Refunds($clock));
$all=[Staff::VIEW,'admin.domains.manage','admin.domains.fund','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){$all[]=StaffOperationReview::permission($action);}
$staff=staffUser($all);Auth::login($staff);$service=new StaffOperations($clock);
$ticket=fn(Operation $op,string $action)=>StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock);
$run=fn(Operation $op,string $action,?string $key=null)=>$service->run($staff,$op->domain_id,$op->id,$action,$key??$ticket($op,$action),'Customer order reviewed by staff');
// Each refusal must be the expected one, so a type error or an unrelated failure cannot pass for it.
$refused=static function(callable $work,string $label,string $message):void {
    try{$work();}catch(DomainException $e){if($e->getMessage()===$message){return;}throw new LogicException($label.' (unexpected refusal: '.$e->getMessage().')',0,$e);}
    throw new LogicException($label);
};
$changed='This request changed. Reload the page and try again.';$original='This hold requires its original recovery or evidence path.';

// The order's own contact grant, as the installed runtime gives it: a local cap refusal stops before any send.
$grants=new class($binding) implements WriteReservation {
    public ?int $active=null;public bool $reserved=false;public bool $consumed=false;public bool $cap=false;public ?Closure $fail=null;
    public function __construct(private AccountBinding $binding){}
    public function bindsTo(AccountBinding $b):bool{return $this->binding->matches($b);}
    public function run(string $feature,int $id,Closure $work):mixed{$this->active=$id;$this->reserved=$this->consumed=false;try{return $work();}finally{$this->active=null;}}
    public function reserve(string $command,#[SensitiveParameter] array $parameters,?Money $cost=null):void{if($this->fail){($this->fail)();}if($this->cap){throw new RuntimeException('Synthetic local cap');}$this->reserved=true;}
    public function unconsumed(int $id):bool{return $this->active===$id&&$this->reserved&&!$this->consumed;}
};
$sent=(object)['contactAdd'=>0,'registerDomain'=>0,'readFails'=>false];
$wire=static function($origin,$command,$parameters,$timeout)use($exchange,$sent,$grants):string {
    if($command==='contactAdd'){$grants->consumed=true;$sent->contactAdd++;}
    if($command==='registerDomain'){$sent->registerDomain++;}
    if($command==='contactList'&&$parameters!==[]&&$sent->readFails){throw new RuntimeException('Synthetic readback loss');}
    return $exchange($origin,$command,$parameters,$timeout);
};
$compose=static function()use($binding,$claims,$clock,$grants,$wire,$freshBindings,&$engine):DomainOperations {
    $features=new WriteFeatures(registration:true);
    $profiles=new RegistrationProfiles($binding,$claims,$clock,static fn()=>throw new LogicException('The older direct sender must not run'),
        operations:new ProfileContactOperations($binding,$claims,$clock,$grants,$wire));
    $acceptance=new WriteAcceptance($binding,true,true,25,$profiles,catalogue:$GLOBALS['caps']??null);
    $protocol=new NameSilo\Registration($binding,$profiles,$wire);
    $bridge=new NameSilo\Writer($binding,$features,$acceptance,static function()use(&$engine){return $engine;},$protocol);
    $engine=new DomainOperations($claims,new RegistrationPreflight($freshBindings,$features,$acceptance,$profiles),$bridge,$clock);
    Cache::forget('domains-write-slot:'.$binding->fingerprint);return $engine;
};
$execute=static function(Operation $op)use($compose,$registration):Operation {
    $registration->query=OperationClaims::query($op,$op->domain);return $compose()->execute($op->id);
};
// One paid order held at its contact step, like request #290: purchase never sealed, contact row still waiting.
$heldOrder=static function(string $name)use($nsNew,$grants,$execute,$sent,$clock,$staff):Operation {
    $op=$nsNew(placeholderComFixtureName($name));
    $op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
    $grants->cap=true;$before=[$sent->contactAdd,$sent->registerDomain];
    $held=$execute($op);$grants->cap=false;
    same(['held','contact_profile_proof_required'],[$held->state,$held->safe_code],'The order holds at its contact step');
    same(['absent','definitely_unsent'],[$held->attempt['seal'],$held->attempt['send_state']],'The purchase was never sealed');
    same('ready',ProfileContactOperation::where('operation_id',$op->id)->sole()->state,'The contact step never sent');
    same($before,[$sent->contactAdd,$sent->registerDomain],'Nothing went to NameSilo');
    $clock->instant=$clock->now()->modify('+60 seconds');
    Auth::login($staff); // Checkout signs the customer in.
    return $held->fresh();
};
// Rows that differ only in a decision input read the request's own action objects; one page case keeps each effect.
// Like a page test, the reader acts as the named staff member: checkout fixtures sign the customer in.
$actions=static function(Operation $op,App\Models\User $as):array{Auth::setUser($as);$op=$op->fresh();return collect(OperationActions::make($op,StaffOperationReview::stamp($op,$op->domain)))->keyBy(fn($a)=>$a->getName())->all();};
$serviceStatus=fn(Operation $op)=>App\Models\Service::findOrFail($op->service_id)->status;
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
$viaGateway=static function(Operation $op,string $receipt)use($gateway):void {
    DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>$receipt]);
};

// Release sends the order once.
$op=$heldOrder('held-contact-release-placeholder.com');
expect(HeldRelease::offered($op,$clock->now()),'Release is offered for an unsent contact step hold');
expect(OperationActions::relevant('refund',$op),'Cancel and refund is offered for an unsent contact step hold');
$account=Account::where('server_id',$op->server_id)->sole();
$costStaff=staffUser([...$all,Staff::COSTS]);Auth::login($costStaff);
$costFacts=RequestFacts::for($op);
same('Not charged yet',$costFacts['cost'],'A proved unsent contact hold has no missing registrar charge');
expect(str_contains($costFacts['cost_sub'],'cannot exceed this estimate'),'The estimate is a spending limit, not an assumed charge');

// A release cannot enable the contact-creation deployment capability; both page and locked writer must refuse it.
$managementConfig=config('domains.namesilo_test.management',true);config(['domains.namesilo_test.management'=>false]);
$blocked='Contact creation is off on this site; the site administrator must enable NameSilo contact creation before this order can retry.';
foreach(['contact_profile_proof_required','write_rate_limited','processing'] as $reason){
    DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>$reason]);$op->refresh();
    $before=[$nativeMoney(),$op->getRawOriginal(),$sent->contactAdd,$sent->registerDomain];
    expect(!HeldRelease::offered($op,$clock->now()),'Contact creation off prevents release for '.$reason);
    if($reason===ContactProfileHold::CODE){Livewire::actingAs($costStaff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('release');}
    else{same(false,$actions($op,$costStaff)['release']->isVisible(),'The page hides Release with contact creation off for '.$reason);}
    Auth::login($staff);$refused(fn()=>$run($op,'release'),'The writer refuses disabled contact creation',$blocked);
    $retryClock=clone $clock;$retryClock->instant=$clock->now()->modify('+1 day');
    expect(!ContactStepRetries::readmit($op->id,$retryClock),'Automatic contact release also waits for contact creation');
    same($before,[$nativeMoney(),$op->fresh()->getRawOriginal(),$sent->contactAdd,$sent->registerDomain],'Blocked contact retry changes no money, history or sends');
}
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'contact_profile_proof_required']);$op->refresh();
config(['domains.namesilo_test.management'=>$managementConfig]);

// Prior holdings may keep held orders, but their controls do not belong on the current domain.
$heldRow=new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,'heldOrderRow');
// The controls the domain page's held order row shows: name and whether staff can press it.
$heldControls=fn($d):?array=>($row=$heldRow->invoke(null,$d))===null?null:array_map(fn($x)=>[$x->getName(),$x->isDisabled()],(fn()=>$this->shownControls())->call($row));
foreach(['authority_generation'=>$op->authority_generation+1,'account_fingerprint'=>str_repeat('e',64)] as $field=>$other){
    $domainRow=$op->domain->fresh();$originalValue=$domainRow->$field;
    DB::table('domains_domains')->where('id',$domainRow->id)->update([$field=>$other]);
    same(null,$heldRow->invoke(null,$domainRow->fresh()),'An order from a different holding offers no current held-order controls');
    DB::table('domains_domains')->where('id',$domainRow->id)->update([$field=>$originalValue]);
}

// A confirmation keeps the state staff opened, even when another worker changes the same held order.
$stale=$op;
$releaseAction=Filament\Actions\Testing\TestAction::make('release')->schemaComponent(true,'content');
$stalePage=Livewire::actingAs($costStaff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$stale->domain_id])
    ->mountAction($releaseAction);
DB::table('domains_operations')->where('id',$stale->id)->update(['safe_code'=>'processing']);
$before=[$nativeMoney(),$stale->fresh()->getRawOriginal(),$sent->contactAdd,$sent->registerDomain];
$stalePage->callMountedAction();
same($before,[$nativeMoney(),$stale->fresh()->getRawOriginal(),$sent->contactAdd,$sent->registerDomain],'A changed held order cannot be released from its earlier confirmation');
DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'contact_profile_proof_required']);$op->refresh();
$clock->instant=$clock->now()->modify('+60 seconds');Auth::login($costStaff);

// Distinct hold causes must lead to a useful repair, with no blind retry for permanent or unknown failures.
foreach([
    'configuration_unavailable'=>['Staff','registrars'],
    'registrar_price_missing'=>['Staff','registrars'],
    'provider_membership_unconfirmed'=>['Staff','registrars'],
    'renewal_invoice_unpaid'=>['Staff','invoice'],
    'automatic_spend_paused'=>['Staff','domain'],
    'registry_details_unsupported'=>['Staff',null],
    'future_hold'=>['Staff','domain'],
] as $code=>[$owner,$destination]){
    $row=clone $op;$row->safe_code=$code;
    $notice=RequestFacts::notice($row);
    if($code==='registrar_price_missing'){
        same(null, $notice['next_try'] ?? null, 'Changing a display reason cannot schedule a price check');
    }else{expect(str_contains((string)$notice['body'],$owner),'The page names who fixes '.$code);}
    same($destination,$notice['link'],'The page leads to the repair for '.$code);
    if($code==='future_hold'){
        expect(str_contains((string)$notice['body'],'releasing this hold cannot resolve its cause'),'Permanent and unknown holds explain why release cannot help');
    }
    if($code==='registry_details_unsupported'){
        same(null,\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestHoldReasons::releaseBlocked($row),'Safe registry holds defer to the writer no-send proof');
    }
}

// Orders off is the only wait for an unsent contact hold: the pages lead to the Orders switch, Release waits for Orders
// while Cancel and refund stays, and the gate preserves the contact retry already scheduled.
$paused=$heldOrder('held-contact-paused-release-placeholder.com');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
$before=[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$paused->attempt];
$pausedActions=$actions($paused,$costStaff);
same([true,true,true,false],[$pausedActions['release']->isVisible(),$pausedActions['release']->isDisabled(),$pausedActions['refund']->isVisible(),$pausedActions['refund']->isDisabled()],'The request page waits Release for Orders and keeps Cancel and refund');
Auth::login($costStaff);same([['release',true],['refund',false]],$heldControls($paused->domain->fresh()),'The domain page waits Release for Orders and keeps Cancel and refund on the held order row');
$panel=Filament\Facades\Filament::getCurrentPanel();$panel->pages([Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class]);
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
same('registrars',RequestFacts::notice($paused)['link'],'The request page leads to the Orders switch');
same(Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::accountUrl((int)$paused->server_id,'orders'),
    Paymenter\Extensions\Servers\Domains\Admin\Support\DomainNotices::notice($paused->domain->fresh())['action']['url']??null,'The domain notice opens the registrar Orders switch');
expect(RequestFacts::reason($paused)!==Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::operationReason($paused),'The saved contact cause is not shown while Orders are off');
expect(!ContactStepRetries::readmit($paused->id,$clock),'The tick does not release while Orders are off');
$savedRetry = AcquisitionResult::continuation($paused->fresh());
same(['held',ContactProfileHold::CODE,0],[$paused->fresh()->state,$paused->fresh()->safe_code,ContactStepRetries::tries($paused->fresh())],'Orders leaves the original contact hold and spends no try');
Auth::login($costStaff);
same('Not charged yet',RequestFacts::for($paused->fresh())['cost'],'An unsent contact order held for Orders off has no missing registrar charge');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
expect(!ContactStepRetries::readmit($paused->id,$clock),'Orders on leaves the retained contact due time in force');
same($savedRetry->dueAt, AcquisitionResult::continuation($paused->fresh())->dueAt, 'Turning Orders off and on preserves the due time');
$clock->instant = $clock->now()->setTimestamp($savedRetry->dueAt);
expect(ContactStepRetries::readmit($paused->id,$clock),'It goes back by itself once the saved contact retry is due');
same(['queued',null],[$paused->fresh()->state,$paused->fresh()->safe_code],'Orders on puts the order back in the queue');
same($before,[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$paused->fresh()->attempt],'Nothing was sent and money and unsent proof are kept');
same('Not charged yet',RequestFacts::for($paused->fresh())['cost'],'The unsent order back in the queue has no missing registrar charge');
// Held again at its contact step: the domain history shows each hold the request History shows, in order.
$grants->cap=true;$execute($paused->fresh());$grants->cap=false;
same(['held','contact_profile_proof_required'],[$paused->fresh()->state,$paused->fresh()->safe_code],'The retried order holds again at its contact step');
$requestRows=array_values(array_filter(Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainHistory::entries($paused->domain->fresh()),fn($r)=>is_array($r)&&($r['__request']??null)===$paused->id));
// The first hold's time comes from the app clock, not this fixture's clock, so only the entries and actors are compared here.
$entries=array_map(fn($r)=>[preg_replace('/-\d+(-\d+)?$/','',(string)$r['__key']),$r['by']],$requestRows);sort($entries);
same([['engine_hold','System'],['first-hold','System'],['release','System']],$entries,'Domain history has both system holds and the automatic release by the system: '.json_encode($entries));
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
Auth::login($costStaff);
[$heading,$submit,$words]=RequestFacts::releaseConfirmation($op,$account,null,false);
same(['Send to NameSilo now?','Release and send'],[$heading,$submit],'Release asks before it sends');
expect(str_contains($words,'NameSilo charges about USD '.number_format((float)$op->cost['estimated'],2,'.','').' from the registrar balance.'),'Release says what NameSilo charges');
$release=static fn()=>collect(OperationActions::make($op->fresh(),'held-contact-confirm'))->first(fn($a)=>$a->getName()==='release');
$action=$release();
same([$heading,$submit,$words],[(string)$action->getModalHeading(),(string)$action->getModalSubmitActionLabel(),(string)$action->getModalDescription()],'The real release modal shows the estimate to staff with Costs');
Auth::login($staff);
$action=$release();$restricted=(string)$action->getModalDescription();
same(['Send to NameSilo now?','Release and send'],[(string)$action->getModalHeading(),(string)$action->getModalSubmitActionLabel()],'Staff without Costs retain the send action');
expect($action->isVisible(),'Staff without Costs retain release authority');
expect(!str_contains($restricted,'charges about')&&!str_contains($restricted,'from the registrar balance.'),'The real modal hides the wholesale estimate without Costs');
expect(str_contains($restricted,'Once sent, it can\'t be taken back.'),'The send warning remains without Costs');
Auth::login($staff);
$key=$ticket($op,'release');$run($op,'release',$key);
same(['queued',null],[$op->fresh()->state,$op->fresh()->safe_code],'Release clears the hold');
same($op->attempt,$op->fresh()->attempt,'Release keeps the unsent claim');
$history=$op->fresh()->policy['staff_actions'];$audit=end($history);
same(['release','contact_profile_proof_required','queued'],[$audit['action'],$audit['before']['reason'],$audit['after']['state']],'Release writes one audit row');
$refused(fn()=>$run($op->fresh(),'release',$key),'A double click on Release is refused',$changed);
same(1,count($op->fresh()->policy['staff_actions']),'A double click writes no second audit row');
$done=$execute($op->fresh());
expect(in_array($done->state,['pending','uncertain'],true),'The released order is sent');
same([1,1],[$sent->contactAdd,$sent->registerDomain],'Release sends the contact and the purchase once');
$clock->instant=$clock->now()->modify('+60 seconds');$execute($op->fresh());
same([1,1],[$sent->contactAdd,$sent->registerDomain],'Running the worker again sends nothing more');
expect(!HeldRelease::offered($op->fresh(),$clock->now())&&!OperationActions::relevant('refund',$op->fresh()),'Both actions are gone once the order was sent');
$refused(fn()=>$run($op->fresh(),'refund'),'Cancel and refund is refused after a send','Only held unpaid-to-provider requests may be closed.');
same(null,$op->fresh()->funding['refund_authorization']??null,'No refund after a send');
echo "Release sends a held contact step order once.\n";

// Cancel and refund cancels the order and its service, then refunds once through the gateway.
$op=$heldOrder('held-contact-refund-placeholder.com');
$restoreHeld=nativeFixtureCheckpoint();
$heldAt=$clock->instant;
$nativeNow=Carbon\Carbon::now()->toImmutable();
$heldReturns=MoneyReceiptGateway::$returns;
$resetHeld=static function () use ($restoreHeld,$heldAt,$nativeNow,$heldReturns,$clock,$staff,$op): Operation {
    $restoreHeld();
    $clock->instant=$heldAt;
    Carbon\Carbon::setTestNow($nativeNow);
    Carbon\CarbonImmutable::setTestNow($nativeNow);
    MoneyReceiptGateway::$returns=$heldReturns;
    Auth::login($staff);
    return $op->fresh();
};
$viaGateway($op,'receipt_held_contact_refund');
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='refunded';$sends=MoneyReceiptGateway::$sends;
$words=OperationActions::refundWords($op);
expect(str_starts_with($words,'Cancels this order and its service, then refunds $'.number_format((float)$op->amount,2).' to the original payment.'),'The confirm says it cancels first and what it refunds');
expect(str_contains($words,'Nothing was sent to NameSilo, so there is no registrar cost.'),'The confirm says there is no registrar cost');
$seenAtRefund=null;
MoneyReceiptGateway::$onAllocated=static function()use($op,&$seenAtRefund,$serviceStatus):void {
    $seenAtRefund=[$op->fresh()->state,$serviceStatus($op),$op->domain->fresh()->status];
};
$key=$ticket($op,'refund');$run($op,'refund',$key);
same(['rejected',App\Models\Service::STATUS_CANCELLED,'registration_rejected'],$seenAtRefund,'The order and its service were cancelled before the refund was sent');
same(['rejected','refund_approved','original_payment'],[$op->fresh()->state,$op->fresh()->safe_code,$op->fresh()->funding['refund']['to']],'Refund goes to the original payment');
same($sends+1,MoneyReceiptGateway::$sends,'One refund');
$history=$op->fresh()->policy['staff_actions'];
same(['refund','contact_profile_proof_required','rejected'],[end($history)['action'],end($history)['before']['reason'],end($history)['after']['state']],'Cancel and refund writes one audit row');
$refused(fn()=>$run($op->fresh(),'refund',$key),'A double click on Cancel and refund is refused',$changed);
$refused(fn()=>$run($op->fresh(),'refund'),'A second Cancel and refund is refused','Invalid retained refund disposition.');
$refused(fn()=>$run($op->fresh(),'release'),'Release is refused after Cancel and refund',$original);
same($sends+1,MoneyReceiptGateway::$sends,'Retries never refund twice');
same(1,count($op->fresh()->policy['staff_actions']),'Refused clicks write no audit row');
same([1,1],[$sent->contactAdd,$sent->registerDomain],'Cancel and refund sends nothing to NameSilo');
echo "Cancel and refund cancels first, then refunds once.\n";

// A failed refund leaves the service cancelled and the refund retryable, with honest words.
$op=$resetHeld();$viaGateway($op,'');$sends=MoneyReceiptGateway::$sends;
$page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('refund')->assertActionVisible('release');
$page->callAction('refund',['reason'=>'Customer asked for a refund'])->assertHasNoActionErrors()
    ->assertNotified(Notification::make()->warning()->persistent()->title('Cancelled, refund not finished')
        ->body("The order and its service are cancelled. The refund hasn't gone through (ref guard_refused). Use Retry refund. It reuses this refund, so the customer is never refunded twice."));
same(['rejected','refund_approved'],[$op->fresh()->state,$op->fresh()->safe_code],'The cancel is kept');
same(App\Models\Service::STATUS_CANCELLED,$serviceStatus($op),'The service stays cancelled when the refund fails');
same(null,$op->fresh()->funding['refund']??null,'No refund was recorded');same($sends,MoneyReceiptGateway::$sends,'No refund was sent');
same(true,$op->fresh()->policy['held_order_refund']??false,'The paid held-order cancellation records its own refund marker');
$closed=OperationAttempts::purchaseProof($op->fresh(),$clock->now());
same([AcquisitionClosureKind::Unsent,$op->acquisitionAttemptFacts()->token->attemptId],[$closed->closure,$closed->facts?->token->attemptId],
    'An interrupted refund can read back its exact closed predecessor');
$order=\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::order($op->domain->fresh(),$op->fresh(),App\Models\Invoice::findOrFail($op->invoice_id));
same(true,$order['refund_pending']??false,'Only the marked held-order refund projects the pending customer step');
$policy=$op->fresh()->policy;
$refused(fn()=>$op->fresh()->update(['policy'=>array_diff_key($policy,['held_order_refund'=>true])]),'The held-order refund marker cannot be removed','Checkout evidence is frozen.');
$viaGateway($op,'receipt_held_contact_retry');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('refund')->assertActionHidden('release')
    ->assertActionVisible('credit')->callAction('credit',['reason'=>'Payment receipt fixed'])->assertHasNoActionErrors()->assertNotified('Refund checked');
same(['original_payment',$sends+1],[$op->fresh()->funding['refund']['to'],MoneyReceiptGateway::$sends],'Retry refund sends one refund');
$order=\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::order($op->domain->fresh(),$op->fresh(),App\Models\Invoice::findOrFail($op->invoice_id));
expect(!isset($order['refund_pending']),'A recorded return uses its existing customer presentation instead of the early-cancel step');
$retained=$op->fresh()->funding;$auditBefore=$op->fresh()->policy['staff_actions'];$retryKey=$ticket($op->fresh(),'credit');
$refused(fn()=>$run($op->fresh(),'credit',$retryKey),'A settled refund cannot be retried','This payment has already been returned.');
same($retained['refund'],$op->fresh()->funding['refund'],'A retained-plan recheck preserves the confirmed refund evidence');
same($retained['refund_authorization'],$op->fresh()->funding['refund_authorization'],'The original refund authority stays');
same(['rejected','refund_approved',App\Models\Service::STATUS_CANCELLED],[$op->fresh()->state,$op->fresh()->safe_code,$serviceStatus($op)],'Rechecking a finished return keeps the order and service cancelled');
$auditAfter=$op->fresh()->policy['staff_actions'];
same($auditBefore,$auditAfter,'A refused settled-refund retry preserves staff history');
$refused(fn()=>$run($op->fresh(),'credit',$retryKey),'A repeated settled-refund retry is refused','This payment has already been returned.');
same($auditAfter,$op->fresh()->policy['staff_actions'],'The refused duplicate review writes no extra audit');
same($sends+1,MoneyReceiptGateway::$sends,'Never a second refund');
echo "A failed refund keeps the cancel and retries once.\n";

// Orders can pause after a real balance retry queues, before there is any contact work.
$restoreShortfall=nativeRegistrarCheckpoint($clock,$registration,$sent);
$shortfall=$nsNew(placeholderComFixtureName('paused-shortfall-placeholder.com'));
$shortfall->update(['payload'=>$shortfall->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
$viaGateway($shortfall,'receipt_paused_shortfall');
$shortfall=$claims->holdUnclaimed($shortfall->id,'usd_balance_required');
$due=static function(Operation $op)use($clock):void {
    $at=AcquisitionResult::continuation($op)?->dueAt;
    expect($at!==null,'The balance hold schedules another check');
    $clock->instant=$clock->now()->setTimestamp($at);
};
$due($shortfall);
$registration->balance='0.00';
$before=[$sent->contactAdd,$sent->registerDomain,MoneyReceiptGateway::$sends];
$shortfall=$execute($shortfall);
$actual=[$shortfall->state,$shortfall->safe_code,ProfileContactOperation::where('operation_id',$shortfall->id)->exists()];
same(['held','registrar_balance_insufficient',false],$actual,'The retry probe observes a shortfall before preparing contact: '.json_encode($actual));
$due($shortfall);
expect($claims->readmitBalance($shortfall->id),'The due shortfall retry is admitted before Orders turns off');
$account=Account::where('server_id',$binding->serverId)->sole();
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
$shortfall=$execute($shortfall);
same(['held','processing',true,false],[$shortfall->state,$shortfall->safe_code,$shortfall->acquisitionAttemptFacts()!==null,
    ProfileContactOperation::where('operation_id',$shortfall->id)->exists()],'Paused retry retains its purchase ledger before any contact preparation');
$clock->instant=$clock->now()->modify('+3 days');
$account=$account->fresh();
FundsWaits::sweep($account,$clock);
$shortfall=$shortfall->fresh();
same(['rejected','cancelled','original_payment'],[$shortfall->state,$serviceStatus($shortfall),$shortfall->funding['refund']['to']??null],
    'The three-day sweep returns the recorded payment while Orders is off');
same([$before[0],$before[1],$before[2]+1],[$sent->contactAdd,$sent->registerDomain,MoneyReceiptGateway::$sends],
    'The sweep returns one payment without sending contact or purchase traffic');
FundsWaits::sweep($account,$clock);
app(Refunds::class)->creditRejected($shortfall->id);
same($before[2]+1,MoneyReceiptGateway::$sends,'Replaying the sweep and the refund never returns a second payment');
$restoreShortfall();

// Once the contact step may have reached NameSilo, both actions are refused.
$op=$nsNew(placeholderComFixtureName('held-contact-sent-placeholder.com'));
$op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
$sent->readFails=true;$before=$sent->contactAdd;$held=$execute($op);$sent->readFails=false;$clock->instant=$clock->now()->modify('+60 seconds');Auth::login($staff);
same(['held','contact_profile_proof_required'],[$held->state,$held->safe_code],'A contact step that sent holds the order');
same([$before+1,'created'],[$sent->contactAdd,ProfileContactOperation::where('operation_id',$op->id)->sole()->state],'The contact reached NameSilo');
$held=$held->fresh();$money=[$nativeMoney(),$serviceStatus($held),MoneyReceiptGateway::$sends];
expect(!HeldRelease::offered($held,$clock->now())&&!OperationActions::relevant('refund',$held),'Neither action is offered after a contact send');
try{$run($held,'refund');throw new LogicException('Cancel and refund must be refused after a contact send');}
catch(Paymenter\Extensions\Servers\Domains\Services\OperationRefused $error){same('The registrar may have received this request.',$error->getMessage(),'Refusal says why');}
$refused(fn()=>$run($held->fresh(),'release'),'Release is refused after a contact send',$original);
same(['held','contact_profile_proof_required'],[$held->fresh()->state,$held->fresh()->safe_code],'The hold is kept');
same($money,[$nativeMoney(),$serviceStatus($held),MoneyReceiptGateway::$sends],'No money moves and the service is unchanged');
same($before+1,$sent->contactAdd,'Nothing more is sent');
echo "Both actions are refused once anything reached NameSilo.\n";

// The saved contact step is bound to the original payload, so changed details cannot be released with it.
$op=$resetHeld();
$payload=$op->payload;$payload['contacts']['registrant']['first_name']='Changed';
\Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update(['payload'=>$payload]));$op=$op->fresh();
$before=[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->attempt];
expect(!HeldRelease::offered($op,$clock->now()),'Changed order details cannot release the saved contact step');
expect(OperationActions::relevant('refund',$op),'A changed unsent order can still be cancelled and refunded');
same([false,true,ContactProfileHold::RETRY_BLOCKED],[$actions($op,$staff)['release']->isVisible(),$actions($op,$staff)['refund']->isVisible(),RequestFacts::notice($op->fresh())['body']??null],'The page offers only Cancel and refund and says why');
$refused(fn()=>$run($op,'release'),'The writer also refuses changed details','The contact step cannot be retried with these order details. Cancel and refund this order.');
same(['held','contact_profile_proof_required'],[$op->fresh()->state,$op->fresh()->safe_code],'Changed details stay held');
same($before,[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->fresh()->attempt],'Refused release changes no money or send evidence');
echo "Changed details cannot release a saved contact step.\n";

// A completed unsent contact step cannot run again, even though its saved order details still match.
$op=$resetHeld();
$row=ProfileContactOperation::where('operation_id',$op->id)->sole();
ProfileContactOperation::writing(fn()=>$row->update(['state'=>'sealed','attempt'=>[...$row->attempt,
    'seal'=>'sealed','send_state'=>'possibly_sent','worker_key'=>str_repeat('a',32),'lease_until'=>$clock->now()->format(DATE_ATOM)]]));
ProfileContactOperation::writing(fn()=>$row->update(['state'=>'unsent','attempt'=>[...$row->attempt,'execution'=>'definitely_unsent']]));
same(OperationClaims::payloadDigest($op),$row->fresh()->payload_digest,'The ended contact step still matches the order details');
$before=[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->attempt,$row->fresh()->attempt];
same([false,true,ContactProfileHold::RETRY_ENDED],[$actions($op,$staff)['release']->isVisible(),$actions($op,$staff)['refund']->isVisible(),RequestFacts::notice($op->fresh())['body']??null],'The page names the ended step without blaming unchanged details');
try{$run($op,'release');throw new LogicException('An ended contact step cannot be released');}
catch(DomainException $error){same('This contact step has ended and cannot be retried. Cancel and refund this order.',$error->getMessage(),'The writer names the ended step without blaming unchanged details');}
same(['held','contact_profile_proof_required'],[$op->fresh()->state,$op->fresh()->safe_code],'The ended contact step stays held');
same($before,[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->fresh()->attempt,$row->fresh()->attempt],'An ended contact step changes no money or send evidence');
echo "An ended contact step does not blame unchanged order details.\n";
// A new paid order with saved nameservers, ready for its contact step.
$order=static function(string $name)use($nsNew):Operation {
    $op=$nsNew(placeholderComFixtureName($name));
    $op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);return $op;
};

// Orders turned off while the contact step waited: the order holds for Orders and goes back by itself once they are on.
$op=$order('held-contact-orders-off-placeholder.com');$before=[$sent->contactAdd,$sent->registerDomain];
$grants->fail=static function()use($account):void {DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);throw new DomainException('TEST NameSilo feature off.');};
$paused=$execute($op);$grants->fail=null;$clock->instant=$clock->now()->modify('+60 seconds');Auth::login($staff);
same(['held','processing'],[$paused->state,$paused->safe_code],'Orders off at the contact step holds for Orders, not for the contact');
expect(OperationActions::relevant('refund',$paused->fresh()),'Cancel and refund stays available while Orders are off');
expect(!ContactStepRetries::readmit($paused->id,$clock),'The order waits while Orders are off');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);$releasedAt=$clock->now();
expect(ContactStepRetries::readmit($paused->id,$clock),'The order goes back by itself once Orders are on');
same(['queued',null],[$paused->fresh()->state,$paused->fresh()->safe_code],'Orders on puts the order back in the queue');
$clock->instant=$clock->now()->modify('+30 seconds');$resent=$execute($paused->fresh());
same([$before[0]+1,$before[1]+1],[$sent->contactAdd,$sent->registerDomain],'The resumed order sends once');
// The retry keeps the first claim's start, so History and the Sent row take the time it was really sent.
$sentAt=Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::time(new DateTimeImmutable($resent->attempt['dispatch_attempted_at']));
expect($sentAt!==Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::time($resent->started_at),'The retried send is later than the first claim');
$timeline=Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::timeline($resent,Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::rows($resent));
$sentRows=array_values(array_filter($timeline,fn($r)=>$r['event']===Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::SENT));
same([$sentAt],array_column($sentRows,'time'),'History has one Sent to registrar entry at the retried send');
expect(new DateTimeImmutable($resent->attempt['dispatch_attempted_at'])>$releasedAt,'The send comes after the release that queued it');
same($sentAt,Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::time(Paymenter\Extensions\Servers\Domains\Admin\Support\StaffReviewHistory::sentAt($resent)),'The Sent to registrar row shows the retried send');
echo "Orders off at the contact step resumes when Orders are on.\n";

// Turning Orders on again does not extend a queued contact retry past its saved send window.
$op=$order('held-contact-orders-off-lapsed-placeholder.com');$before=[$sent->contactAdd,$sent->registerDomain];
$grants->fail=static function()use($account):void {DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);throw new DomainException('TEST NameSilo feature off.');};
$paused=$execute($op);$grants->fail=null;$clock->instant=$clock->now()->modify('+60 seconds');Auth::login($staff);
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
expect(ContactStepRetries::readmit($paused->id,$clock),'The order goes back to the queue once Orders are on');
$window=AcquisitionResult::window($paused->fresh());
expect($window!==null,'The retry has a saved window');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>false]);
$clock->instant=$clock->now()->modify('+'.(Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::EXECUTION_SECONDS+60).' seconds');
DB::table('domains_accounts')->where('id',$account->id)->update(['processing_enabled'=>true]);
$held=$execute($paused->fresh());Auth::login($staff);
same(['held','write_rate_limited'],[$held->state,$held->safe_code],'The elapsed contact retry holds');
same($before,[$sent->contactAdd,$sent->registerDomain],'The elapsed retry sends nothing');
expect($window==AcquisitionResult::window($held->fresh()),'The worker preserves the elapsed window');
$run($held,'release');
expect(AcquisitionResult::window($held->fresh())?->revision>$window->revision,'Staff Release saves a fresh window');
$clock->instant=$clock->now()->modify('+30 seconds');
$sentAfter=$execute($held->fresh());
same([$before[0]+1,$before[1]+1],[$sent->contactAdd,$sent->registerDomain],'The explicit release sends once');
same(0,ContactStepRetries::tries($sentAfter->fresh()),'Waiting for Orders spends no automatic try');
echo "A queued contact retry with an elapsed window needs a fresh admission.\n";

// A contact hold with nothing sent retries by itself a few spaced times, then stays for staff. It never cancels or refunds.
$op=$resetHeld();$viaGateway($op,'receipt_held_contact_auto_retry');
$money=[$nativeMoney(),$serviceStatus($op),MoneyReceiptGateway::$sends];$before=[$sent->contactAdd,$sent->registerDomain];
expect(!ContactStepRetries::readmit($op->id,$clock),'The first automatic try waits');
foreach(ContactStepRetries::WAITS as $i=>$wait){
    $clock->instant=$clock->now()->modify('+'.$wait.' seconds');
    expect(ContactStepRetries::readmit($op->id,$clock),'Automatic try '.($i+1).' starts');
    same(['queued',null],[$op->fresh()->state,$op->fresh()->safe_code],'Automatic try '.($i+1).' queues the order');
    $grants->cap=true;$held=$execute($op->fresh());$grants->cap=false;Auth::login($staff);
    same(['held','contact_profile_proof_required'],[$held->state,$held->safe_code],'Automatic try '.($i+1).' holds again');
}
same($before,[$sent->contactAdd,$sent->registerDomain],'Automatic tries sent nothing');
$clock->instant=$clock->now()->modify('+2 days');
expect(!ContactStepRetries::readmit($op->id,$clock),'After the last try the hold stays for staff');
same(['held','contact_profile_proof_required'],[$op->fresh()->state,$op->fresh()->safe_code],'The hold is kept');
same($money,[$nativeMoney(),$serviceStatus($op),MoneyReceiptGateway::$sends],'No automatic cancel or refund');
same(null,$op->fresh()->funding['refund_authorization']??null,'No refund was authorized');
expect(OperationActions::relevant('refund',$op->fresh())&&HeldRelease::offered($op->fresh(),$clock->now()),'Staff keep Release hold and Cancel and refund');
same([['release',false],['refund',false]],$heldControls($op->domain->fresh()),'The domain page keeps both actions on the held order row');
echo "Automatic contact retries stop at the limit and never refund.\n";
$nativeSuccessful=true;
