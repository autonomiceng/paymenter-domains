<?php
// Staff navigation, the Domain monitoring Settings tab and its maintenance notice. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{ActivationStatus,DnsResolver,PublicSuffixList,RegistrarAccount,RegistrarBalances,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource,PendingAdoptionResource,ProductMappingResource,RoutingProfileResource,TldResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,MonitoringSettings};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Services\{DnsResolverSettings,StaffWatchWrites as Writes,WatchMaintenance,WatchWork};
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();
$resources=[WatchResource::class,ProductMappingResource::class,RoutingProfileResource::class,\App\Admin\Resources\FailedJobResource::class];$pages=[DnsResolver::class,PublicSuffixList::class];$panel->resources($resources)->pages($pages);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$pages,$panel){foreach([...$resources,...$pages] as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();

// Seven pages in one Domains group, in the planned order; every other page leaves the menu but keeps its route.
$all=[DomainResource::class,OperationResource::class,TldResource::class,RegistrarSetup::class,WatchResource::class,RoutingProfileResource::class,ProductMappingResource::class,
    PendingAdoptionResource::class,RegistrarAccount::class,RegistrarBalances::class,ActivationStatus::class,PublicSuffixList::class,DnsResolver::class];
$menu=array_values(array_filter($all,fn($c)=>$c::shouldRegisterNavigation()));usort($menu,fn($a,$b)=>[$a::getNavigationSort()??PHP_INT_MAX]<=>[$b::getNavigationSort()??PHP_INT_MAX]);
same(['Domains','Registrar requests','TLDs & pricing','Registrars','Domain monitoring','DNS templates','Hosting products'],array_map(fn($c)=>$c::getNavigationLabel(),$menu),'Seven staff pages in order');
foreach($menu as $c){same('Domains',$c::getNavigationGroup(),'One Domains group: '.$c);}
same(['domains','registrar-requests','domain-tlds','registrar-setup','domain-watch','dns-routing-profiles','product-domain-mappings','public-suffix-list','dns-resolver'],
    [DomainResource::getSlug(),OperationResource::getSlug(),TldResource::getSlug(),RegistrarSetup::getSlug(),WatchResource::getSlug(),RoutingProfileResource::getSlug(),ProductMappingResource::getSlug(),PublicSuffixList::getSlug(),DnsResolver::getSlug()],'Slugs kept');

// The old DNS resolver page redirects to the Settings tab; the public suffix list keeps its own page.
$viewer=staffUser([Staff::VIEW,Writes::VIEW]);$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);Auth::setUser($manager);
same(true,str_ends_with(WatchResource::getUrl('settings'),'/domain-watch/settings'),'Settings tab URL');
Livewire::actingAs($manager)->test(DnsResolver::class)->assertRedirect(WatchResource::getUrl('settings'));
Livewire::actingAs($manager)->test(PublicSuffixList::class)->assertSuccessful();
Livewire::actingAs(staffUser([Staff::VIEW]))->test(MonitoringSettings::class)->assertForbidden();

// Tabs: Domains and Settings on both pages.
$tabs=fn($page)=>array_map(fn(NavigationItem $i)=>[$i->getLabel(),$i->isActive()],$page->instance()->getSubNavigation());
$list=Livewire::actingAs($manager)->test(ListWatches::class)->assertSuccessful();same([['Domains',true],['Settings',false]],$tabs($list),'List tabs');
$settings=app(DnsResolverSettings::class);
$tab=Livewire::actingAs($manager)->test(MonitoringSettings::class)->assertSuccessful();same([['Domains',false],['Settings',true]],$tabs($tab),'Settings tabs');
// No saved resolver: Missing with its cause and the fix beside it, said once; nothing to test yet, no bootstrap IP.
$once=fn($test,string $copy)=>same(1,substr_count(html_entity_decode(strip_tags($test->html()),ENT_QUOTES),$copy),'Said once: '.$copy);
$tab->assertSee('DNS resolver')->assertSee('Resolver')->assertSee('Missing')->assertSee("No resolver set. Checks use the server's resolver.")->assertDontSee('Server default')->assertDontSee('Bootstrap IP')->assertSee("If the resolver fails, checks retry once with the server's resolver.")
    ->assertActionHidden('configure')->assertActionHidden('check')->assertDontSee('Test resolver')
    ->assertDontSee('Resolver check')->assertDontSee('Last check this visit')->assertDontSee('DNS-over-HTTPS configured')->assertDontSee('Public domain checks');
$once($tab,'Edit resolver');
Livewire::actingAs($viewer)->test(MonitoringSettings::class)->assertSuccessful()->assertActionHidden('configure')->assertActionHidden('check')->assertDontSee('Edit resolver')->assertSee('Missing');
$edit=\Filament\Actions\Testing\TestAction::make('edit')->schemaComponent('resolver','content');
$modal=fn($test)=>html_entity_decode($test->html().implode('',$test->effects['partials']??[]),ENT_QUOTES);
$invalid=Livewire::actingAs($manager)->test(MonitoringSettings::class)->mountAction($edit)->setActionData(['url'=>'http://resolver-placeholder.org','ip'=>'127.0.0.1'])->callMountedAction()->assertHasActionErrors(['url','ip']);
foreach(['Use an HTTPS URL with a public hostname.','Use a public IPv4 or IPv6 address.'] as $text){expect(str_contains($modal($invalid),$text),'Field error: '.$text);}
$form=Livewire::actingAs($manager)->test(MonitoringSettings::class)->mountAction($edit);
foreach(['Edit resolver','Resolver URL (DNS over HTTPS)','Default: '.DohEndpoint::URL,'Checked domain names are sent to this resolver.','Bootstrap IP','Default: '.DohEndpoint::IP.". The resolver's public IP."] as $text){expect(str_contains($modal($form),$text),'Form copy: '.$text);}
$form->setActionData(['url'=>DohEndpoint::URL,'ip'=>DohEndpoint::IP])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Resolver saved');
// Saved: the header holds Edit and Test; no Missing.
$saved=Livewire::actingAs($manager)->test(MonitoringSettings::class)->assertSee('cloudflare-dns.com')->assertSee('Bootstrap IP')->assertSee(DohEndpoint::IP)->assertDontSee('Server default')->assertDontSee('Missing')
    ->assertActionVisible('configure')->assertActionVisible('check');
$once($saved,'Edit resolver');
// Unreadable saved settings: Missing with its cause and the fix next to it.
\App\Models\Setting::where('key',DnsResolverSettings::KEY)->update(['value'=>'{']);
Livewire::actingAs($manager)->test(MonitoringSettings::class)->assertSee('Missing')->assertSee("Saved settings can't be read.")->assertDontSee('Server default');
// The server cannot use a custom resolver: the error notice and the server default, not Missing.
putenv('SSLKEYLOGFILE=/synthetic-disabled');Livewire::actingAs($manager)->test(MonitoringSettings::class)->assertSee('Turn off SSLKEYLOGFILE on the server to use a custom resolver.')->assertSee('Server default')->assertDontSee('Missing');putenv('SSLKEYLOGFILE');

// Maintenance notice, one line per state; time in staff format.
$since=1800000000;$at='Jan 15, 2027 08:00 UTC';
same(null,WatchMaintenance::notice(['outage'=>false,'reason'=>'waiting','since'=>null]),'Waiting has no notice');
same(null,WatchMaintenance::notice(['outage'=>false,'reason'=>'current','since'=>$since]),'Current has no notice');
same(['text'=>'Domain monitoring is off on this site.','tone'=>'warning','action'=>null],WatchMaintenance::notice(['outage'=>false,'reason'=>'disabled','since'=>null]),'Disabled');
same(['text'=>'Monitoring checks have not run since '.$at.'.','tone'=>'danger','action'=>'failed_jobs'],WatchMaintenance::notice(['outage'=>true,'reason'=>'dispatch_overdue','since'=>$since]),'Overdue');
same(['text'=>'Expiry checks have failed since '.$at.'.','tone'=>'danger','action'=>null],WatchMaintenance::notice(['outage'=>true,'reason'=>'checks_unsuccessful','family'=>'registry','families'=>['registry'],'since'=>$since]),'Expiry checks');
same(['text'=>'DNS checks have failed since '.$at.'.','tone'=>'danger','action'=>'settings'],WatchMaintenance::notice(['outage'=>true,'reason'=>'checks_unsuccessful','family'=>'watch_dns','families'=>['watch_dns','managed_dns'],'since'=>$since]),'DNS checks named once');
same(['text'=>'Expiry checks and DNS checks have failed since '.$at.'.','tone'=>'danger','action'=>'settings'],WatchMaintenance::notice(['outage'=>true,'reason'=>'checks_unsuccessful','family'=>'registry','families'=>['registry','watch_dns'],'since'=>$since]),'Two families');
same(['text'=>'Domain discovery has failed since '.$at.'.','tone'=>'danger','action'=>'failed_jobs'],WatchMaintenance::notice(['outage'=>true,'reason'=>'checks_unsuccessful','family'=>'local','families'=>['local'],'since'=>$since]),'Discovery');
expect(str_contains(WatchMaintenance::notice(['outage'=>true,'reason'=>'queue_configuration','since'=>null])['text'],'Queue timeout too short'),'Queue configuration names the cause');
// The list shows the notice above the table with its one action.
$clock=(object)['now'=>$since];app()->instance(WatchWork::class,new WatchWork(new \Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]),new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null),true,fn()=>$clock->now));
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>180]);
DB::table('domains_watch_schedule')->where('scope','dispatch')->delete();DB::table('domains_watch_schedule')->insert(['scope'=>'dispatch','due_at'=>$since,'progress_at'=>$since-86400,'data'=>json_encode(['started_at'=>$since-86400])]);
// The Failed jobs button shows only to staff who can open that page.
Livewire::actingAs($viewer)->test(ListWatches::class)->assertSee('Monitoring checks have not run since Jan 14, 2027 08:00 UTC.')->assertDontSee('Open Failed jobs');
$manager->role->update(['permissions'=>[...$manager->role->permissions,'admin.failed_jobs.viewAny']]);
$overdue=Livewire::actingAs($manager)->test(ListWatches::class)->assertSee('Monitoring checks have not run since Jan 14, 2027 08:00 UTC.')->assertSee('Open Failed jobs')->assertDontSee('Open Settings')->assertCanSeeTableRecords([$watch]);
$text=html_entity_decode(strip_tags($overdue->html()));expect(!str_contains($text,'—'),'No em dash');foreach(['unavailable','Public domain checks','scheduler'] as $word){expect(stripos($text,$word)===false,'No "'.$word.'" on the list');}
$nativeSuccessful=true;echo "Staff navigation, Settings tab and monitoring notice passed.\n";
