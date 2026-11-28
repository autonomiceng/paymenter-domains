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
    // Refuse inline purchase evidence and restore the real virgin operation for recovery.
    $heldRaw=$late->fresh()->getRawOriginal();
    foreach([['seal'=>'absent','send_state'=>'definitely_unsent'],['seal'=>'absent','send_state'=>'possibly_sent'],['seal'=>'sealed','send_state'=>'sent'],['execution'=>null]]as$attempt){
        Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$late->id)->update($heldRaw);
        $late->fresh()->update(['attempt'=>$attempt]);$heldBefore=$late->fresh()->getRawOriginal();
        foreach(['show','code']as$page){
            $html=$controller->$page($request($user),$late->domain_id)->render();
            $dom=storefrontDom($html);
            same(0,$dom->query('//a[@href="'.route('domains.domains.setup',$late->domain_id).'"]')->length,'Inline, unknown or sent attempt offers no setup link');
            same(0,$dom->query('//form[@action="'.route('domains.domains.contacts-save',$late->domain_id).'"]')->length,'Inline, unknown or sent attempt offers no recovery form');
        }
        $session->forget('errors');$r=$request($user);$r->setMethod('POST');
        $r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
        $r->request->set('contact',\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect($user)['registrant']);$actions->contacts($r,$late->domain_id);
        expect($session->get('errors')?->getBag('default')->has('owner_action')===true,'Inline, unknown or sent attempt refuses owner POST');
        same($heldBefore,$late->fresh()->getRawOriginal(),'Refused recovery preserves unknown or sent attempt');
    }
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$late->id)->update($heldRaw);
    $clock->instant=$now->modify('+30 days');Carbon\Carbon::setTestNow($clock->instant);Carbon\CarbonImmutable::setTestNow($clock->instant);
    foreach(['show','setup','code']as$page){
        $html=$controller->$page($request($user),$late->domain_id)->render();
        same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Late contact page never reopens code entry');
        expect(storefrontDom($html)->query($page==='show'?'//a[@href="'.route('domains.domains.setup',$late->domain_id).'"]':'//form[@action="'.route('domains.domains.contacts-save',$late->domain_id).'"]')->length>0,'Late contact fix keeps its setup link or recovery form');
    }
    $html=$controller->code($request($user),$missing->domain_id)->render();
    expect(!str_contains($html,'Save details and continue'),'No late correction without saved code');
    expect(str_contains($html,'The time to enter or change this auth code has passed.'),'Missing-code cutoff guidance');
    $r=$request($user);$r->setMethod('POST');$r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
    $before=[$state->calls,$state->reads,$late->fresh()->payload['epp'],$late->fresh()->policy['paid_at']];
    $r->request->set('contact',\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect($user)['registrant']);$response=$actions->contacts($r,$late->domain_id);same(302,$response->getStatusCode(),'Real owner recovery POST redirects');
    same('held',$late->fresh()->state,'Late contact correction remains held');
    same('transfer_setup_review_required',$late->fresh()->safe_code,'POST commits a staff-review hold');
    same(1,count($late->fresh()->policy['contact_readmissions']),'POST has one audited readmission');
    Illuminate\Support\Facades\Auth::setUser($lateViewer);
    expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant('release',$late->fresh()),'Late corrected transfer offers no impossible staff release');
    expect(Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant('refund',$late->fresh()),'Late corrected transfer retains explicit credit review');
    same('refund',Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($late->fresh())['action']??null,'Cutoff notice offers the refund action');
    foreach(['succeeded','pending','sent-hold','explicit-unsent','execution-evidence','started']as$otherState){
        $otherOp=clone$late->fresh();$otherOp->state=in_array($otherState,['sent-hold','explicit-unsent','execution-evidence','started'],true)?'held':$otherState;
        if($otherState==='explicit-unsent'){$otherOp->attempt=['seal'=>'absent','send_state'=>'definitely_unsent'];}
        elseif($otherState==='execution-evidence'){$otherOp->cost=[...$otherOp->cost,'execution'=>null];}
        elseif($otherState==='started'){$otherOp->started_at=now();}
        elseif($otherState!=='succeeded'){$otherOp->attempt=['seal'=>'sealed','send_state'=>'sent'];}
        expect(!isset(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::for($otherOp)['cutoff'])&&(Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::notice($otherOp)['action']??null)!=='refund','Sent and completed transfers never get cutoff-credit guidance');
    }
    Illuminate\Support\Facades\Auth::setUser($user);
    same($before,[$state->calls,$state->reads,$late->fresh()->payload['epp'],$late->fresh()->policy['paid_at']],'Owner POST preserves code/payment and performs no dispatch/read');
    $html=$controller->code($request($user),$late->domain_id)->render();
    expect(str_contains($html,'Your updated details are awaiting review by our team.'),'Real presenter explains staff review');
    same(0,storefrontDom($html)->query('//input[@name="transfer_code"]')->length,'Review hold keeps code entry closed');
    expect(!str_contains($html,'Save details and continue'),'Review hold offers no repeated contact readmission');
}
$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);
