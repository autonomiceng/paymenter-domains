<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\{DB,Route};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings;
use Paymenter\Extensions\Servers\Domains\Models\{Account,WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,SiteDnsDefault};
// Native lifecycle, encrypted account bindings and rendered Missing rows need the real installed fixture.
$entry=new \Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();
$staff=staffUser(['admin.domains.view','admin.domains.watch.view','admin.domains.watch.manage']);
staffPanel();$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([WatchResource::class]);
Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>WatchResource::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
$connect=static function(string $registrar):void {
    $server=Server::create(['name'=>'Synthetic '.$registrar,'extension'=>'Domains','type'=>'server','enabled'=>true]);
    Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>$registrar,
        'environment'=>'test','stable_identity'=>'synthetic-'.$registrar,'processing_enabled'=>false]));
};
$names=static fn(int $n)=>array_map(fn($i)=>sprintf('ns%02d.seed.test',$i),range(1,$n));
$unset=static fn()=>Setting::where('key',SiteDnsDefault::KEY)->whereNull('settingable_type')->whereNull('settingable_id')->delete();
$reject=static function(int $n,string $rule)use($names,$staff):void {
    config(['domains.site_dns_default.nameservers'=>$names($n)]);
    $before=[WatchProfile::count(),WatchAudit::count()];
    app(Installation::class)->install();
    same(null,SiteDnsDefault::profile(),'Normal installation refuses an incompatible deployment default: '.$rule);
    same(false,Setting::where('key',SiteDnsDefault::KEY)->exists(),'Rejected deployment leaves the default unset');
    same($before,[WatchProfile::count(),WatchAudit::count()],'Rejected deployment creates no template or audit');
    $page=Livewire::actingAs($staff)->test(MonitoringSettings::class)->assertSee('Missing')->assertSee($rule);
    $row=$page->instance()->getSchema('content')->getComponent('site_dns',withHidden:true);
    same('Missing',$row->getState(),'Rejected seed is Missing on the actual settings row');
    same('danger',$row->getColor($row->getState()),'Rejected seed is red');
    unset($row,$page);gc_collect_cycles();gc_mem_caches();
};
$connect('namesilo');
$reject(1,'NameSilo takes 2 to 13 nameservers. Update this DNS template or choose another one.');
config(['domains.site_dns_default.nameservers'=>$names(13)]);app(Installation::class)->install();
$seeded=SiteDnsDefault::profile();same($names(13),$seeded?->nameservers,'NameSilo accepts its upper limit at installation');
$count=WatchProfile::count();$audit=WatchAudit::count();
config(['domains.site_dns_default.nameservers'=>$names(1)]);app(Installation::class)->install();
same($seeded->id,SiteDnsDefault::profile()?->id,'Configuration never replaces a saved choice');
same([$count,$audit],[WatchProfile::count(),WatchAudit::count()],'Repeated installation remains idempotent');
$unset();$connect('enom');
foreach([1,13]as$n){$reject($n,'Enom takes 2 to 12 nameservers. Update this DNS template or choose another one.');}
foreach([2,12]as$n){
    config(['domains.site_dns_default.nameservers'=>$names($n)]);app(Installation::class)->install();
    same($names($n),SiteDnsDefault::profile()?->nameservers,'Both connected registrars accept installation boundary '.$n);
    $unset();
}
$nativeSuccessful=true;echo "DNS deployment seed registrar limits passed.\n";
