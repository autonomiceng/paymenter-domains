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
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
foreach ([$released, $unreleased] as $d) {
    $op=$d->operations()->sole()->replicate()->fill(['action'=>'renew','operation_key'=>'held-alert-'.$d->id,
        'base_expires_at'=>$d->provider_expires_at,'state'=>'queued','attempt'=>[],'policy'=>[]]);
    $op->save();
    $policy=$op->policy;
    if ($d->id===$released->id) {
        $policy['staff_actions']=[['action'=>'release','phase'=>'completed','at'=>now()->toAtomString()]];
    }
    $op->update(['state'=>'queued','safe_code'=>null,'policy'=>$policy]);
    ObligationAlerts::record($d,'held_obligation',now()->subMinute()->getTimestamp(),(int)$op->id);
}
Cache::flush();
$badge=DomainResource::getNavigationBadge();
$page=Livewire::actingAs($viewer)->test(ListDomains::class)->filterTable('alerts',true);
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')->assertSuccessful();
$filtered=$page->instance()->getTableRecords()->pluck('id')->sort()->values()->all();
$cause=DomainFacts::alertCause('held_obligation');
$details=[];
foreach ([$released, $unreleased] as $d) {
    $html=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()->html();
    $details[]=str_contains(html_entity_decode(strip_tags($html),ENT_QUOTES),$cause);
}
$actual=[$badge,$filtered,$details];
same(['1',[$unreleased->id],[false,true]],$actual,
    'A released queued renewal hold counts nowhere; an unreleased review appears in the badge, filter and detail: '.json_encode($actual));
// The read rule preserves the alert history, and the existing minute cache still avoids repeated count queries.
same(2,DB::table('domains_obligation_alerts')->whereNull('resolved_at')->count(),'Read paths retain both alert rows');
DB::connection()->flushQueryLog(); DB::connection()->enableQueryLog();
same('1',DomainResource::getNavigationBadge(),'Warm badge retains the visible count');
$domainReads=array_filter(DB::connection()->getQueryLog(),fn(array $q)=>str_contains($q['query'],'domains_'));
same([],$domainReads,'Warm badge performs no domain-data reads');
DB::connection()->disableQueryLog();
Carbon::setTestNow();
$nativeSuccessful=true; echo "Open alerts agree across navigation, filter and detail.\n";
