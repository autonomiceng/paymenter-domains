<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The native fixture reaches all three callers: cached navigation, Filament's filter and the rendered detail page.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;

Carbon::setTestNow('2026-11-02 18:00:00');
staffPanel(); [$released, , $unreleased]=staffData();
// Isolate saved review alerts from the independent Orders-off staff task.
Paymenter\Extensions\Servers\Domains\Models\Account::query()->update(['processing_enabled'=>true]);
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
$link=DomainResource::getNavigationBadgeUrl();
parse_str(parse_url($link,PHP_URL_QUERY),$parameters);
same(['alerts'=>['isActive'=>'1']],$parameters['filters']??null,'The badge link selects exactly the alert filter');
$page=Livewire::withQueryParams($parameters)->actingAs($viewer)->test(ListDomains::class);
same('Needs attention',$page->instance()->getTable()->getFilter('alerts')->getLabel(),'Staff can select the same Needs attention filter themselves');
expect(str_contains(DomainResource::getNavigationBadgeTooltip(),'staff action'),'Badge title explains the count');
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')->assertSuccessful();
$filtered=$page->instance()->getTableRecords()->pluck('id')->sort()->values()->all();
$cause=DomainFacts::alertCause('operation_review');
$details=[];
foreach ([$released, $unreleased] as $d) {
    $html=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()->html();
    // The page names the request the alert points to, as in the approved domain mock.
    $details[]=str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES),str_replace('Check the request','Check request #'.$d->operations()->sole()->id,$cause));
}
$actual=[$badge,$filtered,$details];
same(['1',[$unreleased->id],[false,true]],$actual,
    'A released queued review counts nowhere; an unreleased review appears in the badge, filter and detail: '.json_encode($actual));
// The read rule preserves the alert history, and the existing minute cache still avoids repeated count queries.
same(2,DB::table('domains_obligation_alerts')->whereNull('resolved_at')->count(),'Read paths retain both alert rows');
// Opening the list drops the cached count so the badge matches it (journeys-r2 item 2); the next menu read fills it again.
same('1',DomainResource::getNavigationBadge(),'The badge refills with the list count');
DB::connection()->flushQueryLog(); DB::connection()->enableQueryLog();
same('1',DomainResource::getNavigationBadge(),'Warm badge retains the visible count');
$domainReads=array_filter(DB::connection()->getQueryLog(),fn(array $q)=>str_contains($q['query'],'domains_'));
same([],$domainReads,'Warm badge performs no domain-data reads');
DB::connection()->disableQueryLog();
// An unreadable encrypted funding record keeps an ended request's alert live.
$op=$unreleased->operations()->sole();
DB::table('domains_operations')->where('id',$op->id)->update(['funding'=>'unreadable funding']);
$ended=clone $unreleased;$ended->status='cancelled';
same(false,ObligationAlerts::outdated((object)['code'=>'operation_review'],$op->fresh(),collect(),$ended),'Unreadable funding preserves the alert without breaking the page');
// An unreadable encrypted policy cannot prove a release, so the released request's alert shows again in the shared read.
$releasedOp=$released->operations()->sole();
same(false,ObligationAlerts::open([$released->id])->where('operation_id',$releasedOp->id)->exists(),'A readable release keeps the alert out of the shared read');
DB::table('domains_operations')->where('id',$releasedOp->id)->update(['policy'=>'unreadable policy']);
same(true,ObligationAlerts::open([$released->id])->where('operation_id',$releasedOp->id)->exists(),'Unreadable policy keeps the alert in the shared read');
same(false,ObligationAlerts::outdated((object)['code'=>'operation_review'],$releasedOp->fresh(),collect(),$released),'Unreadable policy keeps the alert on the detail page');
Carbon::setTestNow();
$nativeSuccessful=true; echo "Open alerts agree across navigation, filter and detail.\n";
