<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Actual owner controller and committed late readmission.
declare(strict_types=1);
require_once __DIR__.'/staff-panel.php';
$lateViewer=staffUser([Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$actions=new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController($owners,
    new Paymenter\Extensions\Servers\Domains\Services\OwnerSetup($clock,$ports),$inputs,
    new Paymenter\Extensions\Servers\Domains\Services\DomainManagement($bindings,$clock),$ports);
foreach([0,1]as$i){
    $clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
    $user->properties()->where('key','phone')->update(['value'=>'']);$user->unsetRelation('properties');
    $late=$newTransfer('late-page-'.$i.'.com',$i);$missing=$newTransfer('late-missing-'.$i.'.com',$i,true,false);
    Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::hold($late->id);
    Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::hold($missing->id);
    $user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);$user->unsetRelation('properties');
    $clock->instant=$now->modify('+30 days');Carbon\Carbon::setTestNow($clock->instant);Carbon\CarbonImmutable::setTestNow($clock->instant);
    foreach(['show','setup','code']as$page){
        $html=$controller->$page($request($user),$late->domain_id)->render();
        same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Late contact page never reopens code entry');
        expect(str_contains($html,$page==='show'?'Update details for review':'Use updated account details'),'Late contact fix remains available');
        expect(!str_contains($html,'before we continue'),'Late overview makes no continuation promise');
    }
    $html=$controller->code($request($user),$missing->domain_id)->render();
    expect(!str_contains($html,'Use updated account details'),'No late correction without saved code');
    expect(str_contains($html,'The time to enter or change this transfer code has passed.'),'Missing-code cutoff guidance');
    $r=$request($user);$r->setMethod('POST');$r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
    $before=[$state->calls,$state->reads,$late->fresh()->payload['epp'],$late->fresh()->policy['paid_at']];
    $response=$actions->contacts($r,$late->domain_id);same(302,$response->getStatusCode(),'Real owner recovery POST redirects');
    same('transfer_setup_review_required',$late->fresh()->safe_code,'POST commits a staff-review hold');
    same(1,count($late->fresh()->policy['contact_readmissions']),'POST has one audited readmission');
    Illuminate\Support\Facades\Auth::setUser($lateViewer);
    expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant('release',$late->fresh()),'Late corrected transfer offers no impossible staff release');
    expect(Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant('refund',$late->fresh()),'Late corrected transfer retains explicit credit review');
    expect(str_contains(implode(' ',Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::review($late->fresh())),'Code deadline passed. Release is unavailable'),'Staff review explains cutoff and credit review');
    foreach(['succeeded','pending','sent-hold','explicit-unsent','execution-evidence','started']as$otherState){
        $otherOp=clone$late->fresh();$otherOp->state=in_array($otherState,['sent-hold','explicit-unsent','execution-evidence','started'],true)?'held':$otherState;
        if($otherState==='explicit-unsent'){$otherOp->attempt=['seal'=>'absent','send_state'=>'definitely_unsent'];}
        elseif($otherState==='execution-evidence'){$otherOp->cost=[...$otherOp->cost,'execution'=>null];}
        elseif($otherState==='started'){$otherOp->started_at=now();}
        elseif($otherState!=='succeeded'){$otherOp->attempt=['seal'=>'sealed','send_state'=>'sent'];}
        expect(!str_contains(implode(' ',Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::review($otherOp)),'Code deadline passed.'),'Sent and completed transfers never get cutoff-credit guidance');
    }
    Illuminate\Support\Facades\Auth::setUser($user);
    same($before,[$state->calls,$state->reads,$late->fresh()->payload['epp'],$late->fresh()->policy['paid_at']],'Owner POST preserves code/payment and performs no dispatch/read');
    $html=$controller->code($request($user),$late->domain_id)->render();
    expect(str_contains($html,'Your updated details are awaiting review by our team.'),'Real presenter explains staff review');
    same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Review hold keeps code entry closed');
    expect(!str_contains($html,'Use updated account details'),'Review hold offers no repeated contact readmission');
}
$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
