<?php
// Native Filament permission/filter/leak regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
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
    $page->assertSuccessful()->assertSee('Saved facts')->assertSet('data',[]);
    $activity=Livewire::actingAs($user)->test(RequestsRelationManager::class,['ownerRecord'=>$held,'pageClass'=>ViewDomain::class]);
    $activity->assertCanSeeTableRecords([$held->operations()->sole()]);
    $body=$page->html().json_encode($page->snapshot).$activity->html().json_encode($activity->snapshot);
    expect(!str_contains($body,'PRIVATE-')&&!str_contains($body,'private-operation-'),'No secret/raw evidence in HTML or Livewire');
    same($cost,str_contains($body,'827.43'),'Cost HTML independent permission');
    same($cost,str_contains($body,'839.47'),'Actual nested charge permission');
    same($metadata,str_contains($body,'Transfer code: Stored'),'Metadata independent permission');
    if (!$cost) { expect(!str_contains($body,'839.47'),'Actual charge does not leak'); }
    expect(!str_contains(json_encode($page->snapshot),'827.43')&&!str_contains(json_encode($activity->snapshot),'827.43'),'No cost in public component data');
}
echo "Permission matrix passed.\n";
$wild=staffUser(['*']); Auth::setUser($wild);
expect(Staff::can(Staff::COSTS)&&Staff::can(Staff::METADATA)&&$policy->managePolicy($wild,$held),'Native wildcard includes all read permissions');
expect(!$policy->update($wild,$held),'Wildcard cannot add staff writes in this slice');
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
// Current record holds are safe review alerts; stale identities and arbitrary reasons never leak.
$recordService=\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::class;
foreach (['history_limit'=>'Record history has reached its safety limit.','ownership_drift'=>'The registrar account no longer lists this domain.',
    'records_unavailable'=>'Current records could not be confirmed.','PRIVATE-UNKNOWN'=>'The record check needs review.'] as $reason=>$label) {
    $recordService::hold($active->fresh(),$reason,new DateTimeImmutable('2026-10-18T12:00:00Z'));
    $alert=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id]);
    $alert->assertSee('Record checks paused')->assertSee($label)->assertSee('choose '.\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ACTIONS['resume_observation'])->assertSee('Open registrar request')->assertSeeHtml('href="'.e(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl('view',['record'=>$active->operations()->sole()->id])).'"')->assertDontSee('PRIVATE-UNKNOWN')->assertSet('data',[]);
    $markup=new DOMDocument;@$markup->loadHTML($alert->html());$links=(new DOMXPath($markup))->query('//a[normalize-space(.)="Open registrar request"]');
    same(1,$links->length,'Record alert has its own recovery link');
    expect(str_contains($links->item(0)->getAttribute('style'),'text-decoration:underline') && str_contains($links->item(0)->getAttribute('style'),'--tw-outline-style:solid') && str_contains($links->item(0)->getAttribute('style'),'outline-offset:-2px') && str_contains($links->item(0)->getAttribute('style'),'padding:2px'),'Recovery link has visible underline and native focus outline');
    same(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl('view',['record'=>$active->operations()->sole()->id]),$links->item(0)->getAttribute('href'),'Recovery link targets current bound request');
    expect(!str_contains(json_encode($alert->snapshot),'record_hold'),'Encrypted hold is absent from public state');
}
$op=$active->operations()->sole();DB::table('domains_operations')->where('id',$op->id)->update(['authority_generation'=>$active->authority_generation+1]);
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee('There is no current registrar request for this domain')->assertDontSee('Open registrar request');
DB::table('domains_operations')->where('id',$op->id)->update(['authority_generation'=>$active->authority_generation]);
$copy=$active->fresh();$p=$copy->observation;$p['record_hold']['identity']['generation']=0;$copy->update(['observation'=>$p]);
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertDontSee('Record checks paused');
$p=$copy->fresh()->observation;unset($p['record_hold']);$copy->update(['observation'=>$p]);
same(null,DomainFacts::recordHold($copy->fresh()),'An ordinary domain has no record alert');
$work=DB::table('domains_observation_work')->insertGetId(['server_id'=>$held->server_id,'account_fingerprint'=>$held->account_fingerprint,
    'domain_id'=>$held->id,'generation'=>$held->authority_generation,'due_at'=>0]);
foreach (['read_unavailable','budget_exhausted','superseded']as$code) {
    DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>$code]);
    Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee('Scheduled reads stopped')->assertSee('Scheduled reads need staff review')->assertDontSee('Domain facts still refresh.');
    foreach(['generation'=>$held->authority_generation+1,'server_id'=>$held->server_id+100,'account_fingerprint'=>str_repeat('a',64),'phase'=>'foreign']as$field=>$value){
        $old=DB::table('domains_observation_work')->where('id',$work)->value($field);DB::table('domains_observation_work')->where('id',$work)->update([$field=>$value]);
        expect(!$recordService::stopped($held->fresh()),'Foreign scheduled work never stops this holding');DB::table('domains_observation_work')->where('id',$work)->update([$field=>$old]);
    }
}
foreach ([[null,0,0,'Waiting for next check · 0 checks'],['healthy_pending',1,0,'Transfer pending at registrar · 1 check'],['refreshed',0,0,'Saved facts refreshed'],
    ['unresolved',2,1,'Check needs review · 2 checks · 1 resume'],['refreshed',0,2,'Saved facts refreshed · 2 resumes']] as [$code,$tries,$resumes,$label]) {
    DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>$code,'tries'=>$tries,'resumes'=>$resumes]);
    same([$label],DomainFacts::for($held)['Observation work'],'Saved variable observation outcomes have distinct counters and labels');
    Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee($label);
}
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>null,'claim'=>str_repeat('a',32),'lease_until'=>time()+300,'tries'=>1]);
same(['Check in progress · 1 check · 2 resumes'],DomainFacts::for($held)['Observation work'],'A claimed reasonless check is in progress');
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>'PRIVATE-UNKNOWN']);
expect(!str_contains(implode('',DomainFacts::for($held)['Observation work']),'PRIVATE'),'Unknown saved work code never echoes');
DB::table('domains_observation_work')->where('id',$work)->update(['generation'=>$held->authority_generation+1]);
same(['Not scheduled'],DomainFacts::for($held)['Observation work'],'Foreign generation observation work is excluded');
DB::table('domains_observation_work')->where('id',$work)->delete();
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('review.example')->assertCanSeeTableRecords([$held])->assertCanNotSeeTableRecords([$active,$long]);
foreach ([['status',['transfer_pending'],[$held,$long],[$active]],['server_id','2',[$active],[$held,$long]],
    ['expiring','30',[$held,$long],[$active]],['held',true,[$held,$long],[$active]]] as [$filter,$value,$yes,$no]) {
    staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),$filter,$value)->assertCanSeeTableRecords($yes)->assertCanNotSeeTableRecords($no);
}
DB::table('domains_operations')->where('domain_id',$long->id)->update(['state'=>'uncertain']);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'unresolved',true)->assertCanSeeTableRecords([$long])->assertCanNotSeeTableRecords([$held,$active]);
// Rejected paid obligations remain visible even when a credit retry failed.
$review=$long->operations()->sole();
DB::table('domains_operations')->where('id',$review->id)->update(['state'=>'rejected','funding_basis'=>'paid_invoice','safe_code'=>'transfer_code_timeout']);
$review->refresh();
expect(RefundCandidates::matches($review,$review->funding??[]),'Paid timeout is a review candidate, never eligibility proof');
expect(str_contains(RequestFacts::for($review)['refund'],'candidate'),'Failed credit retry candidate is visible');
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'credit_review',true)->assertCanSeeTableRecords([$long])->assertCanNotSeeTableRecords([$held,$active]);
$review->funding=['refund'=>null]; expect(!RefundCandidates::matches($review,$review->funding),'Even null credit marker excludes uncredited interpretation');
$review->funding=[]; $review->safe_code='transfer_provider_rejected'; expect(!RefundCandidates::matches($review,[]),'Sent rejection is not an unsent credit candidate');
DB::table('domains_operations')->where('id',$review->id)->update(['safe_code'=>'transfer_provider_rejected']);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'registrar_rejected',true)->assertCanSeeTableRecords([$long])->assertCanNotSeeTableRecords([$held,$active]);
staffFilter(Livewire::actingAs($viewer)->test(ListDomains::class),'credit_review',true)->assertCanNotSeeTableRecords([$long,$held,$active]);
$review->safe_code='transfer_code_timeout'; $review->funding_basis='complimentary';
expect(str_contains(RequestFacts::for($review)['refund'],'no credit inferred'),'Never-paid rejections do not imply customer credit');
// A cross-bound operation must never appear just because its domain_id was forged through privileged SQL.
$ordinaryActivity=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active,'pageClass'=>ViewDomain::class]);
$ordinaryActivity->assertCanSeeTableRecords([$active->operations()->sole()])->assertDontSee('Review retained evidence');
DB::table('domains_operations')->where('domain_id',$active->id)->update(['safe_code'=>'PRIVATE-UNKNOWN']);
Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active,'pageClass'=>ViewDomain::class])
    ->assertSee('Review retained evidence')->assertDontSee('PRIVATE-UNKNOWN');
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
$op->safe_code='PRIVATE-UNKNOWN'; same('Review retained evidence',RequestFacts::for($op)['reason'],'Unknown codes never echo');
$op->safe_code='transfer_provider_rejected'; $op->funding=['refund'=>['private'=>'PRIVATE-CREDIT']];
$facts=RequestFacts::for($op);
expect(str_contains($facts['registrar_refund'],'manual'),'NameSilo manual refund indicator');
expect(str_contains($facts['refund'],'Credit marker'),'Customer credit is retained separately');
same('Original send response unknown; never resend',RequestFacts::review($op)[0],'Safety instruction precedes refund and audit');
expect(!str_contains($facts['registrar_refund'],'Customer credit'),'Registrar refund does not duplicate customer-credit wording');
$op->state='rejected'; $op->safe_code='transfer_code_timeout';
expect(!isset(RequestFacts::for($op)['registrar_refund']),'Local unsent rejection does not imply a registrar charge');
foreach (\Paymenter\Extensions\Servers\Domains\Data\RenewalCode::cases() as $reason) {
    expect(Labels::reason($reason->value)!=='Review retained evidence','Persisted renewal reasons have staff labels');
}
// Inventory literal persisted safe codes and typed write holds, retaining an unknown fallback.
foreach (glob(dirname(__DIR__).'/Domains/Services/*.php') as $file) {
    $source=file_get_contents($file);
    preg_match_all("/'safe_code'\\s*=>\\s*'([a-z_]+)'|new WriteHeld\\('([a-z_]+)'/",$source,$codes,PREG_SET_ORDER);
    foreach ($codes as $code) { expect(Labels::reason($code[1]?:$code[2])!=='Review retained evidence','Saved engine code has a staff label'); }
}
$op->attempt=['execution'=>null]; expect(str_contains(RequestFacts::for($op)['outcome'],'unknown'),'Null execution remains unknown');
$ordinary=clone $op; $ordinary->state='succeeded'; $ordinary->safe_code=null; $ordinary->attempt=[]; $ordinary->policy=[]; $ordinary->funding=[];
same(['Customer credit: not assessed'],RequestFacts::review($ordinary),'Ordinary rows omit default warning, empty corrections and zero audit');
same('USD 0.00',Labels::money('0'),'Known zero is distinct'); same('Unknown',Labels::money(null),'Unknown cost is not zero');
same('USD 123456789012345678.1234',Labels::money('123456789012345678.12340000'),'Exact money without floats');
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
echo "Staff domains: native Filament list/view/activity, 16 permission combinations, filters, binding and secret/cost boundaries passed.\n";
$nativeSuccessful=true;
