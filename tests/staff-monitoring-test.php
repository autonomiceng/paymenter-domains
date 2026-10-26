<?php
// Domain monitoring, DNS templates and Hosting products staff pages over local synthetic evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,ProductMappingResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\ProductMappingResource\Pages\{ListConfiguration as ListProducts,ViewConfiguration as ViewProduct};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,ProductMappingResource::class,RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);Auth::setUser($manager);
// Clean copy: no em dash, no "unavailable", no internal terms.
$clean=function(string $html,string $page):void{$text=html_entity_decode(strip_tags($html));expect(!str_contains($text,'—'),'No em dash on '.$page);foreach(['unavailable','Saved revision','revision','feature gates','Domain watch','routing profile','mapping'] as $word){expect(stripos($text,$word)===false,'No "'.$word.'" on '.$page);}};
$column=fn($page,string $name)=>$page->instance()->getTable()->getColumn($name);
$filter=fn($page,string $name)=>$page->instance()->getTable()->getFilter($name);

// Unchecked: Monitoring, with Last check Missing and its cause.
$list=Livewire::actingAs($manager)->test(ListWatches::class)->assertCanSeeTableRecords([$watch]);$clean($list->html(),'monitoring list');
same('Domain monitoring',$list->instance()->getTitle(),'List title');same(null,$list->instance()->getSubheading(),'No list subheading');
foreach(['canonical_name'=>'Domain','status'=>'Status','registration'=>'Registrar','delegation.dns'=>'Nameservers','last_check'=>'Last check','expiry'=>'Expires'] as $name=>$label){same($label,$column($list,$name)->getLabel(),'Column '.$name);}
foreach(['findings','user_id','state','work_due'] as $gone){same(null,$column($list,$gone),'Old column '.$gone.' removed');}
same('Monitoring',$column($list,'status')->record($watch)->getState(),'Unchecked domain is Monitoring');
same('Missing',$column($list,'last_check')->record($watch)->getState(),'Last check Missing');same('Not checked yet',$column($list,'last_check')->record($watch)->getDescriptionBelow(),'Missing says its cause');
same('Missing',$column($list,'registration')->record($watch)->getState(),'Registrar Missing');same('Not recorded yet',$column($list,'registration')->record($watch)->getDescriptionBelow(),'Registrar Missing says its cause');
same('Customer #'.$uid,$column($list,'canonical_name')->record($watch)->getDescriptionBelow(),'Customer is the domain secondary line');
foreach(['status'=>'Status','customer'=>'Customer','registration'=>'Registrar','expected_dns'=>'Expected nameservers','observed'=>'Nameservers','product'=>'Hosting product','expiry'=>'Expires within'] as $name=>$label){same($label,$filter($list,$name)->getLabel(),'Filter '.$name);}
same(null,$filter($list,'attention'),'Attention filter merged into Status');same(null,$filter($list,'drift'),'Drift filter merged into Status');same(null,$filter($list,'state'),'State filter merged into Status');
same(['monitoring'=>'Monitoring','problem'=>'Problem found','paused'=>'Paused','stopped'=>'Stopped'],$filter($list,'status')->getOptions(),'Status filter options');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','paused')->assertCountTableRecords(0)->assertSee('No monitored domains');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('customer',['customer'=>(string)$uid])->assertCanSeeTableRecords([$watch]);

// Checked: nameservers moved away and expiry within 30 days is Problem found with the reason.
DB::table('domains_watch_names')->where('id',$id)->update(['registration'=>'external']);$at=time()-120;noticeRegistry($f,$at,11);noticeDns($f,$at,'external');
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$at,'backoff_until'=>null]);$t=$dns->begin($id,$uid,'points',$at);expect($t!==null,'Points ticket');expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('points',website:true,email:false),$at+1),'Points reading');
$expiry=gmdate('Y-m-d',$at+11*86400);$days=intdiv(strtotime($expiry.' 00:00:00 UTC')-strtotime(gmdate('Y-m-d').' 00:00:00 UTC'),86400);
$list=Livewire::actingAs($manager)->test(ListWatches::class);$record=WatchName::find($id);
same('Problem found',$column($list,'status')->record($record)->getState(),'Problem found pill');same('warning',$column($list,'status')->record($record)->getColor('Problem found'),'Problem found is warning');
same('Expires in '.$days.' days',$column($list,'status')->record($record)->getDescriptionBelow(),'Reason under the pill');
same('Other',$column($list,'delegation.dns')->record($record)->getState(),'Nameservers elsewhere read Other');
expect($column($list,'last_check')->record($record)->getState()!=='Missing','Last check has a time');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','problem')->assertCanSeeTableRecords([$record]);
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','monitoring')->assertCanNotSeeTableRecords([$record]);

// Monitored domain page: heading pill, header buttons, sections, one notice.
$view=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);$page=$view->instance();$clean($view->html(),'monitored domain');
expect(str_contains($page->getHeading()->toHtml(),'font-mono') && str_contains($page->getHeading()->toHtml(),'Problem found'),'Heading is the mono name and the pill');
same('Expires in '.$days.' days',$page->getSubheading(),'Reason under the heading');
same([WatchResource::getUrl()=>'Domain monitoring','example.com'],$page->getBreadcrumbs(),'Breadcrumb');
foreach(['read'=>'Check now','pause'=>'Pause monitoring','profile'=>'Change DNS template','note'=>'Add note','retire'=>'Stop monitoring'] as $name=>$label){same($label,$page->getAction($name)?->getLabel(),'Action '.$name);}
$view->assertActionHidden('resume');
foreach(['read','pause'] as $name){same(false,$page->getAction($name)->isConfirmationRequired(),$name.' has no dialog');}
same('Stop monitoring?',$page->getAction('retire')->getModalHeading(),'Stop dialog heading');same("Checks and alerts stop. The domain won't be added again automatically.",$page->getAction('retire')->getModalDescription(),'Stop dialog body');
same('Future checks compare against this DNS template.',$page->getAction('profile')->getModalDescription(),'Template dialog body');
$view->assertSeeInOrder(['Domain','Customer','Expected nameservers','DNS template','Synthetic defaults','Registrar','Expires','Hosting service','Hosting','Checks','Nameservers','DNS records','Website points to','Email points elsewhere','Registry','Registered','Last check','Next check','Monitoring alerts','On','History','No history yet']);
$view->assertSee('Expires in '.$days.' days.')->assertDontSee('Findings')->assertDontSee('Saved domain checks')->assertDontSee('Notification activity')->assertDontSee('Watch state');
$facts=WatchFacts::for($record);same(['Domain','Checks'],array_keys($facts),'Two fact sections');same('Other',$facts['Domain']['Registrar']['value']??'Missing','Registrar from the saved registration');

// Pause and resume run without a dialog; Check now queues; stale pages refuse.
$stale=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);DB::table('domains_watch_names')->where('id',$id)->increment('revision');
$stale->callAction('pause')->assertNotified('This domain changed. Reload the page and try again.');same('active',WatchName::find($id)->state,'Stale page changes nothing');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('pause')->assertNotified('Monitoring paused');same('paused',WatchName::find($id)->state,'Pause saves');
$paused=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertActionHidden('pause')->assertActionHidden('read')->assertSee('Paused')->assertDontSee('Next check');
same('Resume monitoring',$paused->instance()->getAction('resume')->getLabel(),'Resume label');$paused->callAction('resume')->assertNotified('Monitoring resumed');same('active',WatchName::find($id)->state,'Resume saves');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('note',['note'=>'Synthetic staff note'])->assertNotified('Saved');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSeeInOrder(['History','Note','Synthetic staff note','Monitoring resumed','Monitoring paused'])->assertDontSee('No history yet');
$rows=WatchFacts::history(WatchName::find($id));same(['Note','Monitoring resumed','Monitoring paused'],array_column($rows,'event'),'History events newest first');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('retire',['reason'=>'Customer asked to stop'])->assertNotified('Saved');same('retired',WatchName::find($id)->state,'Stop saves');
same('Stopped',WatchFindings::status(WatchName::find($id),[])['label'],'Stopped status');


// Apply the real table filters, including Filament's deferred filter state.
$applyFilters=static function($component,array $filters){
    foreach($filters as $name=>$value){$component->filterTable($name,$value);}
    return $component->set('tableDeferredFilters',$component->get('tableFilters'))->call('applyTableFilters');
};
// Stopped includes both retained states, and excludes active and paused records.
DB::beginTransaction();
$makeWatch=static fn(string $name,int $customer,string $state='active',array $extra=[])=>WatchName::create([
    'user_id'=>$customer,'canonical_name'=>$name,'psl_revision'=>str_repeat('a',64),'state'=>$state,
    'expected_dns'=>'platform','ns_profile_id'=>$profile->id,'profile_revision'=>1,'notice_preferences'=>[],...$extra]);
$activeFilter=$makeWatch('active-filter.example',$uid);$pausedFilter=$makeWatch('paused-filter.example',$uid,'paused');
$otherFilter=$makeWatch('other-filter.example',(int)$other->id);
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
$linkedFilter=$makeLinked('linked-filter.example');$retiredFilter=WatchName::findOrFail($id);
$stopped=$applyFilters(Livewire::actingAs($manager)->test(ListWatches::class),['status'=>'stopped']);
$stopped->assertCanSeeTableRecords([$retiredFilter,$linkedFilter])->assertCanNotSeeTableRecords([$activeFilter,$pausedFilter,$otherFilter])->assertCountTableRecords(2);
$applyFilters(Livewire::actingAs($manager)->test(ListWatches::class),['status'=>'paused'])
    ->assertCanSeeTableRecords([$pausedFilter])->assertCanNotSeeTableRecords([$retiredFilter,$linkedFilter,$activeFilter,$otherFilter])->assertCountTableRecords(1);
// Name and email searches require the current native permission, even with a stale loaded role.
DB::table('users')->where('id',$uid)->update(['first_name'=>'Mira','last_name'=>'Filtermatch','email'=>'mira-filter@example.test']);
$viewer=staffUser([Staff::VIEW,Writes::VIEW,'admin.users.view']);$loadedRole=$viewer->role;
$ownedFilter=[$retiredFilter,$linkedFilter,$activeFilter,$pausedFilter];
$searchCustomer=static fn(string $term)=>$applyFilters(Livewire::actingAs($viewer)->test(ListWatches::class),['customer'=>['customer'=>$term]]);
foreach(['Mira','Filtermatch','mira-filter@example.test'] as $term){$searchCustomer($term)->assertCanSeeTableRecords($ownedFilter)->assertCanNotSeeTableRecords([$otherFilter])->assertCountTableRecords(4);}
$searchCustomer('no-such-customer@example.test')->assertCountTableRecords(0);
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>json_encode([Staff::VIEW,Writes::VIEW])]);
expect(in_array('admin.users.view',$loadedRole->permissions,true),'Customer filter actor retains a stale role with the revoked permission');
Auth::setUser($viewer->setRelation('role',$loadedRole));
foreach(['Mira','Filtermatch','mira-filter@example.test'] as $term){same([],WatchFindings::customer(WatchName::query(),$term)->pluck('id')->all(),'Customer text lookup refreshes the retained actor after permission revocation');}
foreach(['Mira','Filtermatch','mira-filter@example.test'] as $term){$searchCustomer($term)->assertCanNotSeeTableRecords([...$ownedFilter,$otherFilter])->assertCountTableRecords(0);}
$searchCustomer((string)$uid)->assertCanSeeTableRecords($ownedFilter)->assertCanNotSeeTableRecords([$otherFilter])->assertCountTableRecords(4);
$searchCustomer((string)$other->id)->assertCanSeeTableRecords([$otherFilter])->assertCanNotSeeTableRecords($ownedFilter)->assertCountTableRecords(1);
DB::table('roles')->where('id',$viewer->role_id)->update(['permissions'=>json_encode([Staff::VIEW,Writes::VIEW,'admin.users.view'])]);
foreach(['Mira','mira-filter@example.test'] as $term){$searchCustomer($term)->assertCanSeeTableRecords($ownedFilter)->assertCanNotSeeTableRecords([$otherFilter])->assertCountTableRecords(4);}
DB::rollBack();Auth::setUser($manager);

// DNS templates.
$templates=Livewire::actingAs($manager)->test(ListTemplates::class)->assertCanSeeTableRecords([$profile])->assertActionVisible('save');$clean($templates->html(),'DNS templates');
same('DNS templates',$templates->instance()->getTitle(),'Templates title');same(null,$templates->instance()->getSubheading(),'No templates subheading');same('Add DNS template',$templates->instance()->getAction('save')->getLabel(),'Add template');
foreach(['name'=>'Template','nameservers'=>'Nameservers','enabled'=>'Status','used_by'=>'Used by'] as $name=>$label){same($label,$column($templates,$name)->getLabel(),'Template column '.$name);}
same(null,$column($templates,'revision'),'No revision column');same('On',$column($templates,'enabled')->record($profile)->getState(),'Status On');same('1 hosting product',$column($templates,'used_by')->record(RoutingProfileResource::getEloquentQuery()->findOrFail($profile->id))->getState(),'Used by count');
$template=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$profile->id])->assertSeeInOrder(['Records','Status','On','Nameservers','Website records','Email records','Used by','Monitored domains','Hosting products'])->assertActionHidden('enable');$clean($template->html(),'DNS template');
same('Synthetic defaults',$template->instance()->getTitle(),'Template title');same('Edit',$template->instance()->getAction('save')->getLabel(),'Edit template');
same([RoutingProfileResource::getUrl()=>'DNS templates','Synthetic defaults'],$template->instance()->getBreadcrumbs(),'Template breadcrumb');
$off=DB::transaction(fn()=>WatchProfile::create(['name'=>'Spare template','nameservers'=>['ns1.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic spare']));
$spare=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$off->id]);same('Turn on',$spare->instance()->getAction('enable')->getLabel(),'Turn on template');same('Turn on Spare template? Monitoring checks start using it.',$spare->instance()->getAction('enable')->getModalDescription(),'Turn on body');
same('Saving turns this template off until you turn it on again.',$spare->instance()->getAction('save')->getModalDescription(),'Edit body');
$spare->callAction('enable')->assertNotified('Saved');expect(WatchProfile::find($off->id)->enabled,'Turn on saves');

// Hosting products.
$products=Livewire::actingAs($manager)->test(ListProducts::class)->assertCanSeeTableRecords([$map]);$clean($products->html(),'Hosting products');
same('Hosting products',$products->instance()->getTitle(),'Products title');same(null,$products->instance()->getSubheading(),'No products subheading');same('Add hosting product',$products->instance()->getAction('save')->getLabel(),'Add product');
foreach(['product.name'=>'Product','property_key'=>'Domain field','template'=>'DNS template','enabled'=>'Monitoring'] as $name=>$label){same($label,$column($products,$name)->getLabel(),'Product column '.$name);}
same('Synthetic defaults',$column($products,'template')->record($map)->getState(),'Template name');same('On',$column($products,'enabled')->record($map)->getState(),'Monitoring On');
$product=Livewire::actingAs($manager)->test(ViewProduct::class,['record'=>$map->id])->assertSeeInOrder(['Settings','Product','Hosting','Domain field','domain','Monitoring','On','DNS template','Synthetic defaults','Matched services'])->assertActionHidden('enable');$clean($product->html(),'Hosting product');
same('Hosting',$product->instance()->getTitle(),'Product title');same('Edit',$product->instance()->getAction('save')->getLabel(),'Edit product');same('Saving turns monitoring off until you turn it on again.',$product->instance()->getAction('save')->getModalDescription(),'Edit product body');
same([ProductMappingResource::getUrl()=>'Hosting products','Hosting'],$product->instance()->getBreadcrumbs(),'Product breadcrumb');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);
$offProduct=Livewire::actingAs($manager)->test(ViewProduct::class,['record'=>$map->id]);same('Turn on monitoring',$offProduct->instance()->getAction('enable')->getLabel(),'Turn on monitoring');same('Turn on monitoring for Hosting? Matched domains start being checked.',$offProduct->instance()->getAction('enable')->getModalDescription(),'Turn on monitoring body');
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['expected_ns_profile_id'=>null]);
same('Missing',$column(Livewire::actingAs($manager)->test(ListProducts::class),'template')->record(\Paymenter\Extensions\Servers\Domains\Models\WatchProductMap::find($map->id))->getState(),'No template is Missing');
// Expiry wording past and on the date; submit buttons name their action; a paused name shows no Missing DNS records.
$day=strtotime('2026-01-03 12:00:00 UTC');foreach(['2026-01-01'=>'Expired 2 days ago','2026-01-02'=>'Expired 1 day ago','2026-01-03'=>'Expires today','2026-01-04'=>'Expires in 1 day','2026-01-05'=>'Expires in 2 days'] as $date=>$text){same($text,WatchFindings::expires($date,$day),'Expiry wording '.$date);}
same('Save',$product->instance()->getAction('save')->getModalSubmitActionLabel(),'Edit submit');same('Turn on monitoring',$offProduct->instance()->getAction('enable')->getModalSubmitActionLabel(),'Turn on submit');
$acts=\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions::watch($watch,(int)$watch->revision);$more=[];foreach(end($acts)->getActions() as $a){$more[$a->getName()]=$a->getModalSubmitActionLabel();}
same(['profile'=>'Save','note'=>'Add note','retire'=>'Stop monitoring'],$more,'More submit labels');
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);$paused=WatchFacts::for(WatchName::find($watch->id));expect(!array_key_exists('DNS records',$paused['Checks']),'Paused name with no record results hides DNS records, not Missing');
// Render the resource-query records so list reads stay bounded as rows and references grow.
$resourceRows=static function(string $resource,string $page,string $columnName)use($manager,$column):array{
    $component=Livewire::actingAs($manager)->test($page);$cell=$column($component,$columnName);$cell->clearCachedState();
    DB::flushQueryLog();DB::enableQueryLog();$records=$resource::getEloquentQuery()->orderBy('id')->get();$values=[];
    foreach($records as $row){$values[$row->id]=$cell->record($row)->getState();}
    $queries=DB::getQueryLog();DB::disableQueryLog();return [$values,count($queries),$records];
};
DB::beginTransaction();
DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['expected_ns_profile_id'=>$profile->id]);
[, $oneMap]=$resourceRows(ProductMappingResource::class,ListProducts::class,'template');
[, $twoTemplates]=$resourceRows(RoutingProfileResource::class,ListTemplates::class,'used_by');
$third=WatchProfile::create(['name'=>'Disabled template','nameservers'=>['ns1.disabled.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic disabled template']);
$expectedTemplates=[$map->id=>'Synthetic defaults'];
foreach([$profile->id,$off->id,$third->id,$third->id,null,999999] as $i=>$templateId){
    $productRow=(array)DB::table('products')->where('id',$map->product_id)->first();unset($productRow['id']);$productRow['slug']='list-query-product-'.$i;
    $productId=DB::table('products')->insertGetId($productRow);$copy=$map->fresh()->getRawOriginal();unset($copy['id']);
    $mapId=DB::table('domains_watch_product_maps')->insertGetId([...$copy,'product_id'=>$productId,'expected_ns_profile_id'=>$templateId,'enabled'=>false]);
    $expectedTemplates[$mapId]=match($templateId){$profile->id=>'Synthetic defaults',$off->id=>'Spare template',$third->id=>'Disabled template',default=>'Missing'};
}
[$names,$manyMaps,$maps]=$resourceRows(ProductMappingResource::class,ListProducts::class,'template');
same($expectedTemplates,$names,'Loaded template names include disabled templates, null and dangling references');
same($oneMap,$manyMaps,'Template-name queries do not grow per hosting product');
$missingCell=$column(Livewire::actingAs($manager)->test(ListProducts::class),'template')->record($maps->last());
same(['Missing','Choose one in Edit','danger','heroicon-m-exclamation-triangle'],[$missingCell->getState(),$missingCell->getDescriptionBelow(),$missingCell->getColor('Missing'),$missingCell->getIcon('Missing')],'Missing template feedback is unchanged');
[$counts,$manyTemplates,$profiles]=$resourceRows(RoutingProfileResource::class,ListTemplates::class,'used_by');
same([$profile->id=>'2 hosting products',$off->id=>'1 hosting product',$third->id=>'2 hosting products'],$counts,'Loaded counts include disabled maps and use normal plurals');
same($twoTemplates,$manyTemplates,'Used-by queries do not grow per DNS template');
foreach($profiles as $row){same(WatchFacts::products(WatchFacts::usedBy((int)$row->id)['products']),$counts[$row->id],'List and detail product counts agree');}
$empty=WatchProfile::create(['name'=>'Unused template','nameservers'=>['ns1.unused.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic unused template']);
[$counts]=$resourceRows(RoutingProfileResource::class,ListTemplates::class,'used_by');same('0 hosting products',$counts[$empty->id],'Unused template count remains zero');
DB::rollBack();

$nativeSuccessful=true;echo "Domain monitoring, DNS templates and Hosting products pages passed.\n";
