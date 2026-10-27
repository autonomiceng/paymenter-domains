<?php
// Reuses the genuine funded renewal and staff release; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$writer->guardAccount(new Paymenter\Extensions\Servers\Domains\Data\RequestContext($binding,
    Paymenter\Extensions\Servers\Domains\Data\RequestOrigin::Background,$clock->now()->modify('+25 seconds'),
    new Paymenter\Extensions\Servers\Domains\Data\RequestBudget(1),$clock));
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
assertNameSiloAccountRefusals($binding,$accountGuard,$runGuard,static function()use(&$writes){return $writes;});
$releaseGuard();Illuminate\Support\Facades\Cache::flush();$wire->commands=[];
echo "NameSilo account renewal refusal matrix and staff release passed.\n";
