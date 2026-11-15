<?php
// Hosting products on Monitoring settings (Ilya, Nov 15, 2026): a product is in scope when its orders carry a domain (domain, else hostname), with or without a server; it is monitored unless staff turn it off. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/doh-environment.php';
require __DIR__.'/Support/watch-notices.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{ProductMonitoring,Staff};
use Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader;
use Paymenter\Extensions\Servers\Domains\Models\WatchProductMap;
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchSchema,StaffWatchWrites as Writes,WatchDiscoveryWork,WatchMappings,WatchProducts,WatchWork};
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}PublicSuffixList::registerRoutes($panel);});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);$viewer=staffUser([Staff::VIEW,Writes::VIEW]);Auth::setUser($manager);
$made=0;
$hosting=function(string $name,?int $serverId=null)use($product,&$made){$copy=$product->replicate();$copy->name=$name;$copy->slug='product-'.++$made;$copy->server_id=$serverId??$product->server_id;$copy->hidden=false;$copy->save();return $copy;};
$saved=function($on,array $values)use($service){$copy=$service->replicate();$copy->product_id=$on->id;$copy->save();foreach($values as $key=>$value){$copy->properties()->create(['key'=>$key,'value'=>$value]);}return $copy;};
$stray=fn($on,bool $enabled)=>DB::table('domains_watch_product_maps')->insert([...WatchProductMap::find($map->id)->getRawOriginal(),'id'=>null,'product_id'=>$on->id,'property_key'=>'hosting_ready','enabled'=>$enabled]);
$mapOf=fn($on)=>WatchProductMap::where('product_id',$on->id)->first();$state=fn($on)=>($m=$mapOf($on))?[$m->property_key,(bool)$m->enabled]:null;
$failed=[];$check=function(bool $ok,string $message)use(&$failed){if(!$ok){$failed[]=$message;}};
config(['queue.default'=>'database','queue.connections.database'=>['driver'=>'database','retry_after'=>180]]);
$work=fn(int $at)=>new WatchWork(new NativeDnsReader(static fn()=>[]),new WatchDiscoveryWork(static fn()=>null),true,static fn()=>$at,static fn()=>null);

$serverless=$hosting('Serverless');$saved($serverless,['domain'=>'shop.example.org']);DB::table('products')->where('id',$serverless->id)->update(['server_id'=>null]);
$both=$hosting('Both');$saved($both,['domain'=>'shop.example.com','hostname'=>'box1.example.net']);
$hostname=$hosting('Hostname');$saved($hostname,['hostname'=>'site.example.net']);
$none=$hosting('No domain');$saved($none,['plan'=>'basic']);
$catalogue=$hosting('Sold domains');$saved($catalogue,['domain'=>'sold.example.com']);\App\Models\Setting::withoutEvents(fn()=>$catalogue->settings()->create(['key'=>'domains_catalogue','value'=>'1']));
$registrar=$hosting('Domain server',\App\Models\Server::create(['name'=>'Registrar','extension'=>'Domains','type'=>'server','enabled'=>true])->id);
$fixed=$hosting('W9 ready');$saved($fixed,['hosting_ready'=>'yes','domain'=>'ready.example.com']);$stray($fixed,true);
$fixedOff=$hosting('W9 off');$saved($fixedOff,['hosting_ready'=>'yes','domain'=>'off.example.com']);$stray($fixedOff,false);
$work(1800000000)->tick();$work(1800000300)->tick();
// 1. Listing: orders that carry a domain are in scope, with or without a server; domain registrations never are.
$check($state($serverless)===['domain',true],'1: a hosting product with no server whose orders carry a domain is monitored');
$check($state($hostname)===['hostname',true],'1: orders with only a hostname are monitored by hostname');
$check($mapOf($catalogue)===null&&$mapOf($registrar)===null&&$mapOf($none)===null,'1: registrations and products with no domain get no map');
// 2. Default on: in scope means monitored with no staff step; staff off stays off; a map on another field moves to the convention.
$check($state($both)===['domain',true],'2: a product whose orders carry a domain and a hostname is monitored by domain, not left for a person');
$check($state($fixed)===['domain',true],'2: a monitored map on a non-domain field is corrected to domain');
$check($state($fixedOff)===['domain',false],'2: a turned-off map on a non-domain field is corrected and stays off');
// 3. No field picker: the server refuses any field but the convention, from either save path.
$refused=function(callable $write){try{$write();return false;}catch(\DomainException){return true;}};
$check($refused(fn()=>(new WatchMappings())->save($manager,(int)$none->id,'plan',0,'Staff pick')),'3: saving a mapping on another field is refused');
$other=$hosting('Other');$saved($other,['plan'=>'pro']);
$check($refused(fn()=>(new Writes())->run($manager,'mapping',0,'save',Writes::ticket((int)$manager->id,'mapping',0,0,'save'),'Domain field chosen',['product_id'=>$other->id,'property_key'=>'plan'])),'3: the staff field save is refused');
$check($mapOf($none)===null&&$mapOf($other)===null,'3: a refused field saves nothing');
expect($failed===[],"Regressions failed:\n".implode("\n",$failed));

// The page: one summary row; Choose products saves only exceptions, and the product edit switch reads and writes the same list.
app()->instance(WatchWork::class,$work(1800000600));
$page=fn($user=null)=>Livewire::actingAs($user??$manager)->test(MonitoringSettings::class)->assertSuccessful();
$lines=WatchProducts::lines();$names=array_column($lines['products'],'name');
expect(in_array('Serverless',$names,true)&&!array_intersect(['Sold domains','Domain server','No domain'],$names),'Only hosting products whose orders carry a domain are listed');
expect(in_array('No domain',$lines['unlisted'],true)&&!array_intersect(['Sold domains','Domain server'],$lines['unlisted']),'Hosting products with no domain are named as not listed; registrations are not');
$html=$page()->html();expect(str_contains($html,'Choose products')&&!str_contains($html,'chooseField')&&!str_contains($html,'productSwitch'),'Managers get Choose products and no field picker');
expect(!str_contains($html,'box1.example.net'),'No customer value on the page');
expect(!str_contains($page($viewer)->html(),'Choose products'),'View-only staff get no Choose products');
$new=$hosting('Not mapped yet');$saved($new,['domain'=>'new.example.com']);
$open=$page();$ids=array_column(WatchProducts::lines()['products'],'id');$loaded=array_column(array_filter(WatchProducts::lines()['products'],fn($p)=>!$p['on']),'id');
// A product gets its first domain order while the dialog is open: it was never offered, so saving leaves it on.
$late=$hosting('Ordered while the dialog was open');$saved($late,['domain'=>'late.example.com']);
$open->call('saveMonitored',array_values(array_diff($ids,[$both->id,$new->id],$loaded)),$loaded,$ids);
same([['domain',false],['domain',false],['domain',true],['domain',false]],[$state($both),$state($new),$state($serverless),$state($fixedOff)],'Unchecked products are saved off, a product not yet mapped included; the rest keep their choice');
same(null,$state($late),'A product that entered the list after the dialog opened is not turned off');
$page()->call('saveMonitored',$ids,$loaded,$ids);same(['domain',false],$state($both),'A dialog opened before the last save changes nothing');
$page($viewer)->call('saveMonitored',$ids,[$both->id,$new->id,$fixedOff->id],$ids)->assertForbidden();same(['domain',false],$state($both),'View-only staff change nothing');
$switch=fn($user=null)=>Livewire::actingAs($user??$manager)->test(ProductMonitoring::class,['product'=>$both->id]);
expect(str_contains($switch()->html(),'aria-checked="false"'),'The product edit switch shows a product turned off in Choose products as off');
$switch()->call('toggle');same(['domain',true],$state($both),'The switch turns monitoring back on');
expect(WatchProducts::lines()['products'][array_search($both->id,array_column(WatchProducts::lines()['products'],'id'),true)]['on'],'Choose products shows what the switch saved');
$switch($viewer)->call('toggle')->assertForbidden();same(['domain',true],$state($both),'View-only staff cannot use the switch');
app()->forgetInstance(WatchWork::class);
expect(!str_contains($page()->html(),'Choose products'),'No product section while monitoring is off');
$nativeSuccessful=true;echo "Hosting product monitoring works.\n";
