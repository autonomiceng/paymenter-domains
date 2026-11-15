<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits, HoldingLocks, ObligationAlerts, ReminderSchema};
ReminderSchema::install();
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class, $clock);
$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void {if(!$ok){$failures[]=$message;}};
foreach(['pending','other_hold','new_shortfall','unknown','unreadable','lost'] as $case){
    [$op,$d,$b]=$makeRenewal(placeholderComFixtureName('funds-warning-'.str_replace('_','-',$case).'-placeholder.com'));
    [$engine,$writer]=$compose($b);
    $account=Account::where('server_id',$op->server_id)->sole();
    $alert=fn()=>DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','renewal_funds');
    $open=fn()=>$alert()->whereNull('resolved_at')->exists();
    $clock->instant=new DateTimeImmutable('2027-10-15T12:00:00Z');$state->balance='0.00';
    same('queued',$engine->run($op->id,$writer)->state,'Shortfall waits before sending');
    expect($open(),'Shortfall raises the funds warning');
    $deadline=$op->fresh()->policy['funds_wait_until'];
    $clock->instant=$clock->now()->modify('+1 day');Cache::flush();
    if(in_array($case,['unknown','unreadable'],true)){
        $state->balance='?';$state->fail=$case==='unreadable'?ReadCommand::Balance:null;
        same('queued',$engine->run($op->id,$writer)->state,'Unknown balance cannot admit the renewal');
        FundsWaits::sweep($account,$clock->now());FundsWaits::sweep($account,$clock->now());
        $check($open(),$case.': unknown balance keeps the funds warning');
        same(1,$alert()->count(),'Repeated unknown-balance sweeps retain one warning');
        $state->fail=null;continue;
    }
    $state->balance='100.00';
    if(in_array($case,['other_hold','new_shortfall'],true)){$state->price='9.00';}
    if($case==='lost'){$state->lost=true;}
    $state->afterWrite=static function()use($open,$check,$case):void {
        $check(!$open(),$case.': funded admission clears before the wire');
    };
    $recovered=$engine->run($op->id,$writer);
    same(match($case){'other_hold','new_shortfall'=>'held','lost'=>'uncertain',default=>'pending'},$recovered->state,'Recovery keeps the actual execution outcome');
    $check(!$open(),$case.': funded recovery clears the funds warning');
    same($deadline,$op->fresh()->policy['funds_wait_until'],'Original funds deadline remains evidence');
    if(in_array($case,['pending','lost'],true)){
        DB::transaction(fn()=>ObligationAlerts::retained(HoldingLocks::take($d),$clock->now()->getTimestamp()));
        expect(DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','operation_review')->whereNull('resolved_at')->exists(),'Unconfirmed execution keeps its independent review');
        // Also repair a warning retained by an execution that predates the fix.
        DB::transaction(fn()=>ObligationAlerts::record($d,'renewal_funds',$clock->now()->getTimestamp(),(int)$op->id));
    }
    FundsWaits::sweep($account,$clock->now());
    $check(!$open(),$case.': sweep keeps funded work clear');
    $settled=$alert()->first();
    $clock->instant=$clock->now()->modify('+1 minute');FundsWaits::sweep($account,$clock->now());
    $check($settled==$alert()->first(),$case.': repeated sweep is idempotent');
    if($case==='new_shortfall'){
        $d->refresh()->update(['renewal_policy'=>[...$d->renewal_policy,'automatic_spend_paused'=>false]]);
        $op->refresh()->update(['state'=>'queued','safe_code'=>null]);$state->balance='0.00';Cache::flush();
        same('queued',$engine->run($op->id,$writer)->state,'New shortfall waits again');
        $check($open(),'New shortfall reopens the funds warning');
        same(1,$alert()->count(),'A new shortfall reuses the retained warning');
    }
}
expect(!$failures,implode("\n",$failures));
echo "Renewal funds recovery, uncertainty, repeated sweeps and new shortfalls passed\n";
$nativeSuccessful=true;
