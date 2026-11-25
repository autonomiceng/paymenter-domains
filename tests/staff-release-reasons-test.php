<?php
// Native staff release witnesses for the decision rows; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-release-fixture.php';
require __DIR__.'/Support/release-decision-rows.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Staff\Release\HeldRelease;

// staff-release-decision-test.php decides every code from releaseFixtureFacts(); here the real request supplies the same facts,
// then one code per path runs the notice, the page action and the writer.
$codes=releaseRowCodes();
$paths=[
    'register'=>[[true,'pre_send_interrupted'],[false,'pre_send_interrupted'],[true,'registrar_price_increased'],[false,'registrar_price_increased'],
        [true,'usd_balance_required'],[true,'enom_test_call_cap'],[false,'contact_details_required'],[true,'balance_retry_funding_changed']],
    'transfer'=>[[false,'staff_review_required'],[true,'contact_details_required'],[false,'registry_details_unsupported'],[true,'enom_test_transfer_setup_required']],
    'renew'=>[[true,'enom_test_renewal_call_cap'],[false,'enom_test_renewal_call_cap'],[false,RenewalCode::Term->value],[true,'pre_send_interrupted'],[false,'registrar_read_failed']],
];
$facts=0;$offered=[];
foreach($paths as $kind=>$samples){
    $clock->instant=$now;config($deployment);
    if($kind==='renew'){[$op]=$makeRenewal(placeholderComFixtureName('staff-release-renewal-parity-placeholder.com'));}
    else{$op=$new(placeholderComFixtureName('staff-release-'.$kind.'-parity-placeholder.com'),true,true,$kind);}
    $op->update(['state'=>'held','safe_code'=>'staff_review_required']);
    Auth::setUser($staff);$restore=nativeFixtureCheckpoint();
    foreach([true,false] as $enabled){
        config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>$enabled,
            'domains.enom_writes.registration'=>$enabled,'domains.enom_writes.transfer'=>$enabled,'domains.enom_writes.renewal'=>$enabled]);
        // Facts only: one holding read, each code on its own copy, nothing written.
        $restore();$held=$op->fresh();$l=HeldRelease::readFacts($held);
        foreach($codes as $code){
            $copy=clone $held;$copy->safe_code=$code;
            $native=get_object_vars(HeldRelease::facts($copy,$l));$expected=get_object_vars(releaseFixtureFacts($kind,$enabled,$code));
            same($expected,$native,'Native release facts match the decision row: '.$kind.':'.($enabled?'on':'off').':'.($code??'null').' '.json_encode(array_diff_assoc(array_map('json_encode',$native),array_map('json_encode',$expected))));
            $facts++;
        }
        foreach($samples as [$on,$code]){
            if($on!==$enabled){continue;}
            $label=$kind.':'.($enabled?'on':'off').':'.$code;
            $restore();$row=$op->fresh();$row->update(['safe_code'=>$code]);$row=$row->fresh();
            $notice=RequestFacts::notice($row);$visible=OperationActions::relevant('release',$row);
            $guidance($row,$notice,$visible);
            // A contact hold is released by copying the account details in, which the decision leaves to its own recovery.
            same(in_array($code,releaseOffered($kind,$enabled),true)||$code==='contact_details_required'&&$kind!=='renew',$visible,'Page offer matches the decision row: '.$label);
            same($visible,($notice['action']??null)==='release','Notice and page offer the same recovery: '.$label);
            if($visible&&($enabled||$code===RenewalCode::Term->value)){
                $release=collect(OperationActions::make($row,'release-parity'))->first(fn($action)=>$action->getName()==='release');
                expect(!$release->isDisabled(),'Offered release button is enabled: '.$label);
            }
            $accepted=true;try{$run($row,'release');}catch(DomainException){$accepted=false;}
            same($visible,$accepted,'Code offer equals actual writer: '.$label);
            if(!$accepted){same($row->getRawOriginal(),$op->fresh()->getRawOriginal(),'Hidden refusal preserves frozen snapshot');continue;}
            $expected=$kind==='transfer'&&$code==='contact_details_required'?['held','transfer_setup_review_required']:['queued',null];
            same($expected,[$op->fresh()->state,$op->fresh()->safe_code],'Every offered recovery is accepted: '.$label);
            $offered[]=$label;
        }
    }
    $restore();$row=$op->fresh();$row->update(['safe_code'=>'future_hold']);
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertDontSee('Release hold');
    commerceReject(fn()=>$run($row->fresh(),'release'));
}
same(count($codes)*6,$facts,'Every code was read natively for each kind and setting');
same(0,$state->writes,'Local recovery never sends');
echo 'Staff release witnesses passed: '.json_encode($offered)."\n";
$nativeSuccessful=true;
