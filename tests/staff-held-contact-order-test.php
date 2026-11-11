<?php
// A paid NameSilo order held at its contact step with nothing sent: staff Release sends it once; Cancel and refund
// cancels the order and its service, then refunds once. Both are refused once anything went to NameSilo.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
require_once __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/money-receipt.php';
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,WriteReservation};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Money};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations,OperationClaims,ProfileContactOperations,ProfileContactSchema,Refunds,RegistrationPreflight,RegistrationProfiles,StaffOperationReview,StaffOperations,StaffOperationWrites,WriteAcceptance,WriteFeatures};
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
    public ?int $active=null;public bool $reserved=false;public bool $consumed=false;public bool $cap=false;
    public function __construct(private AccountBinding $binding){}
    public function bindsTo(AccountBinding $b):bool{return $this->binding->matches($b);}
    public function run(string $feature,int $id,Closure $work):mixed{$this->active=$id;$this->reserved=$this->consumed=false;try{return $work();}finally{$this->active=null;}}
    public function reserve(string $command,#[SensitiveParameter] array $parameters,?Money $cost=null):void{if($this->cap){throw new RuntimeException('Synthetic local cap');}$this->reserved=true;}
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
$serviceStatus=fn(Operation $op)=>App\Models\Service::findOrFail($op->service_id)->status;
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Synthetic receipt','type'=>'gateway','enabled'=>true]);
$viaGateway=static function(Operation $op,string $receipt)use($gateway):void {
    DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id,'transaction_id'=>$receipt]);
};

// Release sends the order once.
$op=$heldOrder('held-contact-release-placeholder.com');
expect(StaffOperationWrites::canRelease($op,$clock->now()),'Release is offered for an unsent contact step hold');
expect(OperationActions::relevant('refund',$op),'Cancel and refund is offered for an unsent contact step hold');
$account=Account::where('server_id',$op->server_id)->sole();
$costStaff=staffUser([...$all,Staff::COSTS]);Auth::login($costStaff);
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
expect(!StaffOperationWrites::canRelease($op->fresh(),$clock->now())&&!OperationActions::relevant('refund',$op->fresh()),'Both actions are gone once the order was sent');
$refused(fn()=>$run($op->fresh(),'refund'),'Cancel and refund is refused after a send','Only held unpaid-to-provider requests may be closed.');
same(null,$op->fresh()->funding['refund_authorization']??null,'No refund after a send');
echo "Release sends a held contact step order once.\n";

// Cancel and refund cancels the order and its service, then refunds once through the gateway.
$op=$heldOrder('held-contact-refund-placeholder.com');$viaGateway($op,'receipt_held_contact_refund');
MoneyReceiptGateway::$lost=false;MoneyReceiptGateway::$outcome='refunded';$sends=MoneyReceiptGateway::$sends;
$words=OperationActions::refundWords($op);
expect(str_starts_with($words,'Cancels this order and its service, then refunds USD '.number_format((float)$op->amount,2,'.','').' to the original payment.'),'The confirm says it cancels first and what it refunds');
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
$op=$heldOrder('held-contact-refund-fails-placeholder.com');$viaGateway($op,'');$sends=MoneyReceiptGateway::$sends;
$page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionVisible('refund')->assertActionVisible('release');
$page->callAction('refund',['reason'=>'Customer asked for a refund'])->assertHasNoActionErrors()
    ->assertNotified(Notification::make()->warning()->title('Cancelled, refund not finished')
        ->body("The order and its service are cancelled. The refund hasn't gone through (ref guard_refused). Use Retry refund. It reuses this refund, so the customer is never refunded twice."));
same(['rejected','refund_approved'],[$op->fresh()->state,$op->fresh()->safe_code],'The cancel is kept');
same(App\Models\Service::STATUS_CANCELLED,$serviceStatus($op),'The service stays cancelled when the refund fails');
same(null,$op->fresh()->funding['refund']??null,'No refund was recorded');same($sends,MoneyReceiptGateway::$sends,'No refund was sent');
same(true,$op->fresh()->policy['held_order_refund']??false,'The paid held-order cancellation records its own refund marker');
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
$retry=$run($op->fresh(),'credit',$retryKey);
same($retained['refund'],$op->fresh()->funding['refund'],'A retained-plan recheck preserves the confirmed refund evidence');
same($retained['refund_authorization'],$op->fresh()->funding['refund_authorization'],'The original refund authority stays');
same(['rejected','refund_approved',App\Models\Service::STATUS_CANCELLED],[$op->fresh()->state,$op->fresh()->safe_code,$serviceStatus($op)],'Rechecking a finished return keeps the order and service cancelled');
$auditAfter=$op->fresh()->policy['staff_actions'];
same($auditBefore,array_slice($auditAfter,0,count($auditBefore)),'The retained-plan recheck preserves prior staff history');
same(count($auditBefore)+1,count($auditAfter),'A fresh retained-plan review writes one audit');
same(['credit','completed',$staff->id,'Customer order reviewed by staff'],[end($auditAfter)['action'],end($auditAfter)['phase'],end($auditAfter)['actor_id'],end($auditAfter)['reason']],'The fresh review records its real action and actor');
$refused(fn()=>$run($op->fresh(),'credit',$retryKey),'A repeated retained-plan review is refused',$changed);
same($auditAfter,$op->fresh()->policy['staff_actions'],'The refused duplicate review writes no extra audit');
same($sends+1,MoneyReceiptGateway::$sends,'Never a second refund');
echo "A failed refund keeps the cancel and retries once.\n";

// Once the contact step may have reached NameSilo, both actions are refused.
$op=$nsNew(placeholderComFixtureName('held-contact-sent-placeholder.com'));
$op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
$sent->readFails=true;$before=$sent->contactAdd;$held=$execute($op);$sent->readFails=false;$clock->instant=$clock->now()->modify('+60 seconds');Auth::login($staff);
same(['held','contact_profile_proof_required'],[$held->state,$held->safe_code],'A contact step that sent holds the order');
same([$before+1,'created'],[$sent->contactAdd,ProfileContactOperation::where('operation_id',$op->id)->sole()->state],'The contact reached NameSilo');
$held=$held->fresh();$money=[$nativeMoney(),$serviceStatus($held),MoneyReceiptGateway::$sends];
expect(!StaffOperationWrites::canRelease($held,$clock->now())&&!OperationActions::relevant('refund',$held),'Neither action is offered after a contact send');
try{$run($held,'refund');throw new LogicException('Cancel and refund must be refused after a contact send');}
catch(Paymenter\Extensions\Servers\Domains\Services\OperationRefused $error){same('The registrar may have received this request.',$error->getMessage(),'Refusal says why');}
$refused(fn()=>$run($held->fresh(),'release'),'Release is refused after a contact send',$original);
same(['held','contact_profile_proof_required'],[$held->fresh()->state,$held->fresh()->safe_code],'The hold is kept');
same($money,[$nativeMoney(),$serviceStatus($held),MoneyReceiptGateway::$sends],'No money moves and the service is unchanged');
same($before+1,$sent->contactAdd,'Nothing more is sent');
echo "Both actions are refused once anything reached NameSilo.\n";

// The saved contact step is bound to the original payload, so changed details cannot be released with it.
$op=$heldOrder('held-contact-details-changed-placeholder.com');
$payload=$op->payload;$payload['contacts']['registrant']['first_name']='Changed';
\Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update(['payload'=>$payload]));$op=$op->fresh();
$before=[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->attempt];
expect(!StaffOperationWrites::canRelease($op,$clock->now()),'Changed order details cannot release the saved contact step');
expect(OperationActions::relevant('refund',$op),'A changed unsent order can still be cancelled and refunded');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('release')
    ->assertActionVisible('refund')->assertSee('The contact step cannot be retried with these order details. Cancel and refund this order.');
$refused(fn()=>$run($op,'release'),'The writer also refuses changed details','The contact step cannot be retried with these order details. Cancel and refund this order.');
same(['held','contact_profile_proof_required'],[$op->fresh()->state,$op->fresh()->safe_code],'Changed details stay held');
same($before,[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->fresh()->attempt],'Refused release changes no money or send evidence');
echo "Changed details cannot release a saved contact step.\n";

// A completed unsent contact step cannot run again, even though its saved order details still match.
$op=$heldOrder('held-contact-ended-placeholder.com');
$row=ProfileContactOperation::where('operation_id',$op->id)->sole();
ProfileContactOperation::writing(fn()=>$row->update(['state'=>'sealed','attempt'=>[...$row->attempt,
    'seal'=>'sealed','send_state'=>'possibly_sent','worker_key'=>str_repeat('a',32),'lease_until'=>$clock->now()->format(DATE_ATOM)]]));
ProfileContactOperation::writing(fn()=>$row->update(['state'=>'unsent','attempt'=>[...$row->attempt,'execution'=>'definitely_unsent']]));
same(OperationClaims::payloadDigest($op),$row->fresh()->payload_digest,'The ended contact step still matches the order details');
$before=[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->attempt,$row->fresh()->attempt];
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionHidden('release')->assertActionVisible('refund')
    ->assertDontSee('The contact step cannot be retried with these order details.')
    ->assertSee('This contact step has ended and cannot be retried. Cancel and refund this order.');
try{$run($op,'release');throw new LogicException('An ended contact step cannot be released');}
catch(DomainException $error){same('This contact step has ended and cannot be retried. Cancel and refund this order.',$error->getMessage(),'The writer names the ended step without blaming unchanged details');}
same(['held','contact_profile_proof_required'],[$op->fresh()->state,$op->fresh()->safe_code],'The ended contact step stays held');
same($before,[$nativeMoney(),$sent->contactAdd,$sent->registerDomain,$op->fresh()->attempt,$row->fresh()->attempt],'An ended contact step changes no money or send evidence');
echo "An ended contact step does not blame unchanged order details.\n";
$nativeSuccessful=true;
