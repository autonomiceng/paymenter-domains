<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The native fixture reaches navigation, Filament's filter and the rendered detail page.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;

Carbon::setTestNow('2026-11-02 18:00:00');
staffPanel(); [$released, , $unreleased]=staffData();
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
foreach ([$released, $unreleased] as $d) {
    $op=$d->operations()->sole();
    $policy=$op->policy;
    if ($d->id===$released->id) {
        $policy['staff_actions']=[['action'=>'release','phase'=>'completed','at'=>now()->toAtomString()]];
    }
    $op->update(['state'=>'queued','safe_code'=>null,'policy'=>$policy]);
    ObligationAlerts::record($d,'operation_review',now()->subMinute()->getTimestamp(),(int)$op->id);
}
Cache::flush();
$badge=DomainResource::getNavigationBadge();
$page=Livewire::actingAs($viewer)->test(ListDomains::class)->filterTable('alerts',true);
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')->assertSuccessful();
$filtered=$page->instance()->getTableRecords()->pluck('id')->sort()->values()->all();
$details=[];
foreach ([$released, $unreleased] as $d) {
    $html=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()->html();
    // The page names the request the alert points to, as in the approved domain mock.
    $request=(int)$d->operations()->sole()->id;
    $details[]=str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES),'Check request #'.$request)
        &&str_contains($html,'href="'.e(OperationResource::getUrl('view',['record'=>$request])).'"');
}
$actual=[$badge,$filtered,$details];
same(['1',[$unreleased->id],[false,true]],$actual,
    'A released queued review counts nowhere; an unreleased review appears in the badge, filter and detail: '.json_encode($actual));
// Read paths retain history, and a resolved alert leaves the badge immediately.
same(2,DB::table('domains_obligation_alerts')->whereNull('resolved_at')->count(),'Read paths retain both alert rows');
DB::table('domains_obligation_alerts')->where('domain_id',$unreleased->id)->update(['resolved_at'=>now()->getTimestamp()]);
same(null,DomainResource::getNavigationBadge(),'Resolved alerts leave navigation without waiting for a cache');
Carbon::setTestNow();
$nativeSuccessful=true; echo "Open alerts agree across navigation, filter and detail.\n";
