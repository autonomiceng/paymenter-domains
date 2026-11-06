<?php
// Reuses the genuine funded renewal and staff release; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$writer->guardAccount(new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
    Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+25 seconds'),
    new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(0),$clock));
same(0,$accountGuard->reads,'Local enrollment needs no default-contact read or read budget');
$releaseGuard=static function()use($staff,$renewal,$clock,$user):void {
    $fresh=$renewal->fresh();if($fresh->state!=='held'){return;}
    Illuminate\Support\Facades\Auth::login($staff);
    $review=Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::class;
    $ticket=$review::ticket($staff->id,$fresh->domain_id,$fresh->id,'release',$review::stamp($fresh,$fresh->domain->fresh()),$clock);
    (new Paymenter\Extensions\Servers\Domains\Services\StaffOperations($clock))->run($staff,$fresh->domain_id,$fresh->id,'release',$ticket,'Review default contact configuration');
    Illuminate\Support\Facades\Auth::login($user);
};
$runGuard=static function($case)use($releaseGuard,$engine,$writer,$renewal,$accountGuard,&$writes){
    $releaseGuard();Illuminate\Support\Facades\Cache::flush();
    $result=$engine->run($renewal->id,$writer);
    if($result->state==='held'){
        $reads=$accountGuard->reads;$sent=$writes;
        same('held',$engine->run($renewal->id,$writer)->state,'Held account guard is not retried automatically');
        same([$reads,$sent],[$accountGuard->reads,$writes],'Held account refusal sends neither another read nor a paid request');
    }
    return $result;
};
// Legacy default-contact refusals and encrypted pins remain in namesilo-legacy-account-guard-test.
// This account is locally enrolled; corrupt each credential authority independently instead.
$server=App\Models\Server::findOrFail($binding->serverId);
foreach(['domains_credentials','domains_verified_credentials',Paymenter\Extensions\Servers\Domains\Services\LocalAccountEnrollment::KEY] as $key){
    $releaseGuard();$saved=$server->settings()->where('key',$key)->sole()->getRawOriginal();
    $financial=nativeFinancialSnapshot($renewal);$sent=$writes;$reads=$wire->calls;
    Illuminate\Support\Facades\DB::table('settings')->where('id',$saved['id'])->update(['value'=>'synthetic-invalid-ciphertext','encrypted'=>true]);
    try{
        same(false,$bindings->configurationAvailable($binding->serverId),'Corrupt local configuration is unavailable: '.$key);
        try{
            $writer->guardAccount(new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
                Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+25 seconds'),
                new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(0),$clock));
            expect(false,'Corrupt local configuration must refuse the account guard');
        }catch(Paymenter\Extensions\Servers\Domains\Services\WriteHeld $error){same('namesilo_account_guard',$error->reason,'Local configuration refuses through the typed account guard');}
        $held=$runGuard($key);
        same(['queued','renewal_acceptance_required'],[$held->state,$held->safe_code],'Renewal configuration refusal stays retryable before admission');
        expect(!isset($held->policy['renewal_admission']),'Unavailable local configuration creates no renewal admission');
        same($sent,$writes,'Local configuration refusal sends no paid request');same($reads,$wire->calls,'Local configuration refusal performs no provider reads');
        same(0,$accountGuard->reads,'Local configuration refusal never reads default contacts');
        expect(!isset($held->attempt['dispatch_attempted_at']),'Local configuration refusal creates no possible-send seal');
        same($financial,nativeFinancialSnapshot($renewal),'Local configuration refusal preserves native finance and holding state');
    }finally{Illuminate\Support\Facades\DB::table('settings')->where('id',$saved['id'])->update($saved);}
}
$releaseGuard();Illuminate\Support\Facades\Cache::flush();$wire->commands=[];
echo "NameSilo local configuration renewal refusals and staff release passed.\n";
