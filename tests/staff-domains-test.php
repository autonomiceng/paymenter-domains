<?php
// Native Filament permission/filter/leak regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/staff-filter-lookalikes.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Labels, RefundCandidates, RequestFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Policies\DomainPolicy;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
staffPanel(); [$held,$active,$long]=staffData();
function staffFilter($page, string $name, mixed $value) {
    // Filament 4 defers filters: exercise the same Apply action as its native form.
    $page->filterTable($name,$value);
    return $page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters');
}
$permissions=[Staff::VIEW,Staff::COSTS,Staff::METADATA,Staff::POLICY]; $policy=new DomainPolicy;
foreach (range(0,15) as $mask) {
    $granted=array_values(array_filter($permissions,fn($p,$i)=>(bool)($mask & (1<<$i)),ARRAY_FILTER_USE_BOTH));
    $user=staffUser($granted); Auth::setUser($user);
    $view=(bool)($mask&1); $cost=$view&&(bool)($mask&2); $metadata=$view&&(bool)($mask&4);
    same($view,$policy->viewAny($user),'Default-deny view matrix');
    same($view,$policy->view($user,$held),'Explicit record view matrix');
    same($cost,$policy->viewCosts($user,$held),'Independent costs matrix');
    same($metadata,$policy->viewSecretsMetadata($user,$held),'Independent metadata matrix');
    same($view&&(bool)($mask&8),$policy->managePolicy($user,$held),'Independent policy permission');
    expect(!$policy->update($user,$held)&&!$policy->create($user)&&!$policy->delete($user,$held),'No staff writes');
    $page=Livewire::actingAs($user)->test(ListDomains::class);
    if (!$view) { $page->assertForbidden(); continue; }
    $page->assertCanSeeTableRecords([$held,$active,$long]);
    $page=Livewire::actingAs($user)->test(ViewDomain::class,['record'=>$held->id]);
    $page->assertSuccessful()->assertSee('Registration')->assertSee('Billing')->assertSet('data',[]);
    $activity=Livewire::actingAs($user)->test(RequestsRelationManager::class,['ownerRecord'=>$held,'pageClass'=>ViewDomain::class]);
    $activity->assertCanSeeTableRecords([$held->operations()->sole()]);
    $body=$page->html().json_encode($page->snapshot).$activity->html().json_encode($activity->snapshot);
    expect(!str_contains($body,'PRIVATE-')&&!str_contains($body,'private-operation-'),'No secret/raw evidence in HTML or Livewire');
    expect(!str_contains($body,'827.43')&&!str_contains($body,'839.47')&&!str_contains($body,'Transfer code'),'Costs and metadata stay on the request page');
    // The request page keeps the independent cost and metadata permissions.
    $request=Livewire::actingAs($user)->test(ViewOperation::class,['record'=>$held->operations()->sole()->id]);
    $detail=$request->html().json_encode($request->snapshot);
    expect(!str_contains($detail,'PRIVATE-')&&!str_contains($detail,'private-operation-'),'No secret/raw evidence on the request page');
    same($cost,str_contains($request->html(),'827.43'),'Cost HTML independent permission');
    same($cost,str_contains($request->html(),'839.47'),'Actual nested charge permission');
    same($metadata,str_contains($request->html(),'Transfer code: Stored'),'Metadata independent permission');
    expect(!str_contains(json_encode($request->snapshot),'827.43'),'No cost in public component data');
}
echo "Permission matrix passed.\n";
$wild=staffUser(['*']); Auth::setUser($wild);
expect(Staff::can(Staff::COSTS)&&Staff::can(Staff::METADATA)&&$policy->managePolicy($wild,$held),'Native wildcard includes all read permissions');
expect(!$policy->update($wild,$held),'Wildcard cannot add staff writes in this slice');
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
staffDomainFilterLookalikes($viewer, $active);
\Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();
DB::table('domains_domains')->where('id',$active->id)->update(['provenance'=>'adoption_pending']);
$pending=$active->fresh();expect($pending->adoptionPending(),'Pending provenance overrides stored active status');
// Held transfers are Under review too, never Pending transfer.
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['under_review'])->assertCanSeeTableRecords([$pending,$held,$long]);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['pending_transfer'])->assertCanNotSeeTableRecords([$pending,$held,$long]);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['active'])->assertCanNotSeeTableRecords([$pending]);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['active','under_review'])->assertCanSeeTableRecords([$pending]);
same(['Under review','Import not confirmed'],[DomainFacts::status($pending)['label'],DomainFacts::status($pending)['reason']],'Pending import is Under review');
DB::table('domains_domains')->where('id',$active->id)->update(['provenance'=>null]);
$acquisition=$active->operations()->sole();$storedState=$acquisition->state;
DB::table('domains_domains')->where('id',$active->id)->update(['status'=>'needs_review']);
foreach(['held'=>'Transfer on hold','uncertain'=>'Transfer not confirmed'] as $shown=>$reason){
    DB::table('domains_operations')->where('id',$acquisition->id)->update(['state'=>$shown]);
    staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['under_review'])->assertCanSeeTableRecords([$active]);
    staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['active','pending_transfer'])->assertCanNotSeeTableRecords([$active]);
    same(['Under review',$reason],[DomainFacts::status($active->fresh())['label'],DomainFacts::status($active->fresh())['reason']],'Latest acquisition names the review reason');
}
DB::table('domains_operations')->where('id',$acquisition->id)->update(['state'=>'succeeded']);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['under_review'])->assertCanSeeTableRecords([$active]);
same(['Under review',null],[DomainFacts::status($active->fresh())['label'],DomainFacts::status($active->fresh())['reason']],'Stored review status without a held acquisition');
DB::table('domains_domains')->where('id',$active->id)->update(['status'=>'active']);
DB::table('domains_operations')->where('id',$acquisition->id)->update(['state'=>$storedState]);


// Current record holds are safe review alerts; stale identities and arbitrary reasons never leak.
$recordService=\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::class;
$requestId=$active->operations()->sole()->id;$requestUrl=\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl('view',['record'=>$requestId]);
foreach (['history_limit'=>'Sync stopped: DNS record history is full.','ownership_drift'=>'Sync stopped: domain is not in the registrar account.',
    'records_unavailable'=>"Sync stopped: couldn't read DNS records.",'unmapped_reason_code'=>'Sync stopped: DNS record check failed.'] as $reason=>$label) {
    $recordService::hold($active->fresh(),$reason,new DateTimeImmutable('2026-10-18T12:00:00Z'));
    $alert=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id]);
    $alert->assertSee($label)->assertSee('Open request #'.$requestId)->assertSeeHtml('href="'.e($requestUrl).'"')->assertDontSee('unmapped_reason_code')->assertDontSee('Resume sync')->assertSet('data',[]);
    $markup=new DOMDocument;@$markup->loadHTML($alert->html());$links=(new DOMXPath($markup))->query('//a[normalize-space(.)="Open request #'.$requestId.'"]');
    same(1,$links->length,'Record notice has its own request link');
    same($requestUrl,$links->item(0)->getAttribute('href'),'Request link targets current bound request');
    expect(!str_contains(json_encode($alert->snapshot),'record_hold'),'Encrypted hold is absent from public state');
}
$op=$active->operations()->sole();DB::table('domains_operations')->where('id',$op->id)->update(['authority_generation'=>$active->authority_generation+1]);
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee('Sync stopped: DNS record check failed.')->assertDontSee('Open request #');
DB::table('domains_operations')->where('id',$op->id)->update(['authority_generation'=>$active->authority_generation]);
$copy=$active->fresh();$p=$copy->observation;$p['record_hold']['identity']['generation']=0;$copy->update(['observation'=>$p]);
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertDontSee('Sync stopped');
$p=$copy->fresh()->observation;unset($p['record_hold']);$copy->update(['observation'=>$p]);
same(null,DomainFacts::recordHold($copy->fresh()),'An ordinary domain has no record alert');
$work=DB::table('domains_observation_work')->insertGetId(['server_id'=>$held->server_id,'account_fingerprint'=>$held->account_fingerprint,
    'domain_id'=>$held->id,'generation'=>$held->authority_generation,'due_at'=>0]);
foreach (['read_unavailable','budget_exhausted','superseded']as$code) {
    DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>$code]);
    Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee('Sync failed')->assertSee('0 attempts. Stopped until resumed.')->assertSee('Sync stopped after repeated failures.');
    foreach(['generation'=>$held->authority_generation+1,'server_id'=>$held->server_id+100,'account_fingerprint'=>str_repeat('a',64),'phase'=>'foreign']as$field=>$value){
        $old=DB::table('domains_observation_work')->where('id',$work)->value($field);DB::table('domains_observation_work')->where('id',$work)->update([$field=>$value]);
        expect(!$recordService::stopped($held->fresh()),'Foreign scheduled work never stops this holding');DB::table('domains_observation_work')->where('id',$work)->update([$field=>$old]);
    }
}
$sync=static function()use($held): array { $f=DomainFacts::for($held->fresh()); return array_key_exists('Last sync',$f)?[$f['Last sync']['value'],$f['Last sync']['sub']]:['absent',null]; };
foreach ([['read_unavailable',1,'1 attempt. Stopped until resumed.'],['budget_exhausted',5,'5 attempts. Stopped until resumed.']] as [$code,$tries,$sub]) {
    DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>$code,'tries'=>$tries]);
    same(['Sync failed',$sub],$sync(),'Stopped sync shows Sync failed with its attempts');
}
foreach ([null,'healthy_pending','refreshed','unresolved'] as $code) {
    DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>$code,'tries'=>2]);
    same([null,'Not synced yet'],$sync(),'Running sync without a saved read shows Missing with its cause, not a failure');
}
DB::table('domains_domains')->where('id',$held->id)->update(['last_synced_at'=>'2026-10-20 15:05:00']);
same(['Oct 20, 2026 15:05 UTC',null],$sync(),'Last sync shows the saved read time');
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>null,'claim'=>str_repeat('a',32),'lease_until'=>time()+300,'tries'=>1]);
same(['Oct 20, 2026 15:05 UTC','Syncing now'],$sync(),'A claimed check is syncing now');
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>'unmapped_reason_code']);
expect(!str_contains(json_encode(DomainFacts::for($held->fresh())),'unmapped_reason_code'),'Unknown saved work code never echoes');
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>'read_unavailable','generation'=>$held->authority_generation+1]);
same(['Oct 20, 2026 15:05 UTC',null],$sync(),'Foreign generation observation work is excluded');
DB::table('domains_domains')->where('id',$held->id)->update(['last_synced_at'=>null]);
DB::table('domains_observation_work')->where('id',$work)->delete();
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('review.example')->assertCanSeeTableRecords([$held])->assertCanNotSeeTableRecords([$active,$long]);
foreach ([['status',['under_review'],[$held,$long],[$active]],['server_id','2',[$active],[$held,$long]],
    ['expiring','30',[$held,$long],[$active]],['status',['active'],[$active],[$held,$long]]] as [$filter,$value,$yes,$no]) {
    staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),$filter,$value)->assertCanSeeTableRecords($yes)->assertCanNotSeeTableRecords($no);
}
DB::table('domains_operations')->where('domain_id',$long->id)->update(['state'=>'uncertain']);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['under_review'])->assertCanSeeTableRecords([$held,$long])->assertCanNotSeeTableRecords([$active]);
same([$long->id],\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::attention(Domain::query(),['uncertain'])->pluck('id')->all(),'Unconfirmed transfer query');
same(['Under review','Transfer needs attention'],[DomainFacts::status($long->fresh())['label'],DomainFacts::status($long->fresh())['reason']],'Unconfirmed transfer keeps its saved reason');
// Rejected paid obligations remain visible even when a credit retry failed.
$review=$long->operations()->sole();
DB::table('domains_operations')->where('id',$review->id)->update(['state'=>'rejected','funding_basis'=>'paid_invoice','safe_code'=>'transfer_code_timeout']);
$review->refresh();
expect(RefundCandidates::matches($review,$review->funding??[]),'Paid timeout is a review candidate, never eligibility proof');
expect(str_contains(RequestFacts::for($review)['refund'],'candidate'),'Failed credit retry candidate is visible');
same([$long->id],\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::creditReview(Domain::query())->pluck('id')->all(),'Credit review query');
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['pending_transfer'])->assertCanSeeTableRecords([$long]);
$review->funding=['refund'=>null]; expect(!RefundCandidates::matches($review,$review->funding),'Even null credit marker excludes uncredited interpretation');
$review->funding=[]; $review->safe_code='transfer_provider_rejected'; expect(!RefundCandidates::matches($review,[]),'Sent rejection is not an unsent credit candidate');
DB::table('domains_operations')->where('id',$review->id)->update(['safe_code'=>'transfer_provider_rejected']);
same([$long->id],\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::registrarRejected(Domain::query())->pluck('id')->all(),'Registrar rejected query');
same([],\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::creditReview(Domain::query())->pluck('id')->all(),'Sent rejection leaves credit review');
$review->safe_code='transfer_code_timeout'; $review->funding_basis='complimentary';
expect(str_contains(RequestFacts::for($review)['refund'],'no credit inferred'),'Never-paid rejections do not imply customer credit');
// A cross-bound operation must never appear just because its domain_id was forged through privileged SQL.
$ordinaryActivity=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active,'pageClass'=>ViewDomain::class]);
$ordinaryActivity->assertCanSeeTableRecords([$active->operations()->sole()])->assertDontSee('Review retained evidence');
DB::table('domains_operations')->where('domain_id',$active->id)->update(['safe_code'=>'unmapped_reason_code']);
Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active,'pageClass'=>ViewDomain::class])
    ->assertSee('Missing (unmapped_reason_code)')->assertDontSee('Review retained evidence');
DB::table('domains_operations')->where('domain_id',$active->id)->update(['safe_code'=>'PRIVATE-UNKNOWN']);
Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active,'pageClass'=>ViewDomain::class])
    ->assertSee('Missing')->assertDontSee('Review retained evidence')->assertDontSee('PRIVATE-UNKNOWN');
DB::table('domains_operations')->where('domain_id',$active->id)->update(['domain_id'=>$held->id,'safe_code'=>'PRIVATE-FORGED-CODE']);
$activity=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$held,'pageClass'=>ViewDomain::class]);
$activity->assertCountTableRecords(1);
expect(!str_contains($activity->html(),'PRIVATE-FORGED'),'Foreign service/account/generation activity excluded');
try { Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>999999]); expect(false,'Missing record refused'); }
catch (Illuminate\Database\Eloquent\ModelNotFoundException $e) { same(Domain::class,$e->getModel(),'Missing domain uses native not-found resolution'); }
$customer=staffUser([]); $customer->update(['role_id'=>null]);
Livewire::actingAs($customer)->test(ViewDomain::class,['record'=>$held->id])->assertForbidden();
// Direct support entry points require view too; cost-only/owner IDs grant nothing.
Auth::setUser($customer);
try { DomainFacts::for($held); expect(false,'Customer read denied'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(403,$e->getStatusCode(),'Support default deny'); }
Auth::setUser($viewer); $op=$held->operations()->first();
$op->safe_code='PRIVATE-UNKNOWN'; same('Missing',RequestFacts::for($op)['reason'],'Unknown private strings never echo');
foreach(['provider exception: secret','<script>secret</script>',"read_failed\nsecret",str_repeat('a',65),'unsafe__code','_unsafe'] as $invalid){
    same('Missing',Labels::reason($invalid),'Invalid reason strings remain hidden');
}
same('Missing ('.str_repeat('a',64).')',Labels::reason(str_repeat('a',64)),'Safe code length boundary remains visible');
$op->safe_code='transfer_provider_rejected'; $op->funding=['refund'=>['private'=>'PRIVATE-CREDIT']];
$facts=RequestFacts::for($op);
expect(str_contains($facts['registrar_refund'],'manual'),'NameSilo manual refund indicator');
expect(str_contains($facts['refund'],'Credit marker'),'Customer credit is retained separately');
same('Original send response unknown; never resend',RequestFacts::review($op)[0],'Safety instruction precedes refund and audit');
expect(!str_contains($facts['registrar_refund'],'Customer credit'),'Registrar refund does not duplicate customer-credit wording');
$op->state='rejected'; $op->safe_code='transfer_code_timeout';
expect(!isset(RequestFacts::for($op)['registrar_refund']),'Local unsent rejection does not imply a registrar charge');
// Check explicit map keys. Passthrough alone must never satisfy mapping coverage.
$labelsSource=file_get_contents(dirname(__DIR__).'/Domains/Admin/Support/Labels.php');
$reasonSource=explode('public static function reason(', $labelsSource, 2)[1];
preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>/",$reasonSource,$mapped);
$mapped=array_fill_keys($mapped[1],true);$emitted=[];
foreach (\Paymenter\Extensions\Servers\Domains\Data\RenewalCode::cases() as $reason) { $emitted[$reason->value][]='RenewalCode'; }
foreach ([\Paymenter\Extensions\Servers\Domains\Services\RuntimeReason::class,\Paymenter\Extensions\Servers\Domains\Services\OperationFailure::class] as $enum) {
    foreach($enum::cases() as $reason){$emitted[$reason->value][]=$enum;}
}
foreach(\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::CODES as $code){$emitted[$code][]='ReadFailure::CODES';}
// Driver results and the demo seed's held and uncertain requests store these codes on requests too.
foreach(\Paymenter\Extensions\Servers\Domains\Data\ResultReason::cases() as $reason){$emitted[$reason->value][]='ResultReason';}
foreach(['configuration_unavailable','unverified','authority_changed'] as $code){$emitted[$code][]='request safe_code';}
// Tokenize expressions so multiline ternaries, double quotes, comments and nested arrays do not hide codes.
$inventory=static function(string $source):array{
    $tokens=array_values(array_filter(token_get_all($source),fn($t)=>!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)));
    $text=static fn($t)=>is_array($t)?$t[1]:$t;$codes=[];$bodies=[];$contexts=[];
    foreach($tokens as $i=>$token){
        if(!is_array($token)||$token[0]!==T_FUNCTION){continue;}$signature='';
        for($j=$i+1;isset($tokens[$j])&&!in_array($text($tokens[$j]),['{',';'],true);$j++){$signature.=$text($tokens[$j]);}
        $bodies[$j]=(bool)preg_match('/:\??string$/D',$signature);
    }
    foreach($tokens as $i=>$token){
        $next=$text($tokens[$i+1]??'');$value=$text($token);
        if($value==='{'){$contexts[]=$bodies[$i]??(end($contexts)?:false);}
        if($value==='}') {array_pop($contexts);}
        $field=is_array($token)&&$token[0]===T_CONSTANT_ENCAPSED_STRING&&in_array(substr($value,1,-1),['safe_code','reason'],true);
        $start=null;
        if($field&&$next==='=>'){$start=$i+2;}
        elseif($field&&$next===']'&&$text($tokens[$i+2]??'')==='='){$start=$i+3;}
        elseif(in_array($value,['$code','$reason','safe_code'],true)&&$next==='='){$start=$i+2;}
        elseif(is_array($token)&&$token[0]===T_RETURN&&(end($contexts)||is_array($tokens[$i+1]??null)&&$tokens[$i+1][0]===T_CONSTANT_ENCAPSED_STRING&&$text($tokens[$i+2]??'')===';')){$start=$i+1;}
        elseif(is_array($token)&&$token[0]===T_NEW&&str_ends_with($next,'WriteHeld')&&$text($tokens[$i+2]??'')==='('){$start=$i+3;}
        if(is_array($token)&&$token[0]===T_FOREACH){
            for($j=$i+1;isset($tokens[$j])&&$text($tokens[$j])!=='as';$j++){}
            if($text($tokens[$j+1]??'')==='$code'){$start=$i+2;}
        }
        if($start===null){continue;}$depth=0;$callDepth=null;
        for($j=$start;isset($tokens[$j]);$j++){
            $t=$tokens[$j];$v=$text($t);
            if($depth===0&&in_array($v,[',',';',')',']','}'],true)){break;}
            if($v==='('&&is_array($tokens[$j-1]??null)&&in_array($tokens[$j-1][0],[T_STRING,T_VARIABLE,T_MATCH],true)){$callDepth??=$depth;}
            if(in_array($v,['(','[','{'],true)){$depth++;}
            if(in_array($v,[')',']','}'],true)){$depth--;}
            if($callDepth!==null&&$depth===$callDepth){$callDepth=null;}
            if($callDepth!==null||!is_array($t)||$t[0]!==T_CONSTANT_ENCAPSED_STRING){continue;}
            // Array keys and indexed field names are data structure, not emitted values.
            if($text($tokens[$j+1]??'')==='=>'||$text($tokens[$j-1]??'')==='['&&$text($tokens[$j+1]??'')===']'){continue;}
            $adjacent=[$text($tokens[$j-1]??''),$text($tokens[$j+1]??'')];
            if(array_intersect($adjacent,['===','!==','==','!=','.'])){continue;}
            $v=substr($v,1,-1);if(preg_match('/^[a-z][a-z0-9_]*$/D',$v)){$codes[]=$v;}
        }
    }
    return array_unique($codes);
};
$probe=$inventory(<<<'PHP'
<?php
function diagnostic(): string { return $ready ? "ternary_reason" : 'alternate_reason'; }
$code = match ($state) { 'unrelated_status' => 'matched_reason', default => 'default_reason' };
$record = ['safe_code' => $ok ? 'field_reason' : 'fallback_reason', 'status' => 'unrelated_result'];
throw new \Example\WriteHeld("held_reason"); // 'ignored_comment'
PHP);
sort($probe);
same(['alternate_reason','default_reason','fallback_reason','field_reason','held_reason','matched_reason','ternary_reason'],$probe,'Inventory covers quoted, conditional and typed reasons without status fields');
// These values have separate presenters or describe results, audit events, inputs or configuration.
// Exclusions are per file and code: a new reason in any service still fails coverage.
$excluded=[
    'AccountBindings.php'=>['matched','server_unavailable','server_disabled','not_configured','site_invalid','site_unbound','site_mismatch','configuration_unavailable'],
    'RegistrarBalances.php'=>['rate_limited','configuration_unavailable','read_failed','test_call_refused'], // Balance facts.
    'AdoptionReader.php'=>['identity_unconfirmed','expiry_disagrees','read_failed'], // Adoption RefusalCode messages.
    'PublicSuffixList.php'=>['not_modified','identical','updated','internal_error','rate_limited','stored_rules_rejected'], // PslFacts.
    'PslRebaseline.php'=>['stored_rules_rejected','rules_lost'],
    'WatchMaintenance.php'=>['waiting','dispatch_overdue','checks_unsuccessful','current'], // Health summary().
    'WatchWork.php'=>['disabled','queue_configuration'],
    'PublicRegistryReader.php'=>['no_expiry','facts','endpoint_failed','deferred'], // Result reason, distinct safe_code.
    'WatchRegistryObserver.php'=>['deferred'],
    'DnsResolverSettings.php'=>['dns_rcode_'], // DNS response prefix, formatted locally.
    'DomainManagement.php'=>['owner_request','staff_correction','nameservers','lock','auto_renew_off','privacy','contacts','dns','ds','glue',
        'ds_add','glue_add','glue_update','privacy_enable','privacy_disable','privacy_purchase'], // Audit reasons and capability names.
    'ManagedPublicEnrollment.php'=>['managed_enrollment'],
    'NativeServiceRefusal.php'=>['identity','price'], // Structured log fields.
    'StaffSettingsReview.php'=>['unavailable'], // Configuration snapshot sentinel.
    'TransferInputs.php'=>['transfer_code'], // Request field name.
    'RefundProof.php'=>['unsent'], // Driver execution disposition.
    'NativeCatalogue.php'=>['registration','transfer'], // Product slug suffixes.
    'RenewalStop.php'=>['cancellation','staff','adopted'], // Renewal intent source, presented separately.
    'ObservationWork.php'=>['holding'], // Work phase identity.
];
// Auto Pay uses its own explicit labels in this class.
preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>/",explode('public static function statuses()', $labelsSource, 2)[0],$autoPay);
$excluded['RenewalAutoPay.php']=$autoPay[1];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/Domains/Services')) as $file){
    if($file->getExtension()!=='php'){continue;}
    foreach(array_diff($inventory(file_get_contents($file->getPathname())),$excluded[$file->getBasename()]??[]) as $code){$emitted[$code][]=$file->getBasename();}
}
// These services forward codes from closed vocabularies rather than emitting literals at the write.
$dnsReason=new ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Dns\DnsReading::class,'safeCode');
$dnsSource=implode('',array_slice(file($dnsReason->getFileName()),$dnsReason->getStartLine()-1,$dnsReason->getEndLine()-$dnsReason->getStartLine()+1));
foreach($inventory('<?php class Diagnostic {'.$dnsSource.'}') as $code){$emitted[$code][]='DnsReading::safeCode';}
preg_match_all('/[\'"]([a-z][a-z0-9_]*)[\'"]/D',file_get_contents(dirname(__DIR__).'/Domains/Services/WriteHeld.php'),$holds);
foreach($holds[1] as $code){$emitted[$code][]='WriteHeld whitelist';}
$missing=array_diff_key($emitted,$mapped);ksort($missing);
expect($missing===[],'Service reason codes need explicit staff mappings: '.json_encode($missing));
echo 'Explicit staff reason mapping coverage: '.count($emitted)." codes.\n";
// Every known reason has its own cause text. An unknown one is Missing, with its safe code for staff.
preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>/",preg_split('/\\n    (?:public|private) static function /',$reasonSource,2)[0],$known);
expect(count($known[1])>150,'Known reasons come from the reason() map');
foreach($known[1] as $code){ $label=Labels::reason($code);
    expect(!str_starts_with($label,'Missing')&&$label!==$code&&$label!=='Review retained evidence','Known reason has cause text: '.$code); }
same("Not sent: couldn't load the registrar account's settings",'Not sent: '.lcfirst(Labels::reason('configuration_unavailable')),'Held demo request reads as status-model.md 2.2');
same('Registrar has not confirmed the result',Labels::reason('unverified'),'Uncertain demo request reads as status-model.md 2.2');
same('Missing (future_reason_code)',Labels::reason('future_reason_code'),'Unknown reason code is Missing with the code');
same('Missing',Labels::reason(null),'Absent reason is Missing');
$op->attempt=['execution'=>null]; expect(str_contains(RequestFacts::for($op)['outcome'],'unknown'),'Null execution remains unknown');
$ordinary=clone $op; $ordinary->state='succeeded'; $ordinary->safe_code=null; $ordinary->attempt=[]; $ordinary->policy=[]; $ordinary->funding=[];
same(['Customer credit: not assessed'],RequestFacts::review($ordinary),'Ordinary rows omit default warning, empty corrections and zero audit');
same('USD 0.00',Labels::money('0'),'Known zero is distinct'); same('Unknown',Labels::money(null),'Unknown cost is not zero');
same('USD 123456789012345678.1234',Labels::money('123456789012345678.12340000'),'Exact money without floats');
// Historic admitted renewals retain the sealed authorization, even without a checkout estimate.
$costUser=staffUser([Staff::VIEW,Staff::COSTS]); Auth::setUser($costUser);
$renewal=new Operation(['action'=>'renew','state'=>'succeeded','cost'=>['actual'=>['amount'=>'8.95','currency'=>'USD']],
    'attempt'=>['seal'=>'sealed','authorized_cost'=>['amount'=>'8.9500','currency'=>'USD']],
    'policy'=>['renewal'=>['cost_cap'=>['amount'=>'12.00','currency'=>'USD']]],'funding'=>[]]);
$retained=$renewal->getAttributes();
same('Authorized USD 8.95 · Actual USD 8.95',RequestFacts::for($renewal)['cost'],'Historic renewal shows authorized registrar cost');
same($retained,$renewal->getAttributes(),'Reading renewal facts never backfills retained cost evidence');
$renewal->cost=['estimated'=>'7.00','currency'=>'USD'];
same('Authorized USD 8.95 · Actual Unknown',RequestFacts::for($renewal)['cost'],'Renewal authorization stays distinct from an existing estimate');
foreach(['running','pending','uncertain','held'] as $state){
    $renewal->state=$state;
    same('Authorized USD 8.95 · Actual Unknown',RequestFacts::for($renewal)['cost'],'Retained authorization survives unresolved renewal states');
}
foreach([[],['amount'=>'8.95'],['amount'=>'8.95','currency'=>'EUR'],['amount'=>8.95,'currency'=>'USD'],['amount'=>'private','currency'=>'USD']] as $authorized){
    $renewal->attempt=$authorized===[]?[]:['authorized_cost'=>$authorized];
    same('Authorized Unknown · Actual Unknown',RequestFacts::for($renewal)['cost'],'Missing or invalid authorization never falls back to estimate or cap');
}
$renewal->attempt=['authorized_cost'=>['amount'=>'0','currency'=>'USD']];
same('Authorized USD 0.00 · Actual Unknown',RequestFacts::for($renewal)['cost'],'Known zero authorization remains distinct from unknown');
$renewal->attempt=['authorized_cost'=>['amount'=>'123456789012345678.12340000','currency'=>'USD']];
same('Authorized USD 123456789012345678.1234 · Actual Unknown',RequestFacts::for($renewal)['cost'],'Authorized cost preserves exact money');
foreach(['register','transfer'] as $action){
    $renewal->action=$action;
    same('Estimated USD 7.00 · Actual Unknown',RequestFacts::for($renewal)['cost'],'Acquisitions still render frozen checkout estimates');
}
$renewal->action='renew'; Auth::setUser($viewer);
expect(!isset(RequestFacts::for($renewal)['cost']),'Renewal authorization requires staff cost rights');
$op->attempt=['seal'=>'sealed','management_reads'=>[['confirmed'=>true]]];
$op->policy=['management'=>['correction_link'=>['operation_key'=>'missing']]];
same('Correction unresolved',RequestFacts::for($op)['correction'],'An unbound readback never closes a correction');
$metadataUser=staffUser([Staff::VIEW,Staff::METADATA]); Auth::setUser($metadataUser);
$op->payload=['epp'=>null];
expect(str_contains(RequestFacts::for($op)['metadata'],'Transfer code: Not recorded'),'Null transfer-code slot is not a stored code');
Auth::setUser($viewer);
$open=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id]);
// Same authenticated object and open components must lose revoked role permissions.
$role=$viewer->role; $role->update(['permissions'=>[]]);
$open->call('$refresh')->assertForbidden();
expect(!Staff::can()&&!DomainResource::canViewAny(),'Revocation reloads current role');
expect(!DomainResource::canCreate()&&!DomainResource::canEdit($held)&&!DomainResource::canDelete($held),'No resource writes');
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains',false),'Dormant entry');
echo "Staff domains: native Filament list/view/history, 16 permission combinations, filters, binding and secret/cost boundaries passed.\n";
$nativeSuccessful=true;
