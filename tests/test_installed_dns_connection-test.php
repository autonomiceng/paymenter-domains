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
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\ListConfiguration;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
use Paymenter\Extensions\Servers\Domains\Presentation\{State,Tone};
// The installed fixture exercises native pages, encrypted account/domain rows and the real settings action.
// Synthetic verified bindings replace only external authentication; no provider call or credential is used.
config(['domains.site_dns_default.nameservers'=>['ns01.connection.test']]);
$entry=new \Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();
$staff=staffUser(array_keys(Staff::permissions()));
$watch=DB::transaction(fn()=>WatchName::create(['user_id'=>$staff->id,'canonical_name'=>'example.com','psl_revision'=>str_repeat('a',64),
    'state'=>'active','ns_profile_id'=>null,'profile_revision'=>0,'expected_dns'=>null,'notice_preferences'=>[]]));
staffPanel();$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([WatchResource::class,RoutingProfileResource::class]);
$pages=[RegistrarSetup::class,RegistrarAccount::class,RegistrarHistory::class];$panel->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages):void {
    WatchResource::registerRoutes($panel);RoutingProfileResource::registerRoutes($panel);foreach($pages as$page){$page::registerRoutes($panel);}
});app('router')->getRoutes()->refreshNameLookups();
$names=static fn(int $n)=>array_map(fn($i)=>sprintf('ns%02d.connection.test',$i),range(1,$n));
$make=static fn(int $n)=>DB::transaction(fn()=>WatchProfile::create(['name'=>'Connection template '.$n,'nameservers'=>$names($n),
    'enabled'=>true,'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$staff->id,'reason'=>'Synthetic connection limit']));
$snapshot=static fn()=>array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->toJson(),[
    'domains_domains','domains_operations','domains_watch_names','domains_watch_profiles','domains_watch_audit']);
$connect=static function(string $registrar)use($snapshot):Account {
    $before=$snapshot();$saved=fixtureBusinessRaw(SiteDnsDefault::KEY);
    $server=Server::create(['name'=>'Synthetic '.$registrar,'extension'=>'Domains','type'=>'server','enabled'=>true]);
    $account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>$registrar,
        'environment'=>'test','stable_identity'=>'synthetic-'.$registrar,'processing_enabled'=>false]));
    same($before,$snapshot(),'Connecting '.$registrar.' never rewrites defaults, domain nameservers, watches or audits');
    same($saved,fixtureBusinessRaw(SiteDnsDefault::KEY),'Connection preserves the exact saved default');
    return $account;
};
$row=static fn($page)=>$page->instance()->getSchema('content')->getComponent(fn($c)=>$c instanceof TextEntry&&in_array($c->getLabel(),['Default nameservers','New domains use'],true));
$assertMissing=static function(Account $account,string $rule)use($staff,$row):void {
    // Account first: it must show the problem immediately after connect, without revisiting settings or upgrading.
    // Monitoring settings draws it as a panel row: Not set, in the attention tone.
    $settings=settingsRowValue(Livewire::actingAs($staff)->test(MonitoringSettings::class)->assertSee($rule)->html(),'Default nameservers');
    same([State::NotSet->label(),Tone::Attention->value],[$settings['word']??null,$settings['tone']??null],'Monitoring settings says the newly incompatible default is not set');
    foreach([[RegistrarAccount::class,['account'=>$account->server_id]]]as[$class,$params]){
        $page=Livewire::actingAs($staff)->test($class,$params)->assertSee($rule);
        $entry=$row($page);expect($entry!==null,'Native page has a Site DNS default row');
        same('Missing',$entry->getState(),'Newly incompatible default is Missing');
        same('danger',$entry->getColor($entry->getState()),'Newly incompatible default is red');
        unset($entry,$page);gc_collect_cycles();gc_mem_caches();
    }
};
// The rendered New domains use row: its text and its links by label.
$dnsRow=static function(string $html):array {
    $rows=array_values(array_filter(iterator_to_array(Dom\HTMLDocument::createFromString($html,LIBXML_NOERROR)->querySelectorAll('.domains-row')),fn($node)=>str_contains($node->textContent,'New domains use')));
    same(1,count($rows),'The page has one New domains use row');$links=[];
    foreach($rows[0]->querySelectorAll('a[href]') as $a){$links[trim(preg_replace('/\s+/',' ',$a->textContent))]=$a->getAttribute('href');}
    return ['text'=>trim(preg_replace('/\s+/',' ',$rows[0]->textContent)),'links'=>$links];
};
$select=static function(WatchProfile $profile,array $accounts,string $oldRule)use($staff,$row,$dnsRow):void {
    $domains=Domain::orderBy('id')->get()->toJson();$watches=WatchName::orderBy('id')->get()->toJson();
    Livewire::actingAs($staff)->test(ListConfiguration::class)->callAction('siteDefault',['profile'=>$profile->id])->assertHasNoActionErrors();
    same($profile->id,SiteDnsDefault::profile()?->id,'Staff selection is the only default change');
    same($domains,Domain::orderBy('id')->get()->toJson(),'Selecting a default never changes existing domain nameservers');
    same($watches,WatchName::orderBy('id')->get()->toJson(),'Selecting a default leaves watch assignments unchanged');
    foreach([[MonitoringSettings::class,[]],...array_map(fn($a)=>[RegistrarAccount::class,['account'=>$a->server_id]],$accounts)]as[$class,$params]){
        $page=Livewire::actingAs($staff)->test($class,$params)->assertDontSee($oldRule);
        // Monitoring settings draws it as a panel row; the account page keeps its entry.
        if($class===MonitoringSettings::class){same($profile->name,settingsRowValue($page->html(),'Default nameservers')['word']??null,'Valid staff choice is the Monitoring settings default');continue;}
        $entry=$row($page);same($profile->name,$entry?->getState(),'Valid staff choice clears Missing on each native page');
        // The account page names what new domains get, with its nameservers and Change, as in the approved mock.
        if($class===RegistrarAccount::class){same('New domains use',$entry->getLabel(),'Account row says what new domains use');
            $dns=$dnsRow($page->html());
            foreach([implode(', ',$profile->nameservers),'New registrations use these nameservers unless the customer chooses others. Transfers keep the nameservers they already have.'] as $text){expect(str_contains($dns['text'],$text),'The DNS row says: '.$text);}
            same(['Change'=>RoutingProfileResource::getUrl()],$dns['links'],'The DNS row links Change to DNS templates');}
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
// Change needs both DNS templates and the right to change the default there; the writer still refuses the rest.
$all=array_keys(Staff::permissions());
foreach(['view only'=>[Staff::VIEW],'no settings page'=>array_values(array_diff($all,[\Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::VIEW])),
    'no domain policy'=>array_values(array_diff($all,[Staff::POLICY])),'no site DNS default'=>array_values(array_diff($all,[SiteDnsDefault::MANAGE])),'full'=>$all] as $case=>$grants){
    $user=staffUser($grants);$full=$case==='full';
    $dns=$dnsRow(Livewire::actingAs($user)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful()->html());
    expect(str_contains($dns['text'],$twelve->name),'Every staff member sees the default: '.$case);
    same($full?['Change'=>RoutingProfileResource::getUrl()]:[],$dns['links'],'The DNS row offers Change only with full rights: '.$case);
    if($full||$case==='no settings page'){continue;}
    try{SiteDnsDefault::save($user,$thirteen->id);expect(false,'The writer refuses without edit rights: '.$case);}
    catch(Symfony\Component\HttpKernel\Exception\HttpException $error){same(403,$error->getStatusCode(),'The writer refuses without edit rights: '.$case);}
    same($twelve->id,SiteDnsDefault::profile()?->id,'A refused change keeps the default: '.$case);
    unset($dns);gc_collect_cycles();gc_mem_caches();
}
// Staff who cannot change the site default see the cause on the registrar page and are sent to staff who can.
$viewer=staffUser(array_values(array_diff(array_keys(Staff::permissions()),[SiteDnsDefault::MANAGE])));
$readOnly=static function(Account $account,string $detail,string $hidden)use($viewer,$row):void {
    $page=Livewire::actingAs($viewer)->test(RegistrarAccount::class,['account'=>$account->server_id])->assertSee($detail)->assertDontSee($hidden);
    $entry=$row($page);same('Missing',$entry?->getState(),'A read-only Default nameservers row is Missing');
    same('danger',$entry->getColor($entry->getState()),'A read-only Default nameservers row is red');
    unset($entry,$page);gc_collect_cycles();gc_mem_caches();
};
fixtureBusinessChoice(SiteDnsDefault::KEY,(string)$thirteen->id,'string');
$readOnly($enom,'Enom takes 2 to 12 nameservers. '.SiteDnsDefault::ASK,'Update this DNS template or choose another one.');
config(['domains.site_dns_default.nameservers'=>[]]);fixtureBusinessChoice(SiteDnsDefault::KEY,'','string');
$readOnly($silo,'New registrations have no site-default nameservers. NameSilo needs explicit nameservers before an order can be sent. '.SiteDnsDefault::ASK,'Choose one in Domains > DNS templates.');
$readOnly($enom,'New registrations have no site-default nameservers. '.SiteDnsDefault::ASK,'NameSilo needs explicit nameservers');
$nativeSuccessful=true;echo "DNS default compatibility after registrar connection passed.\n";
