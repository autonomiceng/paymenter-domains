<?php
// Synthetic default-contact inventory; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
function nameSiloDefaultContactReply(): string
{
    return json_encode(['request'=>['operation'=>'contactList'],'reply'=>['code'=>300,'contact'=>[
        ['contact_id'=>'900','default_profile'=>'1','email'=>'expected@example.com']]]],JSON_THROW_ON_ERROR);
}
function nameSiloGuardWire(object $guard): string
{
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Default contact reads stay outside locks');
    $guard->reads++;
    if($guard->mode==='failure'){throw new RuntimeException('Synthetic private failure');}
    if($guard->mode==='malformed'){return '{';}
    $row=['contact_id'=>$guard->id,'default_profile'=>$guard->default,'email'=>$guard->email];
    return json_encode(['request'=>['operation'=>'contactList'],'reply'=>['code'=>$guard->mode==='rejected'?200:300,
        'contact'=>$guard->mode==='singleton'?$row:($guard->mode==='duplicate'?[$row,$row]:[$row])]],JSON_THROW_ON_ERROR);
}
function nameSiloGuardExpected($binding,string $email): void
{
    App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(
        ['key'=>'domains_namesilo_expected_default_contact_email'],['value'=>$email,'encrypted'=>true,'type'=>'string']);
}
function assertNameSiloAccountRefusals($binding,object $guard,Closure $run,Closure $paidCalls): void
{
    foreach(['email','id','no-default','failure','blank','malformed','duplicate','rejected','pin-email'] as $case){
        $guard->email='expected@example.com';$guard->id='900';$guard->default='1';$guard->mode=$case;
        nameSiloGuardExpected($binding,$case==='blank'?'  ':($case==='pin-email'?'new@example.com':'expected@example.com'));
        if($case==='email'){$guard->email='other@example.com';}
        if($case==='id'){$guard->id='901';}
        if($case==='no-default'){$guard->default='0';}
        if($case==='pin-email'){$guard->email='new@example.com';}
        $before=$paidCalls();$reads=$guard->reads;$result=$run($case);
        same('held',$result->state,'Account '.$case.' refusal holds '.$result->action);
        same('namesilo_account_guard',$result->safe_code,'Staff receives a plain account refusal');
        same($before,$paidCalls(),'Account '.$case.' refusal sends no paid request');
        expect(!isset($result->attempt['dispatch_attempted_at']),'Account refusal creates no possible-send seal');
        same($case==='blank'?0:1,$guard->reads-$reads,'One guard read, none for blank setting');
        $pin=App\Models\Server::findOrFail($binding->serverId)->settings()->where('key','domains_namesilo_default_contact_fingerprint')->sole();
        expect($pin->encrypted,'Fingerprint remains encrypted');
        expect(!str_contains((string)$pin->getRawOriginal('value'),'expected@example.com'),'Stored fingerprint email is encrypted at rest');
        $saved=json_decode($pin->value,true,8,JSON_THROW_ON_ERROR);
        same(['binding'=>$binding->fingerprint,'contact_id'=>'900','email'=>'expected@example.com'],$saved,'A refusal never replaces the first fingerprint');
    }
    nameSiloGuardExpected($binding,' expected@EXAMPLE.com ');
    $guard->email=' EXPECTED@example.com ';$guard->id='900';$guard->default='1';$guard->mode='singleton';
}

function assertNameSiloDeferredGuard($op,$claims,$engine,$clock,object $guard,Closure $paidCalls): void
{
    $claim=$claims->claim($op->id);expect($claim!==null,'Funded acquisition obtains a genuine unsealed claim');
    same('queued',$claims->defer($claim[0])->state,'Capacity deferral retains an unsent claim');
    $evidence=$op->fresh()->attempt;$before=$paidCalls();$guard->mode='failure';
    Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$op->account_fingerprint);
    $held=$engine->execute($op->id);
    same('held',$held->state,'Account refusal holds a capacity-deferred acquisition');
    same('namesilo_account_guard',$held->safe_code,'Deferred acquisition retains account guidance');
    same($evidence,$held->attempt,'Account refusal retains prior unsent claim evidence');
    $reads=$guard->reads;$guard->mode='singleton';
    same('held',$engine->execute($op->id)->state,'Restored account requires staff release after a deferral');
    same($reads,$guard->reads,'Deferred account hold has no automatic read loop');
    same($before,$paidCalls(),'Deferred account refusal never sends a paid request');
    $originalStart=$op->fresh()->getRawOriginal('started_at');
    $clock->instant=$clock->now()->modify('+601 seconds');
    require_once __DIR__.'/staff-panel.php';
    $actor=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.release']);
    $release=static function()use($actor,$op,$clock):void {
        $previous=Illuminate\Support\Facades\Auth::user();Illuminate\Support\Facades\Auth::login($actor);
        try {
            $review=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::class;$held=$op->fresh();
            $ticket=$review::ticket($actor->id,$held->domain_id,$held->id,'release',$review::stamp($held,$held->domain->fresh()),$clock);
            (new Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($actor,$held->domain_id,$held->id,'release',$ticket,'Review default contact configuration');
        } finally {if($previous){Illuminate\Support\Facades\Auth::login($previous);}else{Illuminate\Support\Facades\Auth::logout();}}
    };
    $release();
    same('queued',$op->fresh()->state,'Staff can release a proven-unsent deferred account hold');
    same($evidence,$op->fresh()->attempt,'Release preserves prior claim and deferral evidence');
    $clock->instant=$clock->now()->modify('+260 seconds');$guard->preflightDelay=20;
    Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$op->account_fingerprint);
    $expired=$engine->execute($op->id);
    same('held',$expired->state,'Preflight exhausting the audited window holds before dispatch');
    same('namesilo_account_guard',$expired->safe_code,'Audited window exhaustion remains releasable');
    same($evidence,$expired->attempt,'Window exhaustion preserves original unsent evidence');
    same($before,$paidCalls(),'Expired release window sends no paid request');
    $clock->instant=$clock->now()->modify('+1 second');$release();
    same('queued',$op->fresh()->state,'A second ticketed release recovers exhausted preflight');
    Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$op->account_fingerprint);
    expect(in_array($engine->execute($op->id)->state,['pending','uncertain'],true),'Released acquisition freshly checks and dispatches');
    same($before+1,$paidCalls(),'Only delayed staff release permits the recovered account to send');
    same($originalStart,$op->fresh()->getRawOriginal('started_at'),'Delayed release preserves the original claim identity');
}

function withNameSiloGuardLimiter(int $allowed,Closure $run): mixed
{
    $runtime=Paymenter\Extensions\Servers\Domains\Services\Runtime::class;
    $contract=Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class;
    expect(!app()->bound($runtime)&&!app()->bound($contract),'Guard fixture starts without runtime limiter bindings');
    $limiter=new class($allowed) implements Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter {
        public int $calls=0;
        public function __construct(public int $remaining){}
        public function acquire(Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin $origin): string
        {
            $this->calls++;if($this->remaining--<=0){throw new Paymenter\Extensions\Servers\Domains\Services\ReadDeferred('Synthetic local capacity refusal');}
            return 'synthetic-lease';
        }
        public function release(Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding,Paymenter\Extensions\Servers\Domains\Data\RequestOrigin $origin,string $token): void {}
    };
    app()->instance($runtime,new $runtime);app()->instance($contract,$limiter);
    try{return $run($limiter);}finally{app()->offsetUnset($runtime);app()->offsetUnset($contract);}
}
function assertNameSiloLimitedAcquisition($op,$engine,$clock,object $guard,Closure $paidCalls,$claims=null): void
{
    if($claims){
        $claim=$claims->claim($op->id);expect($claim!==null,'Funded acquisition obtains an unsealed claim before limiter refusal');
        same('queued',$claims->defer($claim[0])->state,'Earlier capacity deferral retains unsent evidence');
    }
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    $before=$op->fresh()->getRawOriginal();$reads=$guard->reads;$paid=$paidCalls();
    $settings=App\Models\Server::findOrFail($op->server_id)->settings();
    $pin=$settings->where('key','domains_namesilo_default_contact_fingerprint')->first()?->getRawOriginal();
    withNameSiloGuardLimiter(0,static function($limiter)use($op,$engine,$clock,$guard,$paidCalls,$before,$reads,$paid,$pin):void {
        $deferred=$engine->execute($op->id);
        same(1,$limiter->calls,'Limiter rejects the preclaim default contact read');
        same($before,$deferred->getRawOriginal(),'Acquisition limiter refusal returns the freshly loaded unchanged operation');
        same($reads,$guard->reads,'Limiter refusal sends no default contact exchange');same($paid,$paidCalls(),'Limiter refusal sends no paid request');
        expect(!isset($deferred->attempt['dispatch_attempted_at']),'Limiter refusal creates no dispatch seal');
        same($pin,App\Models\Server::findOrFail($op->server_id)->settings()->where('key','domains_namesilo_default_contact_fingerprint')->first()?->getRawOriginal(),'Limiter refusal creates or changes no fingerprint');
        $limiter->remaining=100;$clock->instant=$clock->now()->modify('+26 seconds');
        Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
        expect(in_array($engine->execute($op->id)->state,['pending','uncertain'],true),'Capacity recovery retries acquisition without staff release');
        same($reads+1,$guard->reads,'Capacity recovery freshly checks default contact');same($paid+1,$paidCalls(),'Recovered acquisition sends once');
    });
}
