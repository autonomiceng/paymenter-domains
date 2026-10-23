<?php
// Native widget discovery/count regressions; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\Widgets\DomainCounts;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
staffPanel(); [$held,$active,$long]=staffData();
Carbon\Carbon::setTestNow('2026-10-20 12:00:00 UTC');
// Native page discovery queues all nested Livewire components, including non-page widgets.
$panel=Filament::getPanel('admin');
$finder=app('livewire.finder');
expect($finder->normalizeName(DomainCounts::class)!==DomainCounts::class,'Widget is not registered before discovery');
$panel->discoverPages(in:dirname(__DIR__).'/Domains/Admin/Pages',for:'Paymenter\\Extensions\\Servers\\Domains\\Admin\\Pages');
$panel->register();
[, $name]=app('livewire.finder')->parseNamespaceAndName(DomainCounts::class);
same($name,$finder->normalizeName(DomainCounts::class),'Native discovery registers the widget alias');
same(DomainCounts::class,$finder->resolveClassComponentClassName($name),'Discovered alias resolves to the widget');
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
function counts(): array
{
    $page=Livewire::test(DomainCounts::class)->assertSuccessful();
    $dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);$out=[];
    foreach($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," fi-wi-stats-overview-stat ")]') as $stat){
        $label=trim($xpath->query('.//*[contains(@class,"fi-wi-stats-overview-stat-label")]', $stat)->item(0)->textContent);
        $value=trim($xpath->query('.//*[contains(@class,"fi-wi-stats-overview-stat-value")]', $stat)->item(0)->textContent);
        $out[$label]=$value;
    }
    expect(!str_contains($page->html(),'wire:poll'),'Widget never polls');return $out;
}
foreach([[-1,0],[0,1],[30,1],[31,0]] as [$days,$expected]){
    DB::table('domains_domains')->update(['provider_expires_at'=>null]);
    DB::table('domains_domains')->where('id',$held->id)->update(['provider_expires_at'=>today()->addDays($days)->format('Y-m-d')]);
    same((string)$expected,counts()['Expiring in 30 days'],'Inclusive 30-day window excludes past and day 31');
}
$op=$held->operations()->sole();
DB::table('domains_operations')->update(['state'=>'succeeded']);
foreach(['held'=>1,'uncertain'=>1,'pending'=>0,'running'=>0,'rejected'=>0,'succeeded'=>0] as $state=>$expected){
    DB::table('domains_operations')->where('id',$op->id)->update(['state'=>$state]);
    same((string)$expected,counts()['On hold or unconfirmed'],'Default attention states: '.$state);
}
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'held','authority_generation'=>$held->authority_generation+1]);
same('0',counts()['On hold or unconfirmed'],'Foreign generation does not count as attention');
same('3',counts()['Domains'],'All domains are counted once');
foreach([[],[Staff::COSTS]] as $permissions){
    $denied=staffUser($permissions);Auth::setUser($denied);
    same(false,DomainCounts::canView(),'Widget default denies without view permission');
    Livewire::test(DomainCounts::class)->assertForbidden();
}
Auth::setUser($viewer);$open=Livewire::test(DomainCounts::class)->assertSuccessful();
$viewer->role->update(['permissions'=>[]]);$open->call('$refresh')->assertForbidden();
echo "Native widget discovery, date/attention boundaries and fresh authorization passed.\n";
$nativeSuccessful=true;
