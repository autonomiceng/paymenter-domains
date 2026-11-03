<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,WriteReservation};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{ContactProfile,Operation,ProfileContactOperation as ProfileOperation};
/** One contactAdd, then read-only recovery by its durably returned ID. Explicit injection only. */
final class ProfileContactOperations
{
    private readonly \SensitiveParameterValue $exchange;
    public function __construct(private readonly AccountBinding $binding,private readonly OperationClaims $claims,
        private readonly Clock $clock,private readonly WriteReservation $reservation,#[\SensitiveParameter] \Closure $exchange)
    {
        if($binding->registrarKey!=='namesilo'||!$reservation->bindsTo($binding)){throw new \DomainException('Profile transport binding unavailable.');}
        $this->exchange=new \SensitiveParameterValue($exchange);
    }
    public function bindsTo(AccountBinding $binding): bool {return $this->binding->matches($binding);}
    public function prepare(Operation $op,RequestContext $ctx): ContactProfile
    {
        OperationClaims::outside();$this->binding->requireMatch($ctx->binding);
        if($ctx->origin!==RequestOrigin::Background){throw new WriteHeld('contact_profile_proof_required');}
        $contacts=ContactDetails::validate('namesilo',$op->payload['contacts']??null);
        if(count(array_unique(array_map(static fn($c)=>json_encode($c,JSON_THROW_ON_ERROR),$contacts)))!==1){throw new WriteHeld('contact_profile_proof_required');}
        $contact=$contacts['registrant'];$parameters=RegistrationProfiles::parameters($contact);
        $row=$this->authority($op,function(int $owner)use($op):ProfileOperation {
            $row=ProfileOperation::where('operation_id',$op->id)->lockForUpdate()->first();
            if(!$row){$row=ProfileOperation::writing(fn()=>ProfileOperation::create(['operation_id'=>$op->id,'user_id'=>$owner,
                'server_id'=>$this->binding->serverId,'account_fingerprint'=>$this->binding->fingerprint,'authority_generation'=>$op->authority_generation,
                'payload_digest'=>OperationClaims::payloadDigest($op),'state'=>'ready',
                'attempt'=>['claim_key'=>bin2hex(random_bytes(16)),'parent_claim'=>$op->attempt['claim_key'],'seal'=>'absent','send_state'=>'definitely_unsent']]));}
            $this->matches($row,$op,$owner);return $row;
        });
        try {return $this->profile($op);}catch(\Throwable){} // Fresh verified evidence survives worker reconstruction.
        $worker=null;
        try {
            return $this->reservation->run('management',(int)$row->id,function()use($row,$op,$ctx,$contact,$parameters,&$worker):ContactProfile {
                try {
                    if($row->state==='ready'){$this->create($row,$op,$ctx,$parameters,$worker);}
                    elseif($row->state==='sealed'&&new \DateTimeImmutable($row->attempt['lease_until'])<=$this->clock->now()){
                        $this->change($row,static fn($r)=>$r->state==='sealed'?['state'=>'uncertain','attempt'=>[...$r->attempt,'execution'=>'unknown']]:[]);
                    }
                    $current=$row->fresh();
                    if(!in_array($current->state,['created','verified'],true)){throw new WriteHeld('contact_profile_proof_required');}
                    return $this->readback($current,$op,$ctx,$contact,$parameters);
                }catch(\Throwable){
                    $this->change($row,function($r)use($worker):array {
                        if($worker===null||$r->state!=='sealed'||($r->attempt['worker_key']??null)!==$worker){return [];}
                        $unsent=$this->reservation->unconsumed((int)$r->id);
                        return ['state'=>$unsent?'unsent':'uncertain','attempt'=>[...$r->attempt,'execution'=>$unsent?'definitely_unsent':'unknown']];
                    });
                    throw new WriteHeld('contact_profile_proof_required');
                }
            });
        }catch(\Throwable){throw new WriteHeld('contact_profile_proof_required');}
    }
    private function create(ProfileOperation $row,Operation $op,RequestContext $ctx,array $parameters,?string &$worker): void
    {
        $ctx->claimRead($this->binding);
        $ctx->withReservedWire(function()use($row,$op,$ctx,$parameters,&$worker):void {
            $this->reservation->reserve('contactAdd',$parameters); // Own grant, never the purchase grant; zero monetary cost.
            $worker=bin2hex(random_bytes(16));
            $this->authority($op,function(int $owner)use($row,$op,$ctx,$worker):void {
                $r=ProfileOperation::whereKey($row->id)->lockForUpdate()->firstOrFail();$this->matches($r,$op,$owner);
                if($r->state!=='ready'){throw new \DomainException('Profile attempt already claimed.');}
                $ctx->requireReservedCapacity();
                $this->update($r,['state'=>'sealed','attempt'=>[...$r->attempt,'seal'=>'sealed','send_state'=>'possibly_sent',
                    'worker_key'=>$worker,'lease_until'=>min($ctx->deadline,$this->clock->now()->modify('+30 seconds'))->format(DATE_ATOM)]]);
            });
            $body=$ctx->withinClaimedWire($this->binding,function(int $remaining)use($row,$op,$worker,$parameters):string {
                $this->authority($op,function(int $owner)use($row,$op,$worker):void {
                    $r=ProfileOperation::whereKey($row->id)->lockForUpdate()->firstOrFail();$this->matches($r,$op,$owner);
                    if($r->state!=='sealed'||($r->attempt['worker_key']??null)!==$worker||isset($r->attempt['dispatch_at'])
                        ||new \DateTimeImmutable($r->attempt['lease_until'])<=$this->clock->now()){throw new \DomainException('Profile send authority expired.');}
                    $this->update($r,['attempt'=>[...$r->attempt,'dispatch_at'=>$this->clock->now()->format(DATE_ATOM)]]);
                });
                return $this->exchange('contactAdd',$parameters,$remaining);
            });
            $id=self::reply($body,'contactAdd')['contact_id']??null;if(is_int($id)){$id=(string)$id;}
            if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id)){throw new \DomainException('Profile creation unresolved.');}
            // Retain returned identity before any readback or parent-authority check can fail.
            $this->change($row,function($r)use($id,$worker):array {
                if($r->state!=='sealed'||($r->attempt['worker_key']??null)!==$worker){throw new \DomainException('Profile response authority changed.');}
                return ['state'=>'created','attempt'=>[...$r->attempt,'provider_id'=>$id,'received_at'=>$this->clock->now()->format(DATE_ATOM)]];
            });
        });
    }
    private function readback(ProfileOperation $row,Operation $op,RequestContext $ctx,array $contact,array $parameters): ContactProfile
    {
        $id=$row->attempt['provider_id'];
        $body=$ctx->withinRead($this->binding,fn($remaining)=>$this->exchange('contactList',['contact_id'=>$id],$remaining));
        RegistrationProfiles::requireReadback(self::reply($body,'contactList'),$parameters,$id);
        $this->authority($op,fn(int $owner)=>$this->matches($row,$op,$owner));
        // Fixed proof bytes make a crash after recording but before linking idempotent.
        $profile=ContactProfiles::recordCreated($this->binding,(int)$row->user_id,$id,$contact,false,
            ['operation'=>$op->operation_key,'payload'=>$row->payload_digest,'observed_at'=>$row->attempt['received_at']]);
        $this->authority($op,function(int $owner)use($row,$op,$profile):void {
            $r=ProfileOperation::whereKey($row->id)->lockForUpdate()->firstOrFail();$this->matches($r,$op,$owner);
            if(!in_array($r->state,['created','verified'],true)){throw new \DomainException('Profile evidence unavailable.');}
            $this->update($r,['state'=>'verified','attempt'=>[...$r->attempt,'profile_id'=>(int)$profile->id,'observed_at'=>$this->clock->now()->format(DATE_ATOM)]]);
        });
        return $this->profile($op);
    }
    public function profile(Operation $op): ContactProfile
    {
        $row=ProfileOperation::where('operation_id',$op->id)->firstOrFail();$this->matches($row,$op,(int)$op->domain->user_id);
        $at=new \DateTimeImmutable($row->attempt['observed_at']??'1970-01-01T00:00:00Z');
        if($row->state!=='verified'||$at>$this->clock->now()||$at<=$this->clock->now()->modify('-120 seconds')){throw new WriteHeld('contact_profile_proof_required');}
        $profile=ContactProfile::findOrFail($row->attempt['profile_id']);$details=$profile->details;
        if((int)$profile->user_id!==$row->user_id||(int)$profile->server_id!==$row->server_id||$profile->account_fingerprint!==$row->account_fingerprint
            ||($details['provider_id']??null)!==$row->attempt['provider_id']||($details['contact']??null)!==ContactDetails::validate('namesilo',$op->payload['contacts'])['registrant']
            ||($details['registration_proof']??null)!==['operation'=>$op->operation_key,'payload'=>$row->payload_digest,'observed_at'=>$row->attempt['received_at']]){throw new \DomainException('Profile ownership proof unavailable.');}
        return $profile;
    }
    private function authority(Operation $op,\Closure $work): mixed
    {return $this->claims->withProfileAuthority($op,function($binding,$owner)use($work){$this->binding->requireMatch($binding);return $work($owner);});}
    private function matches(ProfileOperation $row,Operation $op,int $owner): void
    {
        if($row->operation_id!==(int)$op->id||$row->user_id!==$owner||$row->server_id!==$this->binding->serverId
            ||$row->account_fingerprint!==$this->binding->fingerprint||$op->account_fingerprint!==$row->account_fingerprint
            ||$row->authority_generation!==$op->authority_generation||$row->payload_digest!==OperationClaims::payloadDigest($op)
            ||($row->attempt['parent_claim']??null)!==($op->attempt['claim_key']??null)){throw new \DomainException('Profile operation authority changed.');}
    }
    private function change(ProfileOperation $row,\Closure $changes): void
    {DB::transaction(function()use($row,$changes):void {$r=ProfileOperation::whereKey($row->id)->lockForUpdate()->firstOrFail();$data=$changes($r);if($data!==[]){$this->update($r,$data);}});}
    private function update(ProfileOperation $row,array $changes): void
    {ProfileOperation::writing(fn()=>$row->update($changes));}
    private function exchange(string $command,#[\SensitiveParameter] array $parameters,int $remaining): string
    {
        OperationClaims::outside();
        $origin=($this->binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live
            ?\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live:\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Sandbox)->origin().'/apibatch';
        return ($this->exchange->getValue())($origin,$command,$parameters,min(25000,$remaining));
    }
    private static function reply(#[\SensitiveParameter] string $body,string $command): array
    {
        if(strlen($body)>1048576){throw new \DomainException('Profile response unavailable.');}
        $data=json_decode($body,true,24,JSON_THROW_ON_ERROR);
        if(($data['request']['operation']??null)!==$command||!in_array($data['reply']['code']??null,[300,'300'],true)){throw new \DomainException('Profile response unavailable.');}
        return $data['reply'];
    }
    public function __debugInfo(): array {return [];}
    public function __serialize(): never {throw new \LogicException('Profile authorities cannot be persisted.');}
    public function __clone(): void {throw new \LogicException('Profile authorities cannot be cloned.');}
}
