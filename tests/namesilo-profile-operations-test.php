<?php
// Installed synthetic authority/restart proof needs real native funding, locks and encrypted model casts.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\WriteReservation;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Money,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{ContactProfile,Operation,ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Services\{OperationClaims,ProfileContactOperations,ProfileContactSchema,RegistrationProfiles,WriteHeld};
expect(is_file(dirname(__DIR__).'/Domains/Services/ProfileContactOperations.php'),'Profile creation needs its own durable authority ledger');
ProfileContactSchema::install();ProfileContactSchema::verify();
$grants=new class($binding) implements WriteReservation {
    public ?int $active=null;public bool $reserved=false;public bool $consumed=false;public bool $cap=false;public int $reservations=0;
    public function __construct(private AccountBinding $binding){}
    public function bindsTo(AccountBinding $b):bool{return $this->binding->matches($b);}
    public function run(string $feature,int $id,Closure $work):mixed {
        same('management',$feature,'Contact creation owns management authority');expect($this->active===null,'No nested profile send scope');
        $this->active=$id;$this->reserved=false;$this->consumed=false;try{return $work();}finally{$this->active=null;}
    }
    public function reserve(string $command,#[SensitiveParameter] array $parameters,?Money $cost=null):void {
        same('contactAdd',$command,'Only creation reserves');same(null,$cost,'Profile creation has no monetary spend');
        if($this->cap){throw new RuntimeException('Synthetic local cap');}$this->reserved=true;$this->reservations++;
    }
    public function unconsumed(int $id):bool{return $this->active===$id&&$this->reserved&&!$this->consumed;}
};
$mode='ok';$writes=0;$reads=0;$nativeExchange=$exchange;$afterReply=null;
$profileExchange=static function($origin,$command,$parameters,$timeout)use(&$mode,&$writes,&$reads,$grants,$nativeExchange,&$afterReply):string {
    same(0,DB::transactionLevel(),'No profile wire work under locks');
    if($command==='contactAdd'){
        $row=ProfileContactOperation::findOrFail($grants->active);
        same('sealed',$row->state,'Dedicated seal durable before mutation');expect(isset($row->attempt['dispatch_at']),'Own send authority consumed before exchange');
        if($mode==='unsent'){throw new RuntimeException('Synthetic prewire refusal');}
        $grants->consumed=true;$writes++;
        if($mode==='lost'){throw new RuntimeException('Synthetic lost contact creation response');}
    }else{$reads++;if($mode==='readfail'){throw new RuntimeException('Synthetic readback loss');}}
    $body=$nativeExchange($origin,$command,$parameters,$timeout);
    if($command==='contactAdd'&&$afterReply){$work=$afterReply;$afterReply=null;$work($body);}
    return $body;
};
$makeProfiles=static function()use($binding,$claims,$clock,$grants,$profileExchange):RegistrationProfiles {
    $ops=new ProfileContactOperations($binding,$claims,$clock,$grants,$profileExchange);
    return new RegistrationProfiles($binding,$claims,$clock,static fn()=>throw new LogicException('Legacy profile sender must not run'),operations:$ops);
};
$context=static fn()=>new RequestContext($binding,RequestOrigin::Background,$clock->now()->modify('+240 seconds'),new RequestBudget(12),$clock);
$claim=static function(string $name)use($nsNew,$claims):Operation {
    $op=$nsNew(placeholderComFixtureName($name));$op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
    return $claims->claim($op->id)[0];
};
$refuse=static function(callable $work):void {try{$work();throw new LogicException('Expected held profile');}catch(WriteHeld){}};
foreach(['ok','unsent','lost','readfail','cap'] as $case){
    $mode=$case;$op=$claim('profile-'.$case.'-placeholder.com');$money=$nativeMoney();$before=$writes;$grants->cap=$case==='cap';
    $profiles=$makeProfiles();
    if($case==='ok'){$profiles->prepare($op,$context());}else{$refuse(fn()=>$profiles->prepare($op,$context()));}
    $row=ProfileContactOperation::where('operation_id',$op->id)->sole();
    same(match($case){'ok'=>'verified','unsent'=>'unsent','lost'=>'uncertain','readfail'=>'created','cap'=>'ready'},$row->state,'Durable profile outcome');
    same(1,Operation::where('domain_id',$op->domain_id)->count(),'No domain-operation child rows');same('absent',$op->fresh()->attempt['seal'],'Profile work cannot seal a purchase');
    same($money,$nativeMoney(),'Profile work preserves native money and jobs');
    same(in_array($case,['unsent','cap'],true)?0:1,$writes-$before,'One creation or proven zero physical mutation');
    if($case==='ok'){
        $profiles=$makeProfiles();expect($profiles->hasProof($op),'Reconstructed worker resolves durable handle');$readCount=$reads;$profiles->prepare($op,$context());same($readCount,$reads,'Fresh verified proof needs no extra read');
        $clock->instant=$clock->now()->modify('+121 seconds');$profiles=$makeProfiles();expect(!$profiles->hasProof($op),'Stale proof cannot authorize purchase');
        $profiles->prepare($op,$context());same($readCount+1,$reads,'Expired proof refreshes by exact ID only');same($before+1,$writes,'Refresh never creates another contact');
        $raw=$row->fresh()->getRawOriginal('attempt');expect(!str_contains($raw,'provider_id')&&!str_contains($raw,'observed_at'),'Profile identity/evidence encrypted');
        commerceReject(fn()=>$row->update(['state'=>'ready']));commerceReject(fn()=>$row->delete());
        commerceReject(fn()=>ProfileContactOperation::writing(fn()=>$row->update(['attempt'=>array_replace($row->attempt,['provider_id'=>'999999'])])));
        $row->refresh();commerceReject(fn()=>ProfileContactOperation::writing(fn()=>$row->update(['user_id'=>$row->user_id+1])));
        $other=clone $op;$other->payload=[...$op->payload,'privacy'=>true];expect(!$profiles->hasProof($other),'Payload cannot adopt another profile proof');
        $other=clone $op;$other->server_id++;expect(!$profiles->hasProof($other),'Another server cannot adopt the retained handle');
        $other=clone $op;$other->registrar_key='enom';expect(!$profiles->hasProof($other),'Another registrar cannot adopt the retained handle');
        $clock->instant=$clock->now()->modify('-121 seconds');
    }elseif($case==='readfail'){
        $mode='ok';$profiles=$makeProfiles();$profiles->prepare($op,$context());same('verified',$row->fresh()->state,'Restart resumes known ID readback');same($before+1,$writes,'Restart does not repeat creation');
    }elseif($case==='cap'){
        $grants->cap=false;$mode='ok';$makeProfiles()->prepare($op,$context());same('verified',$row->fresh()->state,'Unsealed local reservation refusal can be freshly admitted');
    }else{
        $mode='ok';$refuse(fn()=>$makeProfiles()->prepare($op,$context()));same($before+($case==='lost'?1:0),$writes,'Terminal creation attempt never replays');
        same($case==='lost'?'unknown':'definitely_unsent',$row->attempt['execution'],'No response is not local unsent proof');
    }
}
// A valid reply is available to worker A; B expires its seal before A saves the ID.
$op=$claim('profile-late-reply-placeholder.com');$mode='ok';$before=$writes;$money=$nativeMoney();
$afterReply=static function(string $body)use($op,$clock,$grants,$binding,$claims,$profileExchange,$context,$refuse):void {
    $reply=json_decode($body,true,24,JSON_THROW_ON_ERROR);
    same(300,(int)$reply['reply']['code'],'Original worker already has a valid creation reply');
    expect(isset($reply['reply']['contact_id']),'Returned identity exists before competing worker runs');
    $clock->instant=$clock->now()->modify('+31 seconds');
    $other=clone $grants;$other->active=null;$other->reserved=false;$other->consumed=false;
    $workerB=new ProfileContactOperations($binding,$claims,$clock,$other,$profileExchange);
    $refuse(fn()=>$workerB->prepare($op,$context()));
    same('uncertain',ProfileContactOperation::where('operation_id',$op->id)->sole()->state,'Worker B expires the dispatched seal without replay');
};
$prepared=true;try{$makeProfiles()->prepare($op,$context());}catch(WriteHeld){$prepared=false;}
$row=ProfileContactOperation::where('operation_id',$op->id)->sole();
expect(isset($row->attempt['provider_id'],$row->attempt['received_at']),'Late matching reply must retain its ID and receipt after seal expiry');
expect($prepared,'Current parent and exact readback can verify the retained late ID');
same(['verified','sealed','unknown'],[$row->state,$row->attempt['seal'],$row->attempt['execution']],'Late receipt retains original seal and uncertain classification evidence');
same($before+1,$writes,'Two workers create only one contact');same($money,$nativeMoney(),'Late receipt preserves native money');
$makeProfiles()->prepare($op,$context());same($before+1,$writes,'Reconstruction never replays late acknowledged creation');
$clock->instant=$clock->now()->modify('-31 seconds');
// Simulate a worker ending after committing its seal, before retaining any response identity.
$op=$claim('profile-sealed-placeholder.com');$grants->cap=true;$refuse(fn()=>$makeProfiles()->prepare($op,$context()));$grants->cap=false;
$row=ProfileContactOperation::where('operation_id',$op->id)->sole();
ProfileContactOperation::writing(fn()=>$row->update(['state'=>'sealed','attempt'=>[...$row->attempt,'seal'=>'sealed','send_state'=>'possibly_sent',
    'worker_key'=>bin2hex(random_bytes(16)),'lease_until'=>$clock->now()->modify('+30 seconds')->format(DATE_ATOM)]]));
$before=$writes;$refuse(fn()=>$makeProfiles()->prepare($op,$context()));same('sealed',$row->fresh()->state,'Other worker cannot classify an active send as its own unsent grant');
$clock->instant=$clock->now()->modify('+31 seconds');$refuse(fn()=>$makeProfiles()->prepare($op,$context()));
same('uncertain',$row->fresh()->state,'Expired worker without identity remains uncertain');same($before,$writes,'Worker expiry never authorizes another contactAdd');
$clock->instant=$clock->now()->modify('-31 seconds');
// Proof lookup measures elapsed freshness; a wall-clock fall-back cannot extend old evidence.
$row=ProfileContactOperation::where('state','verified')->firstOrFail();$op=Operation::findOrFail($row->operation_id);$saved=$clock->instant;
$beforeFallback=(new DateTimeImmutable('2026-11-01T08:59:00Z'))->setTimezone(new DateTimeZone('America/Los_Angeles'));
ProfileContactOperation::writing(fn()=>$row->update(['attempt'=>[...$row->attempt,'observed_at'=>$beforeFallback->format(DATE_ATOM)]]));
$clock->instant=(new DateTimeImmutable('2026-11-01T09:01:01Z'))->setTimezone(new DateTimeZone('America/Los_Angeles'));
expect(!$makeProfiles()->hasProof($op),'Fall-back cannot extend the 120-second proof lifetime');
ProfileContactOperation::writing(fn()=>$row->update(['attempt'=>[...$row->attempt,'observed_at'=>$clock->now()->format(DATE_ATOM)]]));
expect($makeProfiles()->hasProof($op),'A later absolute observation can have an earlier wall-clock hour');$clock->instant=$saved;
// Current parent authority is rechecked before allocating any profile ledger entry.
$op=$claim('profile-drift-placeholder.com');$op->fresh()->update(['policy'=>[...$op->policy,'synthetic_drift'=>true]]);
try{$makeProfiles()->prepare($op,$context());throw new LogicException('Expected parent authority refusal');}catch(\Paymenter\Extensions\Servers\Domains\Services\ProfileAuthorityMismatch){}
same(0,ProfileContactOperation::where('operation_id',$op->id)->count(),'Stale parent cannot create a ledger authority');
$indexes=Schema::getIndexes(ProfileContactSchema::TABLE);$unique=array_values(array_filter($indexes,static fn($i)=>$i['unique']&&$i['columns']===['operation_id']));same(1,count($unique),'One durable profile authority per parent');
Schema::table(ProfileContactSchema::TABLE,static fn($t)=>$t->dropUnique(['operation_id']));
try{ProfileContactSchema::verify();throw new LogicException('Expected retained ledger schema refusal');}catch(RuntimeException $e){expect(str_contains($e->getMessage(),'schema mismatch'),'Missing uniqueness fails retained verification');}
echo "Dedicated profile operations retain authority, creation outcomes and read-only recovery without domain child rows.\n";
$nativeSuccessful=true;
