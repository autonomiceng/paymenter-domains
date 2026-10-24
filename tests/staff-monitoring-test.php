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

// DNS templates.
$templates=Livewire::actingAs($manager)->test(ListTemplates::class)->assertCanSeeTableRecords([$profile])->assertActionVisible('save');$clean($templates->html(),'DNS templates');
same('DNS templates',$templates->instance()->getTitle(),'Templates title');same(null,$templates->instance()->getSubheading(),'No templates subheading');same('Add DNS template',$templates->instance()->getAction('save')->getLabel(),'Add template');
foreach(['name'=>'Template','nameservers'=>'Nameservers','enabled'=>'Status','used_by'=>'Used by'] as $name=>$label){same($label,$column($templates,$name)->getLabel(),'Template column '.$name);}
same(null,$column($templates,'revision'),'No revision column');same('On',$column($templates,'enabled')->record($profile)->getState(),'Status On');same('1 hosting product',$column($templates,'used_by')->record($profile)->getState(),'Used by count');
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
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);$paused=WatchFacts::for(WatchName::find($watch->id));expect(($paused['Checks']['DNS records']['value']??'')!==null,'Paused DNS records not Missing');
$nativeSuccessful=true;echo "Domain monitoring, DNS templates and Hosting products pages passed.\n";
