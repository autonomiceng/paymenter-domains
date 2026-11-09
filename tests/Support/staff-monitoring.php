<?php
// Domain monitoring and DNS templates staff pages over local synthetic evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/watch-notices.php';require __DIR__.'/domain-name-cell.php';require __DIR__.'/storefront.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
require dirname(__DIR__,2).'/Domains/routes/web.php';require dirname(__DIR__,2).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);Auth::setUser($manager);
// Registration DNS templates remain reachable independently of monitoring activation.
$monitoringReader=new \Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]);
$discovery=new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null);
foreach([[false,$discovery],[true,new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork()]] as [$enabled,$producer]){
    app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($monitoringReader,$producer,$enabled));
    same(true,RoutingProfileResource::canViewAny(),'Registration DNS templates stay available without monitoring');
    expect(str_contains(RoutingProfileResource::getUrl(),'dns-routing-profiles'),'Registration DNS template route stays available');
    same(true,WatchResource::shouldRegisterNavigation(),'Monitoring status remains reachable');
}
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($monitoringReader,$discovery,true));
// Clean copy: no em dash, no "unavailable", no internal terms.
$clean=function(string $html,string $page):void{$text=html_entity_decode(strip_tags($html));expect(!str_contains($text,'—'),'No em dash on '.$page);foreach(['unavailable','Saved revision','revision','feature gates','Domain watch','routing profile','mapping'] as $word){expect(stripos($text,$word)===false,'No "'.$word.'" on '.$page);}};
$column=fn($page,string $name)=>$page->instance()->getTable()->getColumn($name);
$filter=fn($page,string $name)=>$page->instance()->getTable()->getFilter($name);

$checked=static function()use($id,$uid,$f,$dns):array {
DB::table('domains_watch_names')->where('id',$id)->update(['registration'=>'external']);$at=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()-1020;noticeRegistry($f,$at,11);noticeDns($f,$at,'external');
same(false,\Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation::where('watch_id',$id)->where('check_kind','delegation')->latest('id')->sole()->facts['ns_confirmed'],'One DNS observation cannot confirm a mismatch');
noticeDns($f,$at+900,'external');
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$at,'backoff_until'=>null]);$t=$dns->begin($id,$uid,'points',$at);expect($t!==null,'Points ticket');expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('points',website:true,email:false),$at+1),'Points reading');
$expiry=gmdate('Y-m-d',$at+11*86400);$days=intdiv(strtotime($expiry.' 00:00:00 UTC')-strtotime(gmdate('Y-m-d',app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()).' 00:00:00 UTC'),86400);
return compact('at','expiry','days');
};
$applyFilters=static function($component,array $filters){
    foreach($filters as $name=>$value){$component->filterTable($name,$value);}
    return $component->set('tableDeferredFilters',$component->get('tableFilters'))->call('applyTableFilters');
};
$makeWatch=static fn(string $name,int $customer,string $state='active',array $extra=[])=>WatchName::create([
    'user_id'=>$customer,'canonical_name'=>$name,'psl_revision'=>str_repeat('a',64),'state'=>$state,
    'expected_dns'=>'platform','ns_profile_id'=>$profile->id,'profile_revision'=>1,'notice_preferences'=>[],...$extra]);
$makeLinked=static function(string $name)use($makeWatch,$uid,$service){
    $serverId=DB::table((new \App\Models\Server)->getTable())->insertGetId(['name'=>'Synthetic monitored registrar','extension'=>'Domains','type'=>'server','enabled'=>true]);
    $server=\App\Models\Server::findOrFail($serverId);
    $account=\Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(fn()=>\Paymenter\Extensions\Servers\Domains\Models\Account::create([
        'server_id'=>$server->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic-monitoring-'.$server->id]));
    $domain=\Paymenter\Extensions\Servers\Domains\Models\Domain::create(['server_id'=>$server->id,'registrar_key'=>'enom',
        'account_fingerprint'=>$account->account_fingerprint,'domain'=>$name,'sld'=>substr($name,0,-8),'tld'=>'example',
        'user_id'=>$uid,'service_id'=>$service->id,'status'=>'active']);
    return $makeWatch($name,$uid,'linked',['managed_domain_id'=>$domain->id,'registration'=>'platform']);
};
