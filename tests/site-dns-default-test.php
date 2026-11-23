<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
require __DIR__.'/Support/FakeDnsReader.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,NewDomainDns};
// Installation has no staff actor yet. Deployment supplies literal public names, never registrar credentials.
config(['domains.site_dns_default.nameservers'=>['ns1.deployment.test','ns2.deployment.test']]);
extract(dnsFixture());
same(1,WatchProfile::where('name','Site DNS')->where('enabled',true)->count(),'Installation seeds one enabled deployment DNS template');
$default=SiteDnsDefault::profile();
same(['ns1.deployment.test','ns2.deployment.test'],$default->nameservers,'Deployment owns the nameservers');
same($default->id,NewDomainDns::profile($server->id)->id,'Site default works independently of registrar account');
same([],NewDomainDns::capture($server->id,'transfer'),'Site DNS default never supplies transfer nameservers');
same('site_default',NewDomainDns::capture($server->id,'register')['nameservers_origin']['source'],'Registration freezes site-default provenance');
$legacy='domains_new_domain_dns_template';$legacyServers=[];
foreach(['',(string)$profile->id]as$value){$accountServer=\App\Models\Server::create(['name'=>'Retired account DNS','extension'=>'Domains','type'=>'server','enabled'=>true]);$legacyServers[]=$accountServer;
 $accountServer->settings()->create(['key'=>$legacy,'type'=>'string','encrypted'=>false,'value'=>$value]);}
$reading=false;$legacyRead=false;DB::listen(static function($query)use(&$reading,&$legacyRead,$legacy):void{if($reading&&str_starts_with($query->sql,'select')&&in_array($legacy,$query->bindings,true)){$legacyRead=true;}});
$reading=true;foreach($legacyServers as$accountServer){same($default->id,NewDomainDns::profile($accountServer->id)->id,'Neither retired None nor a retired account template affects site DNS');}$reading=false;
expect(!$legacyRead,'Registration never reads the retired account setting');
$entry->upgraded();same($default->id,SiteDnsDefault::profile()->id,'Upgrade keeps one stable default reference');
same(0,\App\Models\Setting::where('key',$legacy)->count(),'Site-default upgrade deletes retired account DNS settings');
same(1,WatchProfile::where('name','Site DNS')->count(),'Upgrade does not duplicate the deployment template');
$manager=staffUser(['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.new_domain_dns','admin.domains.watch.view','admin.domains.watch.manage']);
\Illuminate\Support\Facades\Auth::setUser($manager);
$viewer=staffUser(['admin.domains.view','admin.domains.watch.view','admin.domains.watch.manage']);
$replacement=DB::transaction(fn()=>WatchProfile::create(['name'=>'Explicit replacement','enabled'=>true,'nameservers'=>['ns1.other.test','ns2.other.test'],
    'edited_by'=>$manager->id,'reason'=>'Synthetic site change']));
SiteDnsDefault::save($manager,$replacement->id);
SiteDnsDefault::save($manager,$default->id);
// Mount the actual pages to verify Missing and the one site-level writer with permission checks. The default is set
staffPanel();
$templates=\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::class;
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->resources([$templates]);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(fn()=>$templates::registerRoutes($panel));
app('router')->getRoutes()->refreshNameLookups();
$settingsPage=\Paymenter\Extensions\Servers\Domains\Admin\Pages\DnsResolver::class;
$page=\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\ListConfiguration::class;
\Livewire\Livewire::actingAs($viewer)->test($page)->assertActionHidden('siteDefault')->assertActionDoesNotExist('editDefault');
fixtureClearBusinessChoice(SiteDnsDefault::KEY);
config(['domains.site_dns_default.nameservers'=>null]);$entry->upgraded();
$settings=\Livewire\Livewire::actingAs($manager)->test($page)->assertSee(SiteDnsDefault::MISSING);
// ui-r15 R4: the step is said once, in the red callout; the Default nameservers row shows only Missing.
same(1,substr_count($settings->html(),'Choose a template in Domains &gt; DNS templates'),'Default nameservers step shows once');
$settings->callAction('siteDefault',['profile'=>$default->id])->assertHasNoActionErrors()->assertNotified('Default nameservers saved');
same($default->id,SiteDnsDefault::profile()->id,'Site settings save a single global reference');
$edit = fn ($template) => \Livewire\Livewire::actingAs($manager)->test(
    \Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\EditConfiguration::class, ['record'=>$template->id]);
$draft=$edit($default)->set('data.name','Preview name');
$document = new DOMDocument;
@$document->loadHTML($draft->html());
$xpath = new DOMXPath($document);
$buttons = $xpath->query('//button[@type="submit"]');
same(1, $buttons->length, 'The editor has one submit button');
$button = $buttons->item(0);
$form = $button?->hasAttribute('form') ? $document->getElementById($button->getAttribute('form'))
    : ($button ? $xpath->query('ancestor::form[1]', $button)->item(0) : null);
same('save', $form?->getAttribute('wire:submit'), 'The visible submit button submits the editor form');
$draft->set('data.nameservers', "ns1.preview.test\nns2.preview.test");
same('Yes', panelRowValue($draft->html(),'New registrations'), 'Editing the default nameservers affects new registrations');
$edit($default)->set('data.nameservers', "ns1.edited.test\nns2.edited.test")->call('save')->assertHasNoErrors();
same(['ns1.edited.test','ns2.edited.test'],SiteDnsDefault::profile()->nameservers,'Editing the selected default changes its nameservers');
$stale = $edit($default)->set('data.nameservers', "ns1.stale.test\nns2.stale.test");
SiteDnsDefault::save($manager,$replacement->id);
$before=[WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),DB::table('domains_watch_staff_claims')->count()];
$stale->call('save')->assertNoRedirect();
same($before,[WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),DB::table('domains_watch_staff_claims')->count()],'Changed default refuses a stale edit without updating either template');
SiteDnsDefault::save($manager,$default->id);
\Livewire\Livewire::actingAs($manager)->test($settingsPage)->assertSee('ns1.edited.test, ns2.edited.test');
$default=$default->fresh();
// Connected registrar rules also apply while processing is paused. No provider verification or send is faked here:
// privileged immutable bindings supply only the native relationship used by these local policy writes.
$connect=static function(string $key):void {
    $s=\App\Models\Server::create(['name'=>'Synthetic '.$key,'extension'=>'Domains','type'=>'server','enabled'=>true]);
    \Paymenter\Extensions\Servers\Domains\Models\Account::withVerifiedBindingWrite(fn()=>\Paymenter\Extensions\Servers\Domains\Models\Account::create([
        'server_id'=>$s->id,'registrar_key'=>$key,'environment'=>'test','stable_identity'=>'synthetic-'.$key,'processing_enabled'=>false]));
};
$servers=static fn(int $count)=>array_map(static fn($n)=>'ns'.$n.'.registrar.test',range(1,$count));
$make=static fn(int $count)=>DB::transaction(fn()=>WatchProfile::create(['name'=>'Count '.$count,'enabled'=>true,'nameservers'=>$servers($count),
    'edited_by'=>$manager->id,'reason'=>'Synthetic registrar limit']));
$refuse=static function(Closure $write,string $rule):void {
    $before=[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()];
    $error=null;try{$write();}catch(DomainException $e){$error=$e->getMessage();}
    expect($error!==null && str_contains($error,$rule),'Save refuses with the registrar rule: '.$rule);
    same($before,[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()],'Refused save preserves selection, templates and audit');
};
$connect('namesilo');$one=$make(1);$thirteen=$make(13);
// The chooser offers only templates every connected registrar accepts, each with its nameservers; reserved test names are marked.
$options=\Paymenter\Extensions\Servers\Domains\Admin\Support\DefaultNameservers::options();
expect(!isset($options[$one->id]),'The chooser leaves out a template a connected registrar refuses');
same('Count 13: '.implode(', ',$thirteen->fresh()->nameservers).' (test names, not for real domains)',$options[$thirteen->id]??null,'A choice shows its nameservers and marks reserved test names');
expect(!\Paymenter\Extensions\Servers\Domains\Admin\Support\DefaultNameservers::testNames(['nameserver-placeholder.com','nameserver-placeholder-2.com']),'Real names are not marked');
// A template left out of the list is refused in the dialog, before anything is saved.
$pageBefore=[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()];
\Livewire\Livewire::actingAs($manager)->test($page)->callAction('siteDefault',['profile'=>$one->id])
    ->assertSuccessful()->assertHasActionErrors(['profile'])->assertNotNotified('Default nameservers saved');
same($pageBefore,[SiteDnsDefault::profile()->id,WatchProfile::orderBy('id')->get()->toJson(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count()],'Page refusal preserves selection, templates and audit');
$refuse(fn()=>SiteDnsDefault::save($manager,$one->id),'NameSilo takes 2 to 13');
SiteDnsDefault::save($manager,$thirteen->id);same(13,count(SiteDnsDefault::profile()->nameservers),'NameSilo accepts thirteen');
$refuse(fn()=>DB::transaction(fn()=>$thirteen->fresh()->update(['nameservers'=>$servers(1),'revision'=>$thirteen->revision+1])),'NameSilo takes 2 to 13');
SiteDnsDefault::save($manager,$default->id);$connect('enom');
$refuse(fn()=>SiteDnsDefault::save($manager,$one->id),'Enom takes 2 to 12');
$refuse(fn()=>SiteDnsDefault::save($manager,$thirteen->id),'Enom takes 2 to 12');
foreach([1,13]as$count){$refuse(fn()=>DB::transaction(fn()=>$default->fresh()->update(['nameservers'=>$servers($count),'revision'=>$default->revision+1,'edited_by'=>$manager->id,'reason'=>'Synthetic staff limit edit'])),'Enom takes 2 to 12');}
$twelve=$make(12);SiteDnsDefault::save($manager,$twelve->id);same(12,count(SiteDnsDefault::profile()->nameservers),'Both connected registrars accept twelve');
DB::transaction(fn()=>$twelve->fresh()->update(['nameservers'=>$servers(2),'revision'=>$twelve->revision+1]));
same(2,count(SiteDnsDefault::profile()->nameservers),'Both connected registrars accept editing to two');
// Hostname refusals are not server-count failures; no saved default means create/select recovery.
try{SiteDnsDefault::validateRegistrars(['invalid host','ns2.example.test']);expect(false,'Invalid hostname refused');}
catch(DomainException $e){expect(str_contains(strtolower($e->getMessage()),'hostname')&&!str_contains($e->getMessage(),'takes 2 to'),'Hostname refusal explains the relevant rule');}
fixtureClearBusinessChoice(SiteDnsDefault::KEY);DB::table('domains_watch_profiles')->update(['enabled'=>false]);
config(['domains.site_dns_default.nameservers'=>null]);
\Livewire\Livewire::actingAs($viewer)->test($page)->assertSee('Ask staff who can change the default nameservers.')->assertDontSee('Choose a template in Domains > DNS templates');
$empty=\Livewire\Livewire::actingAs($manager)->test($page)->mountAction('siteDefault')->call('$refresh');
expect(str_contains(html_entity_decode(implode('', $empty->effects['partials']??[]).$empty->html(),ENT_QUOTES),'Lists templates that are on and that every connected registrar accepts. To add one, use Add DNS template.'),'Empty selector explains which templates it lists and how to add one');
config(['domains.site_dns_default.nameservers'=>['invalid host','ns2.example.test']]);
expect(str_contains((string)SiteDnsDefault::refusal(),'Create and turn on a compatible DNS template'),'An unusable deployment seed does not tell staff to edit a nonexistent saved template');
// Too many valid deployment hosts is a count problem, not a hostname problem; registrar limits still apply below it.
$hosts=static fn(int $count)=>array_map(static fn($n)=>'ns'.$n.'.deployment.test',range(1,$count));
config(['domains.site_dns_default.nameservers'=>$hosts(14)]);$limit=(string)SiteDnsDefault::refusal();
expect(str_contains($limit,'more than 13 nameservers')&&!str_contains(strtolower($limit),'hostname'),'Fourteen valid deployment hosts report the 13-host limit: '.$limit);
expect(str_contains($limit,'Create and turn on a compatible DNS template, then choose it for the default nameservers.'),'The limit keeps template-selection recovery');
config(['domains.site_dns_default.nameservers'=>[...$hosts(13),'bad host']]);
expect(str_contains((string)SiteDnsDefault::refusal(),'hostnames are invalid'),'A malformed host among many is still a hostname problem');
config(['domains.site_dns_default.nameservers'=>$hosts(13)]);
expect(str_starts_with((string)SiteDnsDefault::refusal(),'Enom takes 2 to 12 nameservers.'),'Thirteen valid hosts keep the connected Enom limit');
config(['domains.site_dns_default.nameservers'=>$hosts(12)]);same(null,SiteDnsDefault::refusal(),'Twelve valid hosts suit both connected registrars');
// Read-only staff keep the cause and are sent to staff who can change the default.
config(['domains.site_dns_default.nameservers'=>$hosts(14)]);
\Livewire\Livewire::actingAs($viewer)->test($page)->assertSee('Deployment DNS default lists more than 13 nameservers.')->assertSee('Ask staff who can change the default nameservers.')->assertDontSee('Create and turn on a compatible DNS template');
\Livewire\Livewire::actingAs($manager)->test($page)->assertSee('Create and turn on a compatible DNS template')->assertDontSee('Ask staff who can change the default nameservers.');
DB::table('domains_watch_profiles')->where('id',$thirteen->id)->update(['enabled'=>true]);
fixtureBusinessChoice(SiteDnsDefault::KEY,(string)$thirteen->id,'string');
same('Enom takes 2 to 12 nameservers. Update this DNS template or choose another one.',SiteDnsDefault::refusal(),'Editors are told to update or replace a saved default');
\Livewire\Livewire::actingAs($viewer)->test($page)->assertSee('Enom takes 2 to 12 nameservers. Ask staff who can change the default nameservers.')->assertDontSee('Update this DNS template or choose another one.');
$nativeSuccessful=true;echo "Site DNS installation default passed.\n";
