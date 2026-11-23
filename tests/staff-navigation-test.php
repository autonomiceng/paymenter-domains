<?php
// Staff navigation, the one Domains area with its tabs, the Monitoring settings tab and its maintenance notice. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/doh-environment.php';
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,DnsResolver,RegistrarAccount,RegistrarBalances,RegistrarSetup,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource,PendingAdoptionResource,RoutingProfileResource,TldResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Services\{DnsResolverSettings,StaffWatchWrites as Writes};
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';extract(dnsFixture());\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();
$resources=[TldResource::class,RoutingProfileResource::class,\App\Admin\Resources\FailedJobResource::class];$pages=[DnsResolver::class,RegistrarSetup::class,WhatYouSell::class];$panel->resources($resources)->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$pages,$panel){foreach([...$resources,...$pages] as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();

// Three pages in one Domains group, in the planned order; monitoring and DNS templates are tabs of Domains (Ilya, decision 1).
// Every other page leaves the menu but keeps its route.
$all=[DomainResource::class,OperationResource::class,TldResource::class,RegistrarSetup::class,RoutingProfileResource::class,
    PendingAdoptionResource::class,RegistrarAccount::class,RegistrarBalances::class,ActivationStatus::class,DnsResolver::class];
same([DomainResource::class,TldResource::class,RegistrarSetup::class],array_values(array_filter($all,fn($c)=>$c::shouldRegisterNavigation())),'Monitoring and DNS templates have no menu item of their own');
$menu=array_values(array_filter($all,fn($c)=>$c::shouldRegisterNavigation()));usort($menu,fn($a,$b)=>[$a::getNavigationSort()??PHP_INT_MAX]<=>[$b::getNavigationSort()??PHP_INT_MAX]);
same([DomainResource::class,RegistrarSetup::class,TldResource::class],$menu,'Three staff pages in order');
foreach($menu as $c){same('Domains',$c::getNavigationGroup(),'One Domains group: '.$c);}
// Resource breadcrumbs read like the menu, not Filament's title-cased plural label.
foreach(array_filter($all,fn($c)=>is_subclass_of($c,\Filament\Resources\Resource::class)) as $c){same($c::getNavigationLabel(),$c::getBreadcrumb(),'Sentence-case breadcrumb: '.$c);}
same(count($menu),count(array_unique(array_map(fn($c)=>$c::getNavigationIcon(),$menu))),'Each staff page has its own icon');
// Current staff page addresses.
same(['domains','registrar-requests','tlds','registrars','dns-templates','import-pending','dns-resolver'],
    [DomainResource::getSlug(),OperationResource::getSlug(),TldResource::getSlug(),RegistrarSetup::getSlug(),RoutingProfileResource::getSlug(),PendingAdoptionResource::getSlug(),DnsResolver::getSlug()],'Slugs name their pages');
Auth::setUser(staffUser([Staff::VIEW,Staff::COSTS]));
same(2,count(\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::tldCatalogue('All TLDs',[])),'Pricing has two views');


$viewer=staffUser([Staff::VIEW,Writes::VIEW]);$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);Auth::setUser($manager);
Livewire::actingAs(staffUser([Staff::VIEW]))->test(DnsResolver::class)->assertForbidden();

// One Domains area (Ilya, decision 1): every tab page draws the same tab row, each tab opens its own unchanged URL, and the
// page a URL opens marks its own tab, so a reload or a bookmark lands on the same tab.
$tabs=fn($page)=>array_map(fn(NavigationItem $i)=>[$i->getUrl(),$i->isActive()],$page->instance()->getSubNavigation());
$area=[DomainResource::getUrl('index'),RoutingProfileResource::getUrl('index'),DnsResolver::getUrl()];
same(['/admin/domains','/admin/dns-templates','/admin/dns-resolver'],array_map(fn($u)=>parse_url($u,PHP_URL_PATH),$area),'Tab URLs name their tabs');
$open=['index'=>\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains::class,'templates'=>RoutingProfileResource\Pages\ListConfiguration::class,'settings'=>DnsResolver::class];
foreach(array_values($open) as $n=>$page){
    $test=Livewire::actingAs($manager)->test($page)->assertSuccessful();
    same(array_map(fn($u,$i)=>[$u,$i===$n],$area,array_keys($area)),$tabs($test),'One tab row, its own tab marked: '.$page);
    same(1,count(array_filter($test->instance()->getSubNavigation(),fn($item)=>$item->isActive())),'Exactly one destination selected');
    // Tab pages carry no breadcrumb, like Domains and Registrars.
    same([],$test->instance()->getBreadcrumbs(),'No breadcrumb: '.$page);
}
same([],\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::domains('outside'),'A destination outside the tabs has no tab row');
$marked=fn(string $route)=>collect((array)DomainResource::getNavigationItemActiveRoutePattern())->contains(fn($p)=>\Illuminate\Support\Str::is($p,$route));
expect($marked(RoutingProfileResource::getRouteBaseName().'.index')&&!$marked(TldResource::getRouteBaseName().'.index'),'Domains marked on DNS templates only, not on TLDs');
// Permissions stay per tab: staff who can't read monitoring see Domains alone, so no tab row.
same([],Livewire::actingAs(staffUser([Staff::VIEW]))->test($open['index'])->instance()->getSubNavigation(),'No monitoring rights, no tab row');
$settings=app(DnsResolverSettings::class);
$tab=Livewire::actingAs($manager)->test(DnsResolver::class)->assertSuccessful();
// No saved resolver: Missing with its cause and the fix beside it, said once; nothing to test yet, no bootstrap IP.
$once=fn($test,string $copy)=>same(1,substr_count(html_entity_decode(strip_tags($test->html()),ENT_QUOTES),$copy),'Said once: '.$copy);
$tab->assertSee('DNS resolver')->assertSee('Resolver')->assertSee(\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label())->assertSee("No resolver set. Checks use the server's resolver.")->assertDontSee('Server default')->assertDontSee('Resolver IP address')->assertSee('If the resolver fails, checks retry once with the server')
    ->assertActionHidden('configure')->assertActionHidden('check')->assertDontSee('Test resolver')
    ->assertDontSee('Resolver check')->assertDontSee('Last check this visit')->assertDontSee('DNS-over-HTTPS configured')->assertDontSee('Public domain checks');
$once($tab,'Edit resolver');
Livewire::actingAs($viewer)->test(DnsResolver::class)->assertSuccessful()->assertActionHidden('configure')->assertActionHidden('check')->assertDontSee('Edit resolver')->assertSee(\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label());
$edit=\Filament\Actions\Testing\TestAction::make('edit')->schemaComponent('sheet.sec-dns-resolver.row-resolver.row-resolver-actions','content');
$modal=fn($test)=>html_entity_decode($test->html().implode('',$test->effects['partials']??[]),ENT_QUOTES);
$invalid=Livewire::actingAs($manager)->test(DnsResolver::class)->mountAction($edit)->setActionData(['url'=>'http://resolver.example.org','ip'=>'127.0.0.1'])->callMountedAction()->assertHasActionErrors(['url','ip']);
foreach(['Use an HTTPS URL with a public hostname.','Use a public IPv4 or IPv6 address.'] as $text){expect(str_contains($modal($invalid),$text),'Field error: '.$text);}
$form=Livewire::actingAs($manager)->test(DnsResolver::class)->mountAction($edit);
foreach(['Edit resolver','Resolver URL (DNS over HTTPS)','Default: '.DohEndpoint::URL,'Checked domain names are sent to this resolver.','Resolver IP address','Default: '.DohEndpoint::IP.". The resolver's public IP."] as $text){expect(str_contains($modal($form),$text),'Form copy: '.$text);}
$form->setActionData(['url'=>DohEndpoint::URL,'ip'=>DohEndpoint::IP])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Resolver saved');
// Saved: the header holds Edit and Test; no Missing.
$saved=Livewire::actingAs($manager)->test(DnsResolver::class)->assertSee('cloudflare-dns.com')->assertSee('Resolver IP address')->assertSee(DohEndpoint::IP)->assertDontSee('Server default')->assertSee('Default nameservers')
    ->assertActionVisible('configure')->assertActionVisible('check');
$once($saved,'Edit resolver');
expect(!str_contains(resolverSectionText($saved->html()),\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label()),'Saved resolver has no missing state in its section');
// Unreadable saved settings: Missing with its cause and the fix next to it.
\App\Models\Setting::where('key',DnsResolverSettings::KEY)->update(['value'=>'{']);
Livewire::actingAs($manager)->test(DnsResolver::class)->assertSee(\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label())->assertSee("Saved settings can't be read.")->assertDontSee('Server default')->assertActionHidden('configure')->assertActionHidden('check')->assertSee('Edit resolver');
// The server cannot use a custom resolver: the error notice and the server default, not Missing.
putenv('SSLKEYLOGFILE=/synthetic-disabled');$forced=Livewire::actingAs($manager)->test(DnsResolver::class)->assertSee('Turn off SSLKEYLOGFILE on the server to use a custom resolver.')->assertSee('Server default')->assertSee('Default nameservers')->assertActionHidden('configure')->assertActionHidden('check')->assertDontSee('Edit resolver');expect(!str_contains(resolverSectionText($forced->html()),\Paymenter\Extensions\Servers\Domains\Presentation\State::NotSet->label()),'Forced server resolver has no missing state in its section');putenv('SSLKEYLOGFILE');
$GLOBALS['domainsTestCurlMissing']=true;
same([null,'Install the PHP cURL extension to use a custom resolver.',true],$settings->configuration(),'Missing PHP cURL forces the server resolver');
Livewire::actingAs($manager)->test(DnsResolver::class)->assertSee('Install the PHP cURL extension to use a custom resolver.')->assertSee('Server default')->assertActionHidden('configure')->assertActionHidden('check')->assertDontSee('Edit resolver');
$GLOBALS['domainsTestCurlMissing']=false;

$nativeSuccessful=true; echo "Staff navigation and DNS resolver passed.\n";
