<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts, PslReviewSchema};
staffPanel(); [, $domain]=staffData(ordinaryCompleted:true); PslReviewSchema::install();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
// Exercise the actual rendered page for every known state, including derived and unfamiliar states.
foreach(array_keys(DomainFacts::ALERTS) as $code){ObligationAlerts::record($domain,$code,time());}
$hidden=[];
foreach([...array_keys(DomainStatus::STATES),'unknown'] as $status){
    DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>$status,'provider_expires_at'=>null,'last_synced_at'=>null]);
    $html=html_entity_decode(strip_tags(Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$domain->id])->assertSuccessful()->html()),ENT_QUOTES);
    foreach(DomainFacts::ALERTS as $code=>[$row,$cause]){
        if(!str_contains($html,$cause)){$hidden[]=$code.'/'.$status;}
    }
}
same([],$hidden,'No open alert may be hidden on a domain page');
echo "All alert kinds visible in every domain state.\n";
// The account-filtered list must explain why a pending domain has an open alert.
DB::table('domains_obligation_alerts')->where('domain_id',$domain->id)->delete();
DB::table('domains_domains')->where('id',$domain->id)->update(['status'=>'registration_pending']);
ObligationAlerts::record($domain,'unknown_expiry',time());
// Alerts without an applicable row belong above the fact sections, once each.
ObligationAlerts::record($domain,'missing_obligation',time());
$pending=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$domain->id]);
$pending->assertSeeInOrder(['Open alerts','Expiry missing','Renewal invoice missing','Registration']);
$text=html_entity_decode(strip_tags($pending->html()),ENT_QUOTES);
same(1,substr_count($text,'Renewal invoice missing'),'Fallback cause appears once');
$page=Livewire::actingAs($viewer)->test(ListDomains::class)
    ->filterTable('server_id',(string)$domain->server_id)->filterTable('alerts',true);
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')
    ->assertCanSeeTableRecords([$domain])->assertTableColumnExists('alert')->assertSee('Expiry missing')->assertSee('+1 more');
// Repeated records for the same cause count once; resolved causes leave the summary.
ObligationAlerts::record($domain,'unknown_expiry',time(),identity:'second-expiry-check');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($domain->domain)->assertSee('+1 more')->assertDontSee('+2 more');
DB::table('domains_obligation_alerts')->where('domain_id',$domain->id)->where('code','unknown_expiry')->update(['resolved_at'=>time()]);
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($domain->domain)->assertSee('Renewal invoice missing')->assertDontSee('Expiry missing')->assertDontSee('+1 more');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$domain->id])->assertDontSee('Expiry missing')->assertSee('Renewal invoice missing');
// Every allowlisted kind, including late payments, has a cause in the actual list cell.
foreach([...array_keys(DomainFacts::ALERTS),'acquisition_late_money'] as $code){
    DB::table('domains_obligation_alerts')->where('domain_id',$domain->id)->update(['resolved_at'=>time()]);
    ObligationAlerts::record($domain,$code,time());
    Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($domain->domain)->assertSee(DomainFacts::alertCause($code));
}
// Unreadable renewal settings hide billing rows, but never their alert causes.
DB::table('domains_obligation_alerts')->where('domain_id',$domain->id)->update(['resolved_at'=>time()]);
foreach(array_keys(DomainFacts::ALERTS) as $code){ObligationAlerts::record($domain,$code,time());}
DB::table('domains_domains')->where('id',$domain->id)->update(['renewal_policy'=>'unreadable']);
$unreadable=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$domain->id])->assertSee('Open alerts');
foreach(DomainFacts::ALERTS as [$row,$cause]){$unreadable->assertSee($cause);}
// Unknown kinds still surface safely, without printing stored codes.
DB::table('domains_obligation_alerts')->where('domain_id',$domain->id)->update(['resolved_at'=>time()]);
DB::table('domains_obligation_alerts')->where('domain_id',$domain->id)->where('code','unknown_expiry')->limit(1)->update(['resolved_at'=>null,'code'=>'PRIVATE-UNKNOWN-CODE']);
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$domain->id])->assertSee('Domain alert needs review')->assertDontSee('PRIVATE-UNKNOWN-CODE');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($domain->domain)->assertSee('Domain alert needs review')->assertDontSee('PRIVATE-UNKNOWN-CODE');
$nativeSuccessful=true;echo "Staff alert visibility passed.\n";
