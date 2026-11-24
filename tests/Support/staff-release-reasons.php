<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/staff-release-fixture.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;

$method=new ReflectionMethod(Labels::class,'reason');
$lines=file($method->getFileName());
$tokens=token_get_all("<?php\n".implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1)));
$codes=['future_hold',null,'name_unavailable','premium_name','order_not_supported'];
foreach($tokens as $i=>$token){
    if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING||!preg_match("/^'([a-z][a-z0-9_]+)'$/D",$token[1],$m)){continue;}
    $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}
    if(is_array($tokens[$j]??null)&&$tokens[$j][0]===T_DOUBLE_ARROW){$codes[]=$m[1];}
}
$testOnly=['enom_test_setup_required','enom_test_transfer_setup_required','pre_send_interrupted','seal_authority_invalid','claim_authority_invalid','nameservers_required','write_rate_limited','registry_details_unsupported'];
$offered=[];
$kind = STAFF_RELEASE_KIND;
$enabled = STAFF_RELEASE_ENABLED;
$clock->instant = $now;
config($deployment);
if ($kind === 'renew') {
    [$op] = $makeRenewal(placeholderComFixtureName('staff-release-renewal-parity-placeholder.com'));
} else {
    $op = $new(placeholderComFixtureName('staff-release-'.$kind.'-parity-placeholder.com'), true, true, $kind);
}
$op->update(['state' => 'held', 'safe_code' => 'staff_review_required']);
Auth::setUser($staff);
$restore=nativeFixtureCheckpoint();
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>$enabled,
    'domains.enom_writes.registration'=>$enabled,'domains.enom_writes.transfer'=>$enabled,'domains.enom_writes.renewal'=>$enabled]);
foreach(array_unique([...$codes,...array_map(fn($c)=>$c->value,RenewalCode::cases())],SORT_REGULAR) as $code){
    $restore();$row=$op->fresh();$row->update(['safe_code'=>$code]);$row=$row->fresh();
    $notice=RequestFacts::notice($row);$visible=OperationActions::relevant('release',$row);
    $guidance($row,$notice,$visible);
    if(!$enabled&&in_array($code,$testOnly,true)){expect(!$visible,'Deployment-off hold has no release: '.$kind.':'.$code);}
    if(in_array($code,['usd_balance_required','enom_test_transfer_usd_balance_required'],true)){expect(!$visible,'Stored balance checks use automatic recovery: '.$kind.':'.$code);}
    if($code==='enom_test_renewal_call_cap'){same($kind==='renew'&&$enabled,$visible,'Renewal cap recovery requires its own deployment: '.$kind);}
    if(in_array($code,['enom_test_call_cap','enom_test_transfer_call_cap'],true)){expect(!$visible,'Call cap without retained unsent refusal proof stays hidden: '.$kind);}
    same($visible,($notice['action']??null)==='release','Notice and page offer the same recovery: '.$kind.':'.($code??'null'));
    if($visible&&($enabled||$code===RenewalCode::Term->value)){
        $release=collect(OperationActions::make($row,'release-parity'))->first(fn($action)=>$action->getName()==='release');
        expect(!$release->isDisabled(),'Offered release button is enabled: '.$kind.':'.($code??'null'));
    }
    $accepted=true;try{$run($row,'release');}catch(DomainException){$accepted=false;}
    same($visible,$accepted,'Code offer equals actual writer: '.$kind.':'.($code??'null'));
    if(!$accepted){same($row->getRawOriginal(),$op->fresh()->getRawOriginal(),'Hidden refusal preserves frozen snapshot');continue;}
    $expected=$kind==='transfer'&&$code==='contact_details_required'?['held','transfer_setup_review_required']:['queued',null];
    same($expected,[$op->fresh()->state,$op->fresh()->safe_code],'Every offered recovery is accepted: '.$kind.':'.($code??'null'));
    $offered[] = $code;
}
$ordinary=['staff_review_required','configuration_unavailable','processing','balance','write_disabled','write_acceptance_required',
    'funding','renewal_funding_or_authority','renewal_invoice_unpaid','renewal_spend_paused','renewal_authority_invalid','transfer_setup_review_required'];
if($kind!=='renew'){$ordinary[]='contact_details_required';}
else{$ordinary=[...$ordinary,...array_map(fn($c)=>$c->value,[RenewalCode::State,RenewalCode::Cost,RenewalCode::Owner,RenewalCode::AutoRenew,RenewalCode::Term])];}
if ($enabled) {
    foreach ($testOnly as $required) {
        if ($kind === 'renew') {
            expect(!in_array($required, $offered, true), 'Renewal cannot use acquisition recovery: '.$required);
        } else {
            expect(in_array($required, $offered, true), 'Enabled recovery coverage: '.$required);
        }
    }
} else {
    foreach ($ordinary as $required) {
        expect(in_array($required, $offered, true), 'Ordinary recovery coverage: '.$kind.':'.$required);
    }
}

$restore();
$row = $op->fresh();
$row->update(['safe_code'=>'future_hold']);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertDontSee('Release hold');
commerceReject(fn()=>$run($row->fresh(),'release'));
same(0,$state->writes,'Local recovery never sends');
echo 'Staff release parity passed: '.json_encode([$kind => [$enabled ? 'enabled' : 'disabled' => $offered]])."\n";
$nativeSuccessful=true;
