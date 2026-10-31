<?php
// Hosting product lines on Domain monitoring: the system finds the domain field, staff only switch. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{RoutingProfileResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ListWatches;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader;
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchProductMap};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchSchema,StaffWatchWrites as Writes,WatchDiscoveryWork,WatchProducts,WatchWork};
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}PublicSuffixList::registerRoutes($panel);});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);$viewer=staffUser([Staff::VIEW,Writes::VIEW]);Auth::setUser($manager);
$made=0;
$hosting=function(string $name,?int $serverId=null)use($product,&$made){$copy=$product->replicate();$copy->name=$name;$copy->slug='product-'.++$made;$copy->server_id=$serverId??$product->server_id;$copy->save();return $copy;};
$saved=function($on,array $values,array $labels=[])use($service){$copy=$service->replicate();$copy->product_id=$on->id;$copy->save();foreach($values as $key=>$value){$copy->properties()->create(['key'=>$key,'name'=>$labels[$key]??null,'value'=>$value]);}};

// Finding the field: only values services saved count, so no hosting API is called. A name needs values that fit; anything less is left to a person.
$contradicted=$hosting('Contradicted');$saved($contradicted,['hostname'=>'box1','site'=>'shop.example.com']);same(null,WatchProducts::found($contradicted),'A named field holding no domain is not picked, and neither is its neighbor');
$native=$hosting('Native',\App\Models\Server::create(['name'=>'Native','extension'=>'CPanel','type'=>'server','enabled'=>true])->id);same(null,WatchProducts::found($native),'A checkout field with no saved value is not read');
$hidden=$hosting('Hidden');$saved($hidden,['domain'=>'customer.example.org','mailhost'=>'mx.provider.test']);$real=\App\Models\Service::where('product_id',$hidden->id)->sole()->id;foreach(range(1,25) as$n){$saved($hidden,['mailhost'=>'mx.provider.test']);}
\App\Models\Service::where('product_id',$hidden->id)->where('id','>',$real)->update(['status'=>'cancelled']);same('domain',WatchProducts::found($hidden),'Active services outweigh newer cancelled ones');same(['domain'=>'domain','mailhost'=>'mailhost'],WatchProducts::fields($hidden),'Every saved field can be picked, however old');
$buried=$hosting('Buried');$saved($buried,['hostname'=>'box1']);foreach(range(1,25) as$n){$saved($buried,['hostname'=>'site'.$n.'.example.com']);}same(null,WatchProducts::found($buried),'A contradiction on an older service still blocks the pick');
$big=$hosting('Big');$saved($big,['domain'=>'shop.example.com']);$bigId=\App\Models\Service::where('product_id',$big->id)->sole()->id;$fill=fn(int $from,int $to)=>array_map(fn($chunk)=>DB::table('properties')->insert(array_map(fn($n)=>['model_type'=>$service->getMorphClass(),'model_id'=>$bigId,'key'=>'extra_'.$n,'value'=>'plain'],$chunk)),array_chunk(range($from,$to),200));
$fill(1,999);same('domain',WatchProducts::found($big),'A full read settles the field');$fill(1000,1000);same(null,WatchProducts::found($big),'Past the read limit nothing is picked');expect(array_key_exists('domain',WatchProducts::fields($big)),'Past the read limit the picker still has the field');
$named=$hosting('Named');$saved($named,['hostname'=>'shop.example.com','size'=>'large']);same('hostname',WatchProducts::found($named),'Field named hostname');
$labelled=$hosting('Labelled');$saved($labelled,['site'=>'shop.example.com','mirror'=>'mirror.example.com'],['site'=>'Domain']);same('site',WatchProducts::found($labelled),'Field labelled Domain');
$tie=$hosting('Tie');$saved($tie,['domain'=>'shop.example.com','hostname'=>'box1']);same('domain',WatchProducts::found($tie),'Saved values break a tie');
$both=$hosting('Both');$saved($both,['domain'=>'shop.example.com','hostname'=>'box1.example.net']);same(null,WatchProducts::found($both),'Values that both fit are not a guess');
$unnamed=$hosting('Unnamed');$saved($unnamed,['site'=>'SHOP.example.com.','php'=>'8.2','ip'=>'192.0.2.1','plan'=>'basic','domain_aliases'=>'alias.example.com']);same('site',WatchProducts::found($unnamed),'Only field with domain-like values, and never an alias list');
$mixed=$hosting('Mixed');$saved($mixed,['site'=>'shop.example.com']);$saved($mixed,['site'=>'not a domain']);same(null,WatchProducts::found($mixed),'One bad saved value rules a field out');
$twoLike=$hosting('Two like');$saved($twoLike,['site'=>'shop.example.com','mirror'=>'mirror.example.com']);same(null,WatchProducts::found($twoLike),'Two domain-like fields are not a guess');

// The tick maps what it found, leaves staff choices alone, and does nothing while monitoring is off.
config(['queue.default'=>'database','queue.connections.database'=>['driver'=>'database','retry_after'=>180]]);
$work=fn(bool $on,int $at)=>new WatchWork(new NativeDnsReader(static fn()=>[]),new WatchDiscoveryWork(static fn()=>null),$on,static fn()=>$at,static fn()=>null);
$mine=$hosting('Staff turned off');DB::table('domains_watch_product_maps')->insert([...WatchProductMap::find($map->id)->getRawOriginal(),'id'=>null,'product_id'=>$mine->id,'enabled'=>false]);
$off=$work(false,$now);$off->tick();same(0,WatchProductMap::where('product_id',$named->id)->count(),'Monitoring off maps nothing');
$audits=WatchAudit::where('event','mapping_found')->count();
$work(true,$now)->tick();
$row=WatchProductMap::where('product_id',$named->id)->sole();same(['hostname',true,1,0],[$row->property_key,$row->enabled,$row->revision,(int)$row->edited_by],'Found field is monitored by the system');
same(0,WatchProductMap::whereIn('product_id',[$both->id,$twoLike->id,$native->id])->count(),'No guess, no row');
expect(WatchAudit::where('event','mapping_found')->count()>$audits,'System mapping leaves history');same(null,WatchAudit::where('event','mapping_found')->where('subject_id',$row->id)->sole()->actor_id,'History has no staff actor');
$work(true,$now+300)->tick();same(false,(bool)WatchProductMap::where('product_id',$mine->id)->sole()->enabled,'A product staff turned off stays off');same(1,WatchProductMap::where('product_id',$named->id)->sole()->revision,'A mapped product is left alone');
// Each turn looks at 25 products and carries on from there, so finding fields never holds up public checks.
$batch=collect(range(1,27))->map(function($n)use($hosting,$saved){$on=$hosting('Batch '.$n);$saved($on,['domain'=>'shop'.$n.'.example.com']);return $on->id;})->all();$mapped=fn()=>WatchProductMap::whereIn('product_id',$batch)->count();
$work(true,$now+600)->tick();expect($mapped()>0&&$mapped()<=25,'One turn stops at 25 products');$work(true,$now+900)->tick();same(27,$mapped(),'The next turn carries on');

// Off site products stay out of the list; one line per hosting product with its state.
$catalogue=$hosting('Sold domains');\App\Models\Setting::withoutEvents(fn()=>$catalogue->settings()->create(['key'=>'domains_catalogue','value'=>'1']));
$registrar=$hosting('Domain server',\App\Models\Server::create(['name'=>'Registrar','extension'=>'Domains','type'=>'server','enabled'=>true])->id);
$lines=collect(WatchProducts::lines())->keyBy('name');expect(!$lines->has('Sold domains')&&!$lines->has('Domain server'),'Domains products are not hosting products');
same(['No services yet',false],[$lines['Native']['state'],$lines['Native']['pick']??false],'A product with no services has nothing to find');same(['Monitored','Not monitored','No domain found'],[$lines['Hosting']['state'],$lines['Staff turned off']['state'],$lines['Two like']['state']],'Line states');
app()->instance(WatchWork::class,$work(true,$now+1500));
Livewire::actingAs($manager)->test(ListWatches::class)->assertSeeInOrder(['Hosting products','Both','No domain found','Choose a field','Hosting','Monitored','Staff turned off','Not monitored'])->assertDontSee('Sold domains')->assertDontSee('box1.example.net');
$readOnly=Livewire::actingAs($viewer)->test(ListWatches::class)->assertSee('Monitored')->assertDontSee('Choose a field');expect(!str_contains($readOnly->html(),'role="switch"'),'View-only staff get no switch');

// Permission is read once for the section, so more products never mean more permission queries.
$queries=function()use($manager){DB::flushQueryLog();DB::enableQueryLog();Livewire::actingAs($manager)->test(ListWatches::class);$count=count(DB::getQueryLog());DB::disableQueryLog();return $count;};
$queries();$few=$queries();foreach(range(1,10) as$more){$saved($hosting('Query growth '.$more),[]);}same($few,$queries(),'Ten more products add no queries');

// The switch: no form, no reason, and a stale page changes nothing.
$page=Livewire::actingAs($manager)->test(ListWatches::class);expect(str_contains($page->html(),'role="switch"'),'Managers get the switch');
$revision=(int)WatchProductMap::find($map->id)->revision;
$page->callAction('productSwitch',[],['map'=>$map->id,'revision'=>$revision]);$fresh=WatchProductMap::find($map->id);same([false,$revision+1],[$fresh->enabled,$fresh->revision],'Switch turns monitoring off');
$page->callAction('productSwitch',[],['map'=>$map->id,'revision'=>$revision])->assertNotified('This product changed. Reload the page and try again.');same([false,$revision+1],[WatchProductMap::find($map->id)->enabled,WatchProductMap::find($map->id)->revision],'Stale switch changes nothing');
$page->callAction('productSwitch',[],['map'=>$map->id,'revision'=>$revision+1]);same([true,$revision+2],[WatchProductMap::find($map->id)->enabled,WatchProductMap::find($map->id)->revision],'Switch turns monitoring on');

// Choosing a field is only for No domain found; it saves and starts monitoring in one step, and never shows a customer value.
Livewire::actingAs($manager)->test(ListWatches::class)->mountAction('chooseField',['product_id'=>$twoLike->id])
    ->assertFormFieldExists('property_key',fn($select)=>$select->getOptions()===['mirror'=>'mirror','site'=>'site'])->assertDontSee('shop.example.com')
    ->setActionData(['property_key'=>'made_up'])->callMountedAction()->assertHasActionErrors(['property_key']);
same(0,WatchProductMap::where('product_id',$twoLike->id)->count(),'Made-up field saves nothing');
Livewire::actingAs($manager)->test(ListWatches::class)->callAction('chooseField',['property_key'=>'site'],['product_id'=>$twoLike->id])->assertHasNoActionErrors()->assertNotified('Saved');
$chosen=WatchProductMap::where('product_id',$twoLike->id)->sole();same(['site',true],[$chosen->property_key,$chosen->enabled],'Chosen field is monitored');
// Off is no dead end: the picker stays on the line, a new pick keeps the row, and a stale page changes nothing.
$pickers=fn()=>substr_count(Livewire::actingAs($manager)->test(ListWatches::class)->html(),'Choose a field');$before=$pickers();
Livewire::actingAs($manager)->test(ListWatches::class)->callAction('productSwitch',[],['map'=>$chosen->id,'revision'=>$chosen->revision]);same($before+1,$pickers(),'An off line offers the picker');
$off=WatchProductMap::find($chosen->id);$repick=['product_id'=>$twoLike->id,'map'=>$off->id];
Livewire::actingAs($manager)->test(ListWatches::class)->callAction('chooseField',['property_key'=>'mirror'],$repick+['revision'=>$off->revision-1])->assertNotified('This product changed. Reload the page and try again.');same(['site',false],[WatchProductMap::find($off->id)->property_key,WatchProductMap::find($off->id)->enabled],'A stale pick changes nothing');
Livewire::actingAs($manager)->test(ListWatches::class)->callAction('chooseField',['property_key'=>'mirror'],$repick+['revision'=>$off->revision])->assertHasNoActionErrors()->assertNotified('Saved');
$picked=WatchProductMap::where('product_id',$twoLike->id)->sole();same([$off->id,'mirror',true,$off->revision+2],[$picked->id,$picked->property_key,$picked->enabled,$picked->revision],'A new pick on an off line starts monitoring on the same row');
$work(true,$now+1200)->tick();same('mirror',WatchProductMap::find($off->id)->property_key,'The tick leaves a staff pick alone');
$bare=$hosting('Bare');$saved($bare,[]);Livewire::actingAs($manager)->test(ListWatches::class)->mountAction('chooseField',['product_id'=>$bare->id])
    ->assertFormFieldExists('property_key',fn($select)=>str_contains($select->toHtml(),'No service on this product has a text field yet. If it has a domain, add a text option for it.'));

// While monitoring is off the page has no product lines to act on.
app()->forgetInstance(WatchWork::class);
Livewire::actingAs($manager)->test(ListWatches::class)->assertSuccessful()->assertDontSee('No domain found')->assertDontSee('Not monitored');
$nativeSuccessful=true;echo "Hosting product lines work.\n";
