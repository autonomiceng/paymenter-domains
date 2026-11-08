<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff domain page and action boundaries.
declare(strict_types=1);
require __DIR__.'/Support/staff-domain-edits.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainEditActions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ObservationSchema,OwnedDomains,OwnerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
$viewer=staffUser([Staff::VIEW]);
// Real paid requests and Livewire Release exercise the saved audit and the domain rail together.
// A unit row fixture cannot prove that the real action records the actor and time the page reads.
$releaseStaff=staffUser([Staff::VIEW,'admin.domains.manage',StaffOperationReview::permission('hold'),StaffOperationReview::permission('release')]);
$releaseStaff->update(['first_name'=>'Alex','last_name'=>'Admin']);
$releaseOps=[$new(placeholderComFixtureName('rail-release-placeholder.com'),action:'transfer'),$new(placeholderComFixtureName('rail-held-placeholder.com'),action:'transfer')];
$reviews=new StaffOperations($clock);
foreach ($releaseOps as $request) {
    $key=StaffOperationReview::ticket($releaseStaff->id,$request->domain_id,$request->id,'hold',StaffOperationReview::stamp($request,$request->domain),$clock);
    $reviews->run($releaseStaff,$request->domain_id,$request->id,'hold',$key,'Put on hold');
}
$clock->instant=$clock->now()->modify('+5 minutes');
DB::table('domains_accounts')->where('server_id',$releaseOps[0]->server_id)->update(['processing_enabled'=>false]);
Livewire::actingAs($releaseStaff)->test(ViewOperation::class,['record'=>$releaseOps[0]->id])->assertActionVisible('release')
    ->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
same('queued',$releaseOps[0]->fresh()->state,'The real Release leaves the request queued with Orders off');
foreach ($releaseOps as $i=>$request) {
    $html=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$request->domain_id])->assertSuccessful()->html();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $rail='//*[contains(concat(" ",normalize-space(@class)," ")," domains-rail ")]';
    $entries=$xpath->query($rail.'//*[contains(concat(" ",normalize-space(@class)," ")," domains-trace-item ")][contains(.,"Hold released")]');
    same($i===0?1:0,$entries->length,'Only the released domain rail has a staff release entry');
    if ($i===0) {
        expect(str_contains($entries->item(0)->textContent,'Alex Admin'),'The rail names the staff member who released the hold');
        same($clock->now()->format(DATE_ATOM),$xpath->query('.//time',$entries->item(0))->item(0)?->getAttribute('datetime'),'The release row uses the action time');
    }
    $railText=preg_replace('/\s+/',' ',$xpath->query($rail)->item(0)->textContent);
    expect(str_contains($railText,'Request #'.$request->id.' Transfer '.($i===0?'Under review':'On hold')),'Only the retained staff hold reads On hold');
    if ($i===0) { expect(str_contains($railText,'Not sent. Orders are off for this account.'),'The released request keeps its Orders-off reason'); }
}
echo "Staff release history passed.\n";$nativeSuccessful=true;
