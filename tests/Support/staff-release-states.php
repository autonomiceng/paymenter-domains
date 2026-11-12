<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__ . "/staff-release-fixture.php";
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels,OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;


$reset=nativeFixtureCheckpoint();
foreach([defined('STAFF_RELEASE_KIND')?STAFF_RELEASE_KIND:'register'] as $kind){
    foreach(['ready','unpaid','registry','contacts','cancel','status','mixed-invoice','retained-cap-enabled','retained-cap-disabled','contact-recovery','contact-peer','contact-owner','contact-limit','contact-authority','contact-cutoff','permission','complimentary','complimentary-permission','missing-code','invalid-code','missing-paid-at','invalid-paid-at','expired-deadline'] as $fault){
        $group=match(true){
            in_array($fault,['ready','unpaid','complimentary','complimentary-permission'],true)=>'funding',
            str_starts_with($fault,'contact')||$fault==='registry'=>'contacts',
            str_starts_with($fault,'retained-cap')||in_array($fault,['missing-code','invalid-code','missing-paid-at','invalid-paid-at','expired-deadline'],true)=>'inputs',
            default=>'order',
        };
        if($group!==STAFF_RELEASE_GROUP){continue;}
        $reset();
        $clock->instant=$now;config($deployment);
        if(str_starts_with($fault,'retained-cap')&&$kind==='renew'){continue;}
        if(str_starts_with($fault,'complimentary')&&$kind!=='renew'){continue;}
        if(in_array($fault,['missing-code','invalid-code','missing-paid-at','invalid-paid-at','expired-deadline'],true)&&$kind!=='transfer'){continue;}
        if($fault==='contact-cutoff'&&$kind!=='transfer'){continue;}
        if($kind==='renew'&&(str_starts_with($fault,'contact-')||in_array($fault,['registry','contacts','mixed-invoice','retained-cap-enabled','retained-cap-disabled'],true))){continue;}
        $name=placeholderComFixtureName('staff-state-'.$kind.'-'.$fault.'-placeholder.com');
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
        $guidance($row,$notice,$visible);
        $facts=RequestFacts::for($row);expect(!($facts['unreadable']??false),'Held request remains readable: '.$kind.':'.$fault);
        same($financialFacts,array_intersect_key($facts,$financialKeys),'Held request preserves financial facts: '.$kind.':'.$fault);
        same(Labels::requestStatus($row)['reason'],$facts['reason'],'Hold keeps its saved reason');
        if(str_starts_with($fault,'contact-')&&$fault!=='contact-recovery'){
            $cause=match($fault){'contact-peer'=>"The customer can't update details on this request. Contact them.",'contact-limit'=>'The customer tried too many times. Contact them.','contact-cutoff'=>'Saved transfer details need a check. Open Details below.',default=>'The order or product changed after purchase. Check the order.'};
            same($cause,$notice['body']??null,'Notice names contact recovery refusal: '.$fault);
            Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertSee($cause)->assertSee($financialFacts['invoice'])->assertDontSee('Ask your site admin to check the encryption key.');
        }
        if($visible){Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionVisible('release')->assertActionEnabled('release');}
        $accepted=true;try{$run($row,'release');}catch(DomainException){$accepted=false;}
        if(!$accepted){same($before,$row->fresh()->getRawOriginal(),'Refusal preserves snapshot: '.$kind.':'.$fault);}
        same(in_array($fault,['ready','complimentary','retained-cap-enabled'],true),$accepted,'Actual writer result: '.$kind.':'.$fault);
        if($visible!==$accepted||(($notice['action']??null)==='release')!==$accepted){$mismatches[]=$kind.':'.$fault;}
        if($visible===$accepted&&!$accepted){Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release');}
        $staff->role->update(['permissions'=>$all]);$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);
    }
}
expect($mismatches===[],'Actual state offer/writer mismatch: '.implode(', ',$mismatches));

same(0,$state->writes,'Local recovery never sends');
$nativeSuccessful=true;
