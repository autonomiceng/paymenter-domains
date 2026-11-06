<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth, Cache};
use Paymenter\Extensions\Servers\Domains\Services\{LocalAccountEnrollment, OperationClaims, StaffOperations, StaffOperationReview};

/** Installed invoices, native authority and staff release must exercise the actual acquisition engine. */
function assertLocalNameSiloAcquisition(Closure $make, $binding, $clock, object $guard, Closure $paidCalls, $claims): void
{
    $settings=App\Models\Server::findOrFail($binding->serverId)->settings();
    $enrollment=$settings->where('key',LocalAccountEnrollment::KEY)->sole();$saved=$enrollment->value;
    $release=static function($op)use($clock):void {
        require_once __DIR__.'/staff-panel.php';
        $actor=staffUser(['admin.domains.view','admin.domains.manage','admin.domains.operations.release']);
        $previous=Auth::user();Auth::login($actor);
        try{$fresh=$op->fresh();$ticket=StaffOperationReview::ticket($actor->id,$fresh->domain_id,$fresh->id,'release',StaffOperationReview::stamp($fresh,$fresh->domain->fresh()),$clock);
            (new StaffOperations($clock))->run($actor,$fresh->domain_id,$fresh->id,'release',$ticket,'Review local account configuration');
        }finally{$previous?Auth::login($previous):Auth::logout();}
    };
    foreach([false,true] as $deferred){
        [$op,$engine]=$make($deferred?'deferred':'virgin');
        if($deferred){$claim=$claims->claim($op->id);expect($claim!==null,'Funded acquisition obtains an unsealed claim');same('queued',$claims->defer($claim[0])->state,'Capacity deferral retains an unsent claim');}
        $evidence=$op->fresh()->attempt;$start=$op->fresh()->getRawOriginal('started_at');$finance=nativeFinancialSnapshot($op);$paid=$paidCalls();$reads=$guard->reads;
        $enrollment->value='{}';$enrollment->save();
        try{
            $held=$engine->execute($op->id);
            same(['held','namesilo_account_guard'],[$held->state,$held->safe_code],'Invalid local enrollment holds funded acquisition');
            same($evidence,$held->attempt,'Account refusal preserves all prior unsent evidence');
            same($finance,nativeFinancialSnapshot($held),'Account refusal preserves native finance and holding state');
            same([$reads,$paid],[$guard->reads,$paidCalls()],'Local account refusal sends neither a default-contact read nor a paid request');
        }finally{$enrollment->value=$saved;$enrollment->save();}
        same('held',$engine->execute($op->id)->state,'Restoring configuration still requires staff release');
        same($paid,$paidCalls(),'A restored account never retries a held paid request automatically');
        $clock->instant=$clock->now()->modify('+601 seconds');$release($op);same('queued',$op->fresh()->state,'Ticketed staff review releases the original unsent request');
        same($evidence,$op->fresh()->attempt,'Staff release preserves the original claim evidence');
        if($deferred){
            $clock->instant=$clock->now()->modify('+260 seconds');$guard->preflightDelay=20;Cache::forget('domains-write-slot:'.$binding->fingerprint);
            $expired=$engine->execute($op->id);same(['held','namesilo_account_guard'],[$expired->state,$expired->safe_code],'Preflight exhausting the audited release window is held');
            same($evidence,$expired->attempt,'Expired release window preserves original unsent evidence');same($paid,$paidCalls(),'Expired release window sends no paid request');
            $clock->instant=$clock->now()->modify('+1 second');$release($op);
        }
        Cache::forget('domains-write-slot:'.$binding->fingerprint);
        expect(in_array($engine->execute($op->id)->state,['pending','uncertain'],true),'Released acquisition checks current profile proof and sends');
        same($paid+1,$paidCalls(),'Only ticketed release permits one paid request');
        if($deferred){same($start,$op->fresh()->getRawOriginal('started_at'),'Delayed release preserves original claim identity');}
        same($reads,$guard->reads,'Local enrollment never establishes identity from a default contact');
    }
    [$op,$engine]=$make('limited');$finance=nativeFinancialSnapshot($op);$paid=$paidCalls();$reads=$guard->reads;
    Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
    withNameSiloGuardLimiter(0,static function($limiter)use($op,$engine,$clock,$guard,$paidCalls,$finance,$paid,$reads):void {
        $queued=$engine->execute($op->id);same(1,$limiter->calls,'The first required preflight read consumes capacity');
        same(['queued','write_rate_limited'],[$queued->state,$queued->safe_code],'Preflight capacity refusal remains retryable');
        expect(!isset($queued->attempt['dispatch_attempted_at']),'Capacity refusal creates no possible-send seal');
        same($finance,nativeFinancialSnapshot($queued),'Capacity refusal preserves native finance and holding state');
        same([$reads,$paid],[$guard->reads,$paidCalls()],'Capacity refusal sends no default-contact or paid request');
        $limiter->remaining=100;$clock->instant=$clock->now()->modify('+26 seconds');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
        Cache::forget('domains-write-slot:'.$op->account_fingerprint);
        expect(in_array($engine->execute($op->id)->state,['pending','uncertain'],true),'Capacity recovery retries without staff release');
        same($paid+1,$paidCalls(),'Recovered capacity sends once');same($reads,$guard->reads,'Recovery still makes no default-contact identity claim');
    });
    expect(!$settings->where('key','domains_namesilo_default_contact_fingerprint')->exists(),'Local enrollment never writes a default-contact identity pin');
}
