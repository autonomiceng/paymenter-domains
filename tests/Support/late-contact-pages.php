<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Actual owner controller and committed late readmission.
declare(strict_types=1);
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/reserved-fixtures.php';
$createTransfer = $newTransfer;
$newTransfer = static function (string $name, int $i = 0, bool $paid = true, bool $code = true) use ($createTransfer) {
    return $createTransfer(placeholderComFixtureName($name), $i, $paid, $code);
};
$lateViewer=staffUser([Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$actions=new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController($owners,
    new Paymenter\Extensions\Servers\Domains\Services\OwnerSetup($clock,$ports),$inputs,
    new Paymenter\Extensions\Servers\Domains\Services\DomainManagement($bindings,$clock),$ports);
foreach([0,1]as$i){
    $clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
    $user->properties()->where('key','phone')->update(['value'=>'']);$user->unsetRelation('properties');
    $late=$newTransfer('late-page-placeholder-'.$i.'.com',$i);$missing=$newTransfer('late-missing-placeholder-'.$i.'.com',$i,true,false);
    Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::hold($late->id);
    Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::hold($missing->id);
    $user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);$user->unsetRelation('properties');
    // Test uncertain evidence before restoring the explicit unsent attempt for recovery.
    $unsent=['seal'=>'absent','send_state'=>'definitely_unsent'];$heldRaw=$late->fresh()->getRawOriginal();
    foreach([['seal'=>'absent','send_state'=>'possibly_sent'],['seal'=>'sealed','send_state'=>'sent'],['execution'=>null]]as$attempt){
        Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$late->id)->update($heldRaw);
        $late->fresh()->update(['attempt'=>$attempt]);$heldBefore=$late->fresh()->getRawOriginal();
        foreach(['show','code']as$page){
            $html=$controller->$page($request($user),$late->domain_id)->render();
            expect(!str_contains($html,'Use updated account details')&&!str_contains($html,'Update your details so we can finish the transfer.'),'Unknown or sent attempt hides contact recovery');
        }
        $session->forget('errors');$r=$request($user);$r->setMethod('POST');
        $r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
        $actions->contacts($r,$late->domain_id);
        expect($session->get('errors')?->getBag('default')->has('owner_action')===true,'Unknown or sent attempt refuses owner POST');
        same($heldBefore,$late->fresh()->getRawOriginal(),'Refused recovery preserves unknown or sent attempt');
    }
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$late->id)->update($heldRaw);
    $late->fresh()->update(['attempt'=>$unsent]);
    $clock->instant=$now->modify('+30 days');Carbon\Carbon::setTestNow($clock->instant);Carbon\CarbonImmutable::setTestNow($clock->instant);
    foreach(['show','setup','code']as$page){
        $html=$controller->$page($request($user),$late->domain_id)->render();
        same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Late contact page never reopens code entry');
        expect(str_contains($html,$page==='show'?'Update your details so we can finish the transfer.':'Use updated account details'),'Late contact fix remains available');
        expect(!str_contains($html,'before we continue'),'Late overview makes no continuation promise');
    }
    $r=$request($user);$r->setMethod('POST');$r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
    $session->forget('errors');$actions->contacts($r,$late->domain_id);
    same(null,$session->get('errors'),'Proven-unsent recovery POST succeeds');
    same('transfer_setup_review_required',$late->fresh()->safe_code,'Proven-unsent recovery retains staff review');
    same(1,count($late->fresh()->policy['contact_readmissions']),'Proven-unsent recovery records one audit');
    same($unsent,$late->fresh()->attempt,'Recovery preserves explicit proven-unsent evidence');
    // Restore the original no-attempt case for its existing cutoff and staff checks.
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$late->id)->update($heldRaw);
    $html=$controller->code($request($user),$missing->domain_id)->render();
    expect(!str_contains($html,'Use updated account details'),'No late correction without saved code');
    expect(str_contains($html,'The time to enter or change this transfer code has passed.'),'Missing-code cutoff guidance');
    $r=$request($user);$r->setMethod('POST');$r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
    $before=[$state->calls,$state->reads,$late->fresh()->payload['epp'],$late->fresh()->policy['paid_at']];
    $response=$actions->contacts($r,$late->domain_id);same(302,$response->getStatusCode(),'Real owner recovery POST redirects');
    same('held',$late->fresh()->state,'Late contact correction remains held');
    same('transfer_setup_review_required',$late->fresh()->safe_code,'POST commits a staff-review hold');
    same(1,count($late->fresh()->policy['contact_readmissions']),'POST has one audited readmission');
    Illuminate\Support\Facades\Auth::setUser($lateViewer);
    expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant('release',$late->fresh()),'Late corrected transfer offers no impossible staff release');
    expect(Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant('refund',$late->fresh()),'Late corrected transfer retains explicit credit review');
    same(['Auth code deadline passed.','danger','refund'],array_values(array_intersect_key(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($late->fresh())??[],['title'=>1,'tone'=>1,'action'=>1])),'Staff notice explains cutoff and offers Cancel and refund');
    foreach(['succeeded','pending','sent-hold','explicit-unsent','execution-evidence','started']as$otherState){
        $otherOp=clone$late->fresh();$otherOp->state=in_array($otherState,['sent-hold','explicit-unsent','execution-evidence','started'],true)?'held':$otherState;
        if($otherState==='explicit-unsent'){$otherOp->attempt=['seal'=>'absent','send_state'=>'definitely_unsent'];}
        elseif($otherState==='execution-evidence'){$otherOp->cost=[...$otherOp->cost,'execution'=>null];}
        elseif($otherState==='started'){$otherOp->started_at=now();}
        elseif($otherState!=='succeeded'){$otherOp->attempt=['seal'=>'sealed','send_state'=>'sent'];}
        expect(!isset(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($otherOp)['cutoff'])&&(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($otherOp)['title']??null)!=='Auth code deadline passed.','Sent and completed transfers never get cutoff-credit guidance');
    }
    Illuminate\Support\Facades\Auth::setUser($user);
    same($before,[$state->calls,$state->reads,$late->fresh()->payload['epp'],$late->fresh()->policy['paid_at']],'Owner POST preserves code/payment and performs no dispatch/read');
    $html=$controller->code($request($user),$late->domain_id)->render();
    expect(str_contains($html,'Your updated details are awaiting review by our team.'),'Real presenter explains staff review');
    same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Review hold keeps code entry closed');
    expect(!str_contains($html,'Use updated account details'),'Review hold offers no repeated contact readmission');
}
$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
