<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\{DB,Route};
use Livewire\Livewire;
use Filament\Infolists\Components\TextEntry;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarSetup,RegistrarAccount,RegistrarHistory};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
// The installed fixture exercises native pages, encrypted account/domain rows and the real settings action.
// Synthetic verified bindings replace only external authentication; no provider call or credential is used.
config(['domains.site_dns_default.nameservers'=>['ns01.connection.test']]);
$entry=new \Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();
$staff=staffUser(array_keys(Staff::permissions()));
$watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$staff->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),
    'state'=>'active','ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null,'notice_preferences'=>[]]));
staffPanel();$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([WatchResource::class]);
$pages=[RegistrarSetup::class,RegistrarAccount::class,RegistrarHistory::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages):void {
    WatchResource::registerRoutes($panel);foreach($pages as$page){$page::registerRoutes($panel);}
});app('router')->getRoutes()->refreshNameLookups();
$names=static fn(int $n)=>array_map(fn($i)=>sprintf('ns%02d.connection.test',$i),range(1,$n));
$make=static fn(int $n)=>DB::transaction(fn()=>WatchProfile::create(['name'=>'Connection template '.$n,'nameservers'=>$names($n),
    'enabled'=>true,'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$staff->id,'reason'=>'Synthetic connection limit']));
$snapshot=static fn()=>array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->toJson(),[
    'domains_domains','domains_operations','domains_watch_names','domains_watch_profiles','domains_watch_audit']);
$connect=static function(string $registrar)use($snapshot):Account {
    $before=$snapshot();$saved=Setting::where('key',SiteDnsDefault::KEY)->sole()->getRawOriginal();
    $server=Server::create(['name'=>'Synthetic '.$registrar,'extension'=>'Domains','type'=>'server','enabled'=>true]);
    $account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>$registrar,
        'environment'=>'test','stable_identity'=>'synthetic-'.$registrar,'processing_enabled'=>false]));
    same($before,$snapshot(),'Connecting '.$registrar.' never rewrites defaults, domain nameservers, watches or audits');
    same($saved,Setting::where('key',SiteDnsDefault::KEY)->sole()->getRawOriginal(),'Connection preserves the exact saved default');
    return $account;
};
$row=static fn($page)=>$page->instance()->getSchema('content')->getComponent(fn($c)=>$c instanceof TextEntry&&in_array($c->getLabel(),['Site DNS default','New domains use'],true));
$assertMissing=static function(Account $account,string $rule)use($staff,$row):void {
    // Account first: it must show the problem immediately after connect, without revisiting settings or upgrading.
    foreach([[RegistrarAccount::class,['account'=>$account->server_id]],[MonitoringSettings::class,[]]]as[$class,$params]){
        $page=Livewire::actingAs($staff)->test($class,$params)->assertSee($rule);
        $entry=$row($page);expect($entry!==null,'Native page has a Site DNS default row');
        same('Missing',$entry->getState(),'Newly incompatible default is Missing');
        same('danger',$entry->getColor($entry->getState()),'Newly incompatible default is red');
        unset($entry,$page);gc_collect_cycles();gc_mem_caches();
    }
};
$select=static function(WatchProfile $profile,array $accounts,string $oldRule)use($staff,$row):void {
    $domains=Domain::orderBy('id')->get()->toJson();$watches=WatchName::orderBy('id')->get()->toJson();
    Livewire::actingAs($staff)->test(MonitoringSettings::class)->callAction('siteDefault',['profile'=>$profile->id])->assertHasNoActionErrors();
    same($profile->id,SiteDnsDefault::profile()?->id,'Staff selection is the only default change');
    same($domains,Domain::orderBy('id')->get()->toJson(),'Selecting a default never changes existing domain nameservers');
    same($watches,WatchName::orderBy('id')->get()->toJson(),'Selecting a default leaves watch assignments unchanged');
    foreach([[MonitoringSettings::class,[]],...array_map(fn($a)=>[RegistrarAccount::class,['account'=>$a->server_id]],$accounts)]as[$class,$params]){
        $page=Livewire::actingAs($staff)->test($class,$params)->assertDontSee($oldRule);
        $entry=$row($page);same($profile->name,$entry?->getState(),'Valid staff choice clears Missing on each native page');
        expect($entry->getColor($entry->getState())!=='danger','Valid default is no longer red');
        unset($entry,$page);gc_collect_cycles();gc_mem_caches();
    }
};
$initial=SiteDnsDefault::profile()->id;
$silo=$connect('namesilo');$siloRule='NameSilo takes 2 to 13 nameservers. Update this DNS template or choose another one.';
$assertMissing($silo,$siloRule);same($initial,SiteDnsDefault::profile()->id,'Incompatible reference remains saved');
$domain=Domain::create(['server_id'=>$silo->server_id,'registrar_key'=>'namesilo','account_fingerprint'=>$silo->account_fingerprint,
    'domain'=>'example.com','sld'=>'example','tld'=>'com','user_id'=>$staff->id,'service_id'=>401,'status'=>'active',
    'observation'=>['facts'=>['nameservers'=>['ns1.customer.test','ns2.customer.test']]]]);
$thirteen=$make(13);$select($thirteen,[$silo],$siloRule);
$enom=$connect('enom');$enomRule='Enom takes 2 to 12 nameservers. Update this DNS template or choose another one.';
$assertMissing($enom,$enomRule);$assertMissing($silo,$enomRule);
same($thirteen->id,SiteDnsDefault::profile()->id,'Later Enom connection never replaces NameSilo-compatible default');
$twelve=$make(12);$select($twelve,[$silo,$enom],$enomRule);
$nativeSuccessful=true;echo "DNS default compatibility after registrar connection passed.\n";
