<?php
// Local read limits at the contact step stay on the three spaced automatic tries, never a balance retry, and then end
// on the staff hold with Release hold and Cancel and refund.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,WriteReservation};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Money};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\{ContactStepRetries,ReadDeferred,DomainOperations,OperationClaims,ProfileContactOperations,ProfileContactSchema,RegistrationPreflight,RegistrationProfiles,StaffOperationReview,StaffOperationWrites,WriteAcceptance,WriteFeatures};
ProfileContactSchema::install();staffPanel();
app()->instance(Clock::class,$clock);
$all=[Staff::VIEW,'admin.domains.manage','admin.domains.fund','admin.invoices.update'];
foreach(array_keys(StaffOperationReview::ACTIONS) as $action){$all[]=StaffOperationReview::permission($action);}
$staff=staffUser($all);Auth::login($staff);

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
// A new paid order with saved nameservers, ready for its contact step.
$order=static function(string $name)use($nsNew):Operation {
    $op=$nsNew(placeholderComFixtureName($name));
    $op->update(['payload'=>$op->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);return $op;
};
$op=$order('held-contact-deferred-placeholder.com');$before=[$sent->contactAdd,$sent->registerDomain];
// Every try refuses on a local read limit: each holds on the bounded contact retries, never a balance retry, then staff decide.
$grants->fail=static fn()=>throw new ReadDeferred('Synthetic read limit');$held=$execute($op);
same(['held','write_rate_limited'],[$held->state,$held->safe_code],'A read limit at the contact step holds for the bounded contact retries');
same(false,array_key_exists('balance_retry',$held->fresh()->policy??[]),'A contact read limit starts no balance retry');
$clock->instant=$clock->now()->modify('+60 seconds');Auth::login($staff);
foreach(ContactStepRetries::WAITS as $i=>$wait){
    $clock->instant=$clock->now()->modify('+'.$wait.' seconds');
    expect(ContactStepRetries::readmit($op->id,$clock),'Read limit try '.($i+1).' starts');
    $held=$execute($op->fresh());Auth::login($staff);
    same(['held','write_rate_limited'],[$held->state,$held->safe_code],'Read limit try '.($i+1).' holds again');
    same(false,array_key_exists('balance_retry',$held->fresh()->policy??[]),'Read limit try '.($i+1).' starts no balance retry');
}
$grants->fail=null;$clock->instant=$clock->now()->modify('+1 hour');
expect(!ContactStepRetries::readmit($op->id,$clock),'Read limit retries stop after the last try');
expect(StaffOperationWrites::canRelease($op->fresh(),$clock->now())&&OperationActions::relevant('refund',$op->fresh()),'Staff get Release hold and Cancel and refund');
same($before,[$sent->contactAdd,$sent->registerDomain],'Read limit tries sent nothing');
echo "Contact read limits stay on the bounded retries and end with staff.\n";
$nativeSuccessful=true;
