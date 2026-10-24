<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;

// Genuine current states exercise writer prerequisites independently of reason substitution.
$mismatches=[];$reading=false;
$all=[...$all,Staff::COSTS,Staff::METADATA];$staff->role->update(['permissions'=>$all]);
$deployment=[];foreach(['domains.enom_identity.environment','domains.enom_catalogue.enabled','domains.enom_writes.registration','domains.enom_writes.transfer'] as $key){$deployment[$key]=config($key);}
DB::listen(static function($query)use(&$reading):void{
    if($reading){expect(DB::transactionLevel()===0&&preg_match('/^\s*select\b/i',$query->sql)&&!str_contains(strtolower($query->sql),'for update'),'Offer loads read-only facts without locks');}
});
foreach([defined('STAFF_RELEASE_KIND')?STAFF_RELEASE_KIND:'register'] as $kind){
    foreach(['ready','unpaid','registry','contacts','cancel','status','mixed-invoice','retained-cap-enabled','retained-cap-disabled','contact-recovery','contact-peer','contact-owner','contact-limit','contact-authority','contact-cutoff','permission','complimentary','complimentary-permission','missing-code','invalid-code','missing-paid-at','invalid-paid-at','expired-deadline'] as $fault){
        $clock->instant=$now;config($deployment);
        if(str_starts_with($fault,'retained-cap')&&$kind==='renew'){continue;}
        if(str_starts_with($fault,'complimentary')&&$kind!=='renew'){continue;}
        if(in_array($fault,['missing-code','invalid-code','missing-paid-at','invalid-paid-at','expired-deadline'],true)&&$kind!=='transfer'){continue;}
        if($fault==='contact-cutoff'&&$kind!=='transfer'){continue;}
        if($kind==='renew'&&(str_starts_with($fault,'contact-')||in_array($fault,['registry','contacts','mixed-invoice','retained-cap-enabled','retained-cap-disabled'],true))){continue;}
        $name='staff-state-'.$kind.'-'.$fault.'-placeholder.com';
        if($fault==='contacts'||str_starts_with($fault,'contact-')){$user->properties()->where('key','phone')->update(['value'=>'']);}
        if($kind==='renew'){[$row,$domain]=$makeRenewal($name,paid:$fault!=='unpaid'&&!str_starts_with($fault,'complimentary'));
            if(str_starts_with($fault,'complimentary')){$cycles->retireUnpaid($row->id);$row=$cycles->complimentary($domain->id,$staff,1,new \Paymenter\Extensions\Servers\Domains\Data\Money('8.95','USD'),'reviewed_gift');}}
        else{$row=$new($name,$fault!=='unpaid',true,$kind);}
        if($fault==='unpaid'&&$kind==='register'){same('awaiting_payment',$row->state,'Genuine unpaid registration before staff hold');}
        if($fault!=='contact-recovery'){$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);}
        if($fault==='registry'){
            \Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$row->update(['payload'=>array_replace($row->payload,['registry_schema'=>[['name'=>'unaccepted']],'registry_supported'=>false])]));
            \Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::hold($row->id);
            same('registry_details_unsupported',$row->fresh()->safe_code,'Genuine unsupported registry hold');
        }elseif(str_starts_with($fault,'retained-cap')){
            $claim=$claims->claim($row->id);expect($claim!==null,'Real unsealed claim for cap refusal');
            $claims->holdTestCallCap($claim[0]);expect(\Paymenter\Extensions\Servers\Domains\Services\OperationClaims::testCallRefusalRetryable($row->fresh()),'Actual retained cap refusal proof');
        }elseif(str_starts_with($fault,'contact-')){\Paymenter\Extensions\Servers\Domains\Services\ContactRecovery::hold($row->id);}
        else{$run($row,'hold');}
        Auth::setUser($staff);$financialKeys=array_flip(['invoice','invoice_sub','refund','refund_sub','refund_fix','cost','cost_sub']);
        $financialFacts=array_intersect_key(RequestFacts::for($row->fresh()),$financialKeys);
        if(str_starts_with($fault,'contact-')){expect(isset($financialFacts['invoice'],$financialFacts['cost_sub'])&&array_key_exists('cost',$financialFacts)&&!isset($financialFacts['refund']),'Contact hold retains invoice and cost facts; no refund is recorded');}
        if($fault==='contact-peer'){\Paymenter\Extensions\Servers\Domains\Models\Operation::create([
            'domain_id'=>$row->domain_id,'service_id'=>$row->service_id,'server_id'=>$row->server_id,'registrar_key'=>$row->registrar_key,
            'account_fingerprint'=>$row->account_fingerprint,'authority_generation'=>$row->authority_generation,'action'=>'contacts',
            'term'=>1,'currency'=>'USD','amount'=>'0.00','funding_basis'=>'non_billable_management','state'=>'uncertain','operation_key'=>$row->operation_key.'-peer']);}
        if($fault==='contact-owner'){DB::table('services')->where('id',$row->service_id)->update(['user_id'=>$staff->id]);}
        if($fault==='contact-limit'){\Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$row->update(['policy'=>[...$row->policy,'contact_readmissions'=>array_fill(0,10,['at'=>$clock->now()->format(DATE_ATOM)])]]));}
        if($fault==='contact-authority'){DB::table('domains_domains')->where('id',$row->domain_id)->update(['billing_managed'=>false]);}
        if($fault==='contact-cutoff'){$clock->instant=\Paymenter\Extensions\Servers\Domains\Services\TransferInputs::deadline($row,$clock->now());$row->payload=[...$row->payload,'epp'=>null];DB::table('domains_operations')->where('id',$row->id)->update(['payload'=>$row->getAttributes()['payload']]);}
        if($fault==='cancel'){App\Models\ServiceCancellation::create(['service_id'=>$row->service_id,'type'=>'immediate','reason'=>'Customer cancels current service']);}
        if($fault==='status'){$row->domain->service->update(['status'=>'suspended']);}
        if($fault==='mixed-invoice'){App\Models\InvoiceItem::create(['invoice_id'=>$row->invoice_id,'price'=>'10.00','quantity'=>1,'description'=>'Unfunded unrelated line']);}
        if(in_array($fault,['missing-code','invalid-code','missing-paid-at','invalid-paid-at'],true)){
            // Retained legacy inputs can be incomplete even though current entry guards reject them.
            $attrs=[];
            if(str_ends_with($fault,'code')){$row->payload=[...$row->payload,'epp'=>$fault==='missing-code'?null:"\n"];$attrs['payload']=$row->getAttributes()['payload'];}
            else{$policy=$row->policy;if($fault==='missing-paid-at'){unset($policy['paid_at']);}else{$policy['paid_at']='invalid';}$row->policy=$policy;$attrs['policy']=$row->getAttributes()['policy'];}
            DB::table('domains_operations')->where('id',$row->id)->update($attrs);
        }
        if($fault==='expired-deadline'){$clock->instant=\Paymenter\Extensions\Servers\Domains\Services\TransferInputs::deadline($row,$clock->now());}
        Auth::setUser($staff);config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.transfer'=>true]);
        if($fault==='retained-cap-disabled'){config(['domains.enom_catalogue.enabled'=>false,'domains.enom_writes.registration'=>false,'domains.enom_writes.transfer'=>false]);}
        if(in_array($fault,['permission','complimentary-permission'],true)){$staff->role->update(['permissions'=>array_values(array_diff($all,[$fault==='permission'?'admin.domains.manage':'admin.domains.fund']))]);}
        $row=$row->fresh();$before=$row->getRawOriginal();$reading=true;
        $visible=OperationActions::relevant('release',$row);$notice=RequestFacts::notice($row);$reading=false;
        $facts=RequestFacts::for($row);expect(!($facts['unreadable']??false),'Held request remains readable: '.$kind.':'.$fault);
        same($financialFacts,array_intersect_key($facts,$financialKeys),'Held request preserves financial facts: '.$kind.':'.$fault);
        same(Labels::requestStatus($row)['reason'],$facts['reason'],'Hold keeps its saved reason');
        if(str_starts_with($fault,'contact-')&&$fault!=='contact-recovery'){
            $cause=match($fault){'contact-peer'=>'Contact recovery unavailable.','contact-limit'=>'Contact recovery limit reached.','contact-cutoff'=>'Saved transfer inputs require review.',default=>'Holding native authority changed.'};
            same($cause,$notice['body']??null,'Notice names contact recovery refusal: '.$fault);
            Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertSee($cause)->assertSee($financialFacts['invoice'])->assertDontSee('Check the app encryption key.');
        }
        if($visible){Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionVisible('release');}
        $accepted=true;try{$run($row,'release');}catch(DomainException){$accepted=false;}
        if(!$accepted){same($before,$row->fresh()->getRawOriginal(),'Refusal preserves snapshot: '.$kind.':'.$fault);}
        same(in_array($fault,['ready','complimentary','retained-cap-enabled'],true),$accepted,'Actual writer result: '.$kind.':'.$fault);
        if($visible!==$accepted||(($notice['action']??null)==='release')!==$accepted){$mismatches[]=$kind.':'.$fault;}
        if($visible===$accepted&&!$accepted){Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release');}
        $staff->role->update(['permissions'=>$all]);$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);
    }
}
expect($mismatches===[],'Actual state offer/writer mismatch: '.implode(', ',$mismatches));

// Real writer calls establish page/writer parity across the known reason vocabulary.
$method=new ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::class,'reason');
$lines=file($method->getFileName());
$tokens=token_get_all("<?php\n".implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1)));
$codes=['future_hold',null];
foreach($tokens as $i=>$token){
    if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING||!preg_match("/^'([a-z][a-z0-9_]+)'$/D",$token[1],$m)){continue;}
    $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}
    if(is_array($tokens[$j]??null)&&$tokens[$j][0]===T_DOUBLE_ARROW){$codes[]=$m[1];}
}
$testOnly=['enom_test_setup_required','enom_test_transfer_setup_required','usd_balance_required','enom_test_transfer_usd_balance_required','pre_send_interrupted','seal_authority_invalid','claim_authority_invalid','nameservers_required','write_rate_limited','registry_details_unsupported'];
$offered=[];
foreach([defined('STAFF_RELEASE_KIND')?STAFF_RELEASE_KIND:'register'] as $kind){
    $clock->instant=$now;config($deployment);
    if($kind==='renew'){[$op]=$makeRenewal('staff-release-renewal-parity-placeholder.com');$op->update(['state'=>'held','safe_code'=>'staff_review_required']);}
    else{$op=$new('staff-release-'.$kind.'-parity-placeholder.com',true,true,$kind);$op->update(['state'=>'held','safe_code'=>'staff_review_required']);}
    Auth::setUser($staff);$original=$op->fresh()->getRawOriginal();
    $restore=static fn()=>DB::table('domains_operations')->where('id',$op->id)->update($original);
    foreach([false,true] as $enabled){
        config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>$enabled,
            'domains.enom_writes.registration'=>$enabled,'domains.enom_writes.transfer'=>$enabled,'domains.enom_writes.renewal'=>$enabled]);
        foreach(array_unique([...$codes,...array_map(fn($c)=>$c->value,RenewalCode::cases())],SORT_REGULAR) as $code){
            $restore();$row=$op->fresh();$row->update(['safe_code'=>$code]);$row=$row->fresh();
            $notice=RequestFacts::notice($row);$visible=OperationActions::relevant('release',$row);
            if(!$enabled&&in_array($code,$testOnly,true)){expect(!$visible,'Deployment-off hold has no release: '.$kind.':'.$code);}
            if($code==='enom_test_renewal_call_cap'){same($kind==='renew'&&$enabled,$visible,'Renewal cap recovery requires its own deployment: '.$kind);}
            if(in_array($code,['enom_test_call_cap','enom_test_transfer_call_cap'],true)){expect(!$visible,'Call cap without retained unsent refusal proof stays hidden: '.$kind);}
            same($visible,($notice['action']??null)==='release','Notice and page offer the same recovery: '.$kind.':'.($code??'null'));
            $accepted=true;try{$run($row,'release');}catch(DomainException){$accepted=false;}
            same($visible,$accepted,'Code offer equals actual writer: '.$kind.':'.($code??'null'));
            if(!$accepted){same($row->getRawOriginal(),$op->fresh()->getRawOriginal(),'Hidden refusal preserves frozen snapshot');continue;}
            $expected=$kind==='transfer'&&$code==='contact_details_required'?['held','transfer_setup_review_required']:['queued',null];
            same($expected,[$op->fresh()->state,$op->fresh()->safe_code],'Every offered recovery is accepted: '.$kind.':'.($code??'null'));
            $offered[$kind][$enabled?'enabled':'disabled'][]=$code;
        }
    }
    $ordinary=['staff_review_required','configuration_unavailable','processing','balance','write_disabled','write_acceptance_required',
        'funding','renewal_funding_or_authority','renewal_authority_invalid','transfer_setup_review_required'];
    if($kind!=='renew'){$ordinary[]='contact_details_required';}
    else{$ordinary=[...$ordinary,...array_map(fn($c)=>$c->value,[RenewalCode::State,RenewalCode::Cost,RenewalCode::Owner,RenewalCode::AutoRenew,RenewalCode::Term])];}
    foreach($ordinary as $required){expect(in_array($required,$offered[$kind]['disabled']??[],true),'Ordinary recovery coverage: '.$kind.':'.$required);}
    foreach($testOnly as $required){
        if($kind==='renew'){expect(!in_array($required,$offered[$kind]['enabled']??[],true),'Renewal cannot use acquisition recovery: '.$required);}
        else{expect(in_array($required,$offered[$kind]['enabled']??[],true),'Enabled recovery coverage: '.$kind.':'.$required);}
    }
    $restore();$row=$op->fresh();$row->update(['safe_code'=>'future_hold']);
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertDontSee('Release hold');
    commerceReject(fn()=>$run($row->fresh(),'release'));
}
same(0,$state->writes,'Local recovery never sends');
echo 'Staff release parity passed: '.json_encode($offered)."\n";
$nativeSuccessful=true;
