<?php
// Domain monitoring and DNS templates staff pages over local synthetic evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';require __DIR__.'/Support/domain-name-cell.php';require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';$f=noticeFixture();extract($f);\Paymenter\Extensions\Servers\Domains\Services\StaffWatchSchema::install();staffPanel();$panel=Filament::getCurrentPanel();$resources=[WatchResource::class,RoutingProfileResource::class];$panel->resources($resources);
Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resources,$panel){foreach($resources as$r){$r::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
$manager=staffUser([Staff::VIEW,Writes::VIEW,Writes::MANAGE]);Auth::setUser($manager);
// Monitoring-only configuration leaves the menu until the deployment supplies both activation gates.
$monitoringReader=new \Paymenter\Extensions\Servers\Domains\Dns\NativeDnsReader(static fn()=>[]);
$discovery=new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork(static fn()=>null);
foreach([[false,$discovery],[true,new \Paymenter\Extensions\Servers\Domains\Services\WatchDiscoveryWork()]] as [$enabled,$producer]){
    app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($monitoringReader,$producer,$enabled));
    foreach([RoutingProfileResource::class] as $resource){same(false,$resource::shouldRegisterNavigation(),'Unconfigured monitoring hides '.$resource);}
    same(true,WatchResource::shouldRegisterNavigation(),'Monitoring status remains reachable');
}
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\WatchWork::class,new \Paymenter\Extensions\Servers\Domains\Services\WatchWork($monitoringReader,$discovery,true));
foreach([RoutingProfileResource::class] as $resource){same(true,$resource::shouldRegisterNavigation(),'Configured monitoring shows '.$resource);}
// Clean copy: no em dash, no "unavailable", no internal terms.
$clean=function(string $html,string $page):void{$text=html_entity_decode(strip_tags($html));expect(!str_contains($text,'—'),'No em dash on '.$page);foreach(['unavailable','Saved revision','revision','feature gates','Domain watch','routing profile','mapping'] as $word){expect(stripos($text,$word)===false,'No "'.$word.'" on '.$page);}};
$column=fn($page,string $name)=>$page->instance()->getTable()->getColumn($name);
$filter=fn($page,string $name)=>$page->instance()->getTable()->getFilter($name);

// Unchecked: Monitoring, with Last check Missing and its cause.
$list=Livewire::actingAs($manager)->test(ListWatches::class)->assertCanSeeTableRecords([$watch]);$clean($list->html(),'monitoring list');
assertDomainNameWraps($list->html(),$watch->canonical_name,'Monitoring list');
same('Domain monitoring',$list->instance()->getTitle(),'List title');same(null,$list->instance()->getSubheading(),'No list subheading');
foreach(['canonical_name'=>'Domain','status'=>'Status','registration'=>'Registrar','delegation.dns'=>'Nameservers','last_check'=>'Last check','expiry'=>'Expires'] as $name=>$label){same($label,$column($list,$name)->getLabel(),'Column '.$name);}
foreach(['findings','user_id','state','work_due'] as $gone){same(null,$column($list,$gone),'Old column '.$gone.' removed');}
same('Monitoring',$column($list,'status')->record($watch)->getState(),'Unchecked domain is Monitoring');
same('Missing',$column($list,'last_check')->record($watch)->getState(),'Last check Missing');same('Fills in by itself after the next check',$column($list,'last_check')->record($watch)->getDescriptionBelow(),'Missing says its cause');
same('Missing',$column($list,'registration')->record($watch)->getState(),'Registrar Missing');same('Fills in by itself after the next check',$column($list,'registration')->record($watch)->getDescriptionBelow(),'Registrar Missing says its cause');
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
assertDomainNameWraps($page->getHeading()->toHtml(),$watch->canonical_name,'Monitored domain title','title');
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
$stale->call('refreshFacts')->callAction('pause')->assertNotified('This domain changed. Reload the page and try again.');same('active',WatchName::find($id)->state,'Stale page changes nothing');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('pause')->assertNotified('Monitoring paused');same('paused',WatchName::find($id)->state,'Pause saves');
$paused=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertActionHidden('pause')->assertActionHidden('read')->assertSee('Paused')->assertDontSee('Next check');
same('Resume monitoring',$paused->instance()->getAction('resume')->getLabel(),'Resume label');$paused->callAction('resume')->assertNotified('Monitoring resumed');same('active',WatchName::find($id)->state,'Resume saves');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('note',['note'=>'Synthetic staff note'])->assertNotified('Saved');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSeeInOrder(['History','Note','Synthetic staff note','Monitoring resumed','Monitoring paused'])->assertDontSee('No history yet');
$rows=WatchFacts::history(WatchName::find($id));same(['Note','Monitoring resumed','Monitoring paused'],array_column($rows,'event'),'History events newest first');
// A queued check keeps feedback on the current page rather than losing the toast to a redirect.
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
// Distinct DOM identity disconnects the prior Livewire timer when switching cadence.
$pollKey=static function($page):string{
    $doc=new DOMDocument;@$doc->loadHTML($page->html());
    $nodes=(new DOMXPath($doc))->query('//*[@*[starts-with(name(),"wire:poll")]]');
    same(1,$nodes->length,'Exactly one polling element');$key=$nodes->item(0)->getAttribute('wire:key');
    expect($key!=='','Polling element has a stable key');return $key;
};
$secondTab=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false);
$passiveKey=$pollKey($secondTab);
$check=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->callAction('read')->assertSee('wire:poll.5s="pollCheck"',false)
    ->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Check requested')
        ->body('This takes up to about six minutes. This page updates by itself.')->persistent())
    ->assertNoRedirect();
$pendingKey=$pollKey($check);expect($pendingKey!==$passiveKey,'Changing polling mode replaces the timer element');
same('Monitoring uses 0 registrar reads per domain per day.',$check->instance()->getAction('read')->getTooltip(),'Check cost is visible before requesting it');
same(1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->count(),'Check request recorded once');
$originalWait=$check->get('pendingCheck');
$requestAt=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->first()->created_at->getTimestamp();
same($requestAt+360,$originalWait[0],'Deadline starts at the stored accepted request time');
\Carbon\Carbon::setTestNow(now()->addSeconds(120));
$waitingPages=['second tab'=>$secondTab,'reload'=>Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])];
foreach($waitingPages as $label=>$waitingPage){
    if($label==='reload'){$waitingPage->assertSee('wire:poll.5s="pollCheck"',false);same($originalWait,$waitingPage->get('pendingCheck'),'Reload restores the original wait');}
    $waitingPage->callAction('read')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotified('Check already requested')->assertNoRedirect();
    same($originalWait,$waitingPage->get('pendingCheck'),$label.' joins the same wait without extending it');
}
$check->callAction('read')->assertNotified('Check already requested');
same($originalWait,$check->get('pendingCheck'),'Retry preserves the original deadline');
same(1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->count(),'Throttled tabs do not add a request');
\Carbon\Carbon::setTestNow();
$check->call('pollCheck')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotNotified('Check finished');
same(WatchFacts::date($at),$check->instance()->getSchema('infolist')->getComponentByStatePath('fact_'.md5('Last check'))->getState(),'Pending page keeps its saved Last check');
// A read already in flight can start before the request and save a newer Last check afterwards.
$completedAt=$requestAt-1;DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->update(['due_at'=>$completedAt,'backoff_until'=>null]);
$t=$dns->begin($id,$uid,'delegation',$completedAt);expect($t!==null,'In-flight DNS ticket');
expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$requestAt+1),'In-flight DNS result saved after the request');
$check->call('pollCheck')->assertSee(WatchFacts::date($completedAt))->assertNotified('Check finished')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNoRedirect();
same($passiveKey,$pollKey($check),'Completion restores the stable passive element');
same(WatchFacts::date($completedAt),$check->instance()->getSchema('infolist')->getComponentByStatePath('fact_'.md5('Last check'))->getState(),'Poll updates the Last check entry itself');
$check->call('pollCheck')->assertNotNotified('Check finished');
foreach($waitingPages as $label=>$waitingPage){
    $waitingPage->call('pollCheck')->assertNotified('Check finished')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false);
    same(WatchFacts::date($completedAt),$waitingPage->instance()->getSchema('infolist')->getComponentByStatePath('fact_'.md5('Last check'))->getState(),$label.' receives the new Last check');
}
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotNotified('Check finished');
// Autonomous work after Check finished must refresh this same open page, including Next check.
$autonomousAt=$requestAt+120;
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$autonomousAt,'backoff_until'=>null]);
$t=$dns->begin($id,$uid,'points',$autonomousAt);expect($t!==null,'Autonomous points ticket');
$check->call('refreshFacts')->assertSee('Checking now')->assertNotNotified('Check finished');
expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('points',website:true,email:true),$autonomousAt+1),'Autonomous points result');
$audits=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count();
$check->call('refreshFacts')->assertSee(WatchFacts::date($autonomousAt))->assertDontSee('Checking now')->assertNotNotified('Check finished');
same(WatchFacts::date($autonomousAt),$check->instance()->getSchema('infolist')->getComponentByStatePath('fact_'.md5('Last check'))->getState(),'Passive refresh updates Last check on the open page');
same(WatchFacts::for(WatchName::find($id))['Checks']['Next check']['value'],$check->instance()->getSchema('infolist')->getComponentByStatePath('fact_'.md5('Next check'))->getState(),'Passive refresh replaces Next check after work finishes');
same($audits,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),'Passive refresh requests no checks or writes');
// Age the synthetic request claim so a second real Check now action can be tested.
DB::table('domains_watch_staff_claims')->where('subject_type','watch')->where('subject_id',$id)->where('action','read')->update(['created_at'=>time()-301]);
// If no saved check moves, support advice appears only after the six-minute wait.
$waitingAt=\Carbon\Carbon::createFromTimestampUTC($autonomousAt+2);\Carbon\Carbon::setTestNow($waitingAt);
$check=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('read');
same($pendingKey,$pollKey($check),'Another request reuses the pending key, not the passive node');
\Carbon\Carbon::setTestNow($waitingAt->copy()->addSeconds(359));
$check->call('pollCheck')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotNotified('Check is taking longer');
$reload=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('wire:poll.5s="pollCheck"',false);
same($check->get('pendingCheck'),$reload->get('pendingCheck'),'Reload at 359 seconds keeps the original deadline');
\Carbon\Carbon::setTestNow($waitingAt->copy()->addSeconds(360));
$check->call('pollCheck')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('Check is taking longer')
    ->body('Last check has not changed. Ask your site admin.')->persistent())->assertNoRedirect();
same($passiveKey,$pollKey($check),'Timeout also disconnects the pending timer');
$check->call('pollCheck')->assertNotNotified('Check is taking longer');
$reload->call('pollCheck')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified('Check is taking longer');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified('Check is taking longer');
\Carbon\Carbon::setTestNow();

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
foreach(['name'=>'Template','nameservers'=>'Nameservers','enabled'=>'Status'] as $name=>$label){same($label,$column($templates,$name)->getLabel(),'Template column '.$name);}
same(null,$column($templates,'revision'),'No revision column');same('On',$column($templates,'enabled')->record($profile)->getState(),'Status On');same(null,$column($templates,'used_by'),'No Used by column');
$template=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$profile->id])->assertSeeInOrder(['Records','Status','On','Nameservers','Website records','Email records','Used by','Monitored domains'])->assertActionHidden('enable');$clean($template->html(),'DNS template');
same('Synthetic defaults',$template->instance()->getTitle(),'Template title');same('Edit',$template->instance()->getAction('save')->getLabel(),'Edit template');
same([RoutingProfileResource::getUrl()=>'DNS templates','Synthetic defaults'],$template->instance()->getBreadcrumbs(),'Template breadcrumb');
$off=DB::transaction(fn()=>WatchProfile::create(['name'=>'Spare template','nameservers'=>['ns1.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic spare']));
$spare=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$off->id]);same('Turn on',$spare->instance()->getAction('enable')->getLabel(),'Turn on template');
same('Saving turns this template off until you turn it on again.',$spare->instance()->getAction('save')->getModalDescription(),'Edit body');
$spare->callAction('enable')->assertNotified('Saved');expect(WatchProfile::find($off->id)->enabled,'Turn on saves');

DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);
// Expiry wording past and on the date; a paused name shows no Missing DNS records.
$day=strtotime('2026-01-03 12:00:00 UTC');foreach(['2026-01-01'=>'Expired 2 days ago','2026-01-02'=>'Expired 1 day ago','2026-01-03'=>'Expires today','2026-01-04'=>'Expires in 1 day','2026-01-05'=>'Expires in 2 days'] as $date=>$text){same($text,WatchFindings::expires($date,$day),'Expiry wording '.$date);}
$acts=\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions::watch($watch,(int)$watch->revision);$more=[];foreach(end($acts)->getActions() as $a){$more[$a->getName()]=$a->getModalSubmitActionLabel();}
same(['profile'=>'Save','note'=>'Add note','retire'=>'Stop monitoring'],$more,'More submit labels');
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);$paused=WatchFacts::for(WatchName::find($watch->id));expect(!array_key_exists('DNS records',$paused['Checks']),'Paused name with no record results hides DNS records, not Missing');

// Expiry indicators must describe the same pre-expiry candidates as the bounded historical scan.
DB::beginTransaction();
$watchSeed=WatchName::findOrFail($id)->getRawOriginal();unset($watchSeed['id']);
$observation=(array)DB::table('domains_watch_registry')->where('watch_id',$id)->where('result','facts')->first();
expect(isset($observation['id']),'Expiry indicator fixture has a real facts observation');unset($observation['id']);
$registryFacts=json_decode(Crypt::decryptString($observation['facts']),true,flags:JSON_THROW_ON_ERROR);
$near=[...$observation,'facts'=>Crypt::encryptString(json_encode([...$registryFacts,'expiry'=>gmdate('Y-m-d',time()+3*86400)],JSON_THROW_ON_ERROR))];
$far=[...$observation,'facts'=>Crypt::encryptString(json_encode([...$registryFacts,'expiry'=>gmdate('Y-m-d',time()+200*86400)],JSON_THROW_ON_ERROR))];
$unknown=[...$observation,'result'=>'unknown','reason'=>'endpoint_failed','valid_until'=>null,
    'facts'=>Crypt::encryptString(json_encode(['result'=>'unknown','expiry'=>null],JSON_THROW_ON_ERROR))];
DB::table('domains_watch_registry')->delete();DB::table('domains_watch_names')->delete();
$linkedCandidate=$makeLinked('expiry-linked.example');$candidateIds=[(int)$linkedCandidate->id];
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$linkedCandidate->id]);
$watchRows=[];$factRows=[];$first=(int)$linkedCandidate->id+1;
for($i=0;$i<999;$i++){
    $wid=$first+$i;$candidateIds[]=$wid;$state=match($i){0=>'active',1=>'retired',default=>'paused'};
    $watchRows[]=[...$watchSeed,'id'=>$wid,'canonical_name'=>'expiry-candidate-'.$i.'.example','state'=>$state];
    $factRows[]=[...($i<3?$near:$far),'watch_id'=>$wid];
}
foreach(array_chunk($watchRows,100) as $rows){DB::table('domains_watch_names')->insert($rows);}
foreach(array_chunk($factRows,100) as $rows){DB::table('domains_watch_registry')->insert($rows);}
$nearIds=array_slice($candidateIds,0,4);$indicatorErrors=[];
$checkExpiry=static function(string $case,array $filters,array $expectedIds,bool $capped)use($manager,$applyFilters,$filter,&$indicatorErrors):void{
    $page=$applyFilters(Livewire::actingAs($manager)->test(ListWatches::class),[...$filters,'expiry'=>'30']);
    $instance=$page->instance();
    // Livewire drops protected scan state between requests. Read the indicator before resolving records.
    if(property_exists($instance,'expiryCapped')){(new ReflectionProperty(ListWatches::class,'expiryCapped'))->setValue($instance,null);}
    if(property_exists($instance,'expiryScans')){(new ReflectionProperty(ListWatches::class,'expiryScans'))->setValue($instance,[]);}
    DB::flushQueryLog();DB::enableQueryLog();
    $indicators=$filter($page,'expiry')->getIndicators();expect(count($indicators)===1,'One actual expiry indicator for '.$case);
    $actual=(string)$indicators[0]->getLabel();$expected='Expires within 30 days'.($capped?' (first 1,000 domains)':'');
    $instance->getTableRecords();
    $actualIds=$instance->getFilteredTableQuery()->orderBy('id')->pluck('id')->all();
    $instance->getFilteredTableQuery();
    $scans=array_filter(DB::getQueryLog(),static fn($q)=>str_contains($q['query'],'max(id)')&&str_contains($q['query'],'domains_watch_registry'));
    DB::disableQueryLog();
    same(1,count($scans),'Indicator and repeated record queries share one expiry scan: '.$case);
    echo 'Expiry indicator probe '.$case.': '.json_encode(['expected'=>$expected,'actual'=>$actual,'matches'=>count($actualIds)],JSON_THROW_ON_ERROR)."\n";
    if($actual!==$expected){$indicatorErrors[]=$case.': expected '.$expected.'; got '.$actual;}
    sort($expectedIds);sort($actualIds);if($actualIds!==$expectedIds){$indicatorErrors[]=$case.': exact expiry matches differ (expected '.json_encode($expectedIds).'; got '.json_encode($actualIds).')';}
};
$checkExpiry('1,000 facts-bearing watches in all states',[],$nearIds,false);
// Repeated history counts once; the newest facts row supplies the expiry, even after a non-facts result.
for($i=0;$i<12;$i++){DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$linkedCandidate->id]);}
DB::table('domains_watch_registry')->insert([...$far,'watch_id'=>$linkedCandidate->id]);
$checkExpiry('repeated facts use the latest expiry',[],array_slice($nearIds,1),false);
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$linkedCandidate->id]);
DB::table('domains_watch_registry')->insert([...$unknown,'watch_id'=>$linkedCandidate->id]);
$checkExpiry('new facts cycle survives a later non-facts result',[],$nearIds,false);
$orphanRows=[];for($i=0;$i<1001;$i++){$orphanRows[]=[...$near,'watch_id'=>9000000+$i];}
foreach(array_chunk($orphanRows,100) as $rows){DB::table('domains_watch_registry')->insert($rows);}
$checkExpiry('orphan facts do not consume the scan',[],$nearIds,false);
$nonfactRows=[];$nonfactObservations=[];$nonfactIds=[];
for($i=0;$i<1001;$i++){
    $wid=$first+999+$i;$nonfactIds[]=$wid;
    $nonfactRows[]=[...$watchSeed,'id'=>$wid,'canonical_name'=>'no-facts-'.$i.'.example','state'=>'active'];
    $nonfactObservations[]=[...$unknown,'watch_id'=>$wid];
}
foreach(array_chunk($nonfactRows,100) as $rows){DB::table('domains_watch_names')->insert($rows);}
foreach(array_chunk($nonfactObservations,100) as $rows){DB::table('domains_watch_registry')->insert($rows);}
$checkExpiry('1,001 active non-facts watches do not add candidates',[],$nearIds,false);
$overflow=DB::table('domains_watch_names')->insertGetId([...$watchSeed,'user_id'=>$other->id,'canonical_name'=>'overflow-expiry.example','state'=>'retired']);
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$overflow,'user_id'=>$other->id]);
$checkExpiry('1,001 distinct candidates exceed the scan',[],$nearIds,true);
$checkExpiry('customer narrows 1,001 candidates to exactly 1,000',['customer'=>['customer'=>(string)$uid]],$nearIds,false);
$checkExpiry('other customer scans its late candidate',['customer'=>['customer'=>(string)$other->id]],[(int)$overflow],false);
$checkExpiry('paused status narrows the candidate set',['status'=>'paused'],[$nearIds[3]],false);
$checkExpiry('customer and stopped status keep linked and retired',['customer'=>['customer'=>(string)$uid],'status'=>'stopped'],[$nearIds[0],$nearIds[2]],false);
$checkExpiry('other customer and stopped status scan the late candidate',['customer'=>['customer'=>(string)$other->id],'status'=>'stopped'],[(int)$overflow],false);
$checkExpiry('empty customer and status intersection',['customer'=>['customer'=>(string)$other->id],'status'=>'paused'],[],false);
// A warning is still needed when the extra candidates are stopped, with few active watches globally.
DB::table('domains_watch_names')->whereIn('id',$nonfactIds)->update(['state'=>'retired']);
$checkExpiry('few active watches still truncate historical facts',[],$nearIds,true);
// Reusing one component must still distinguish intervals and incoming filter values.
$cached=new ListWatches;$scope=static fn(int $owner)=>WatchName::where('user_id',$owner);
foreach([7,30,90] as $days){
    same([(int)$overflow],$cached->expiryQuery($scope((int)$other->id),$days)->pluck('id')->all(),'Expiry cache keeps interval '.$days.' scoped to the requested customer');
}
$ownMatches=$cached->expiryQuery($scope((int)$uid),30)->orderBy('id')->pluck('id')->all();
same($nearIds,$ownMatches,'Different customer bindings retain their own expiry matches: '.json_encode($ownMatches));
same([], $cached->expiryQuery($scope(-1),30)->pluck('id')->all(),'Different customer bindings cannot borrow cached matches');
$intervalSeed=(int)$linkedCandidate->id;
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$intervalSeed,'facts'=>Crypt::encryptString(json_encode([...$registryFacts,'expiry'=>gmdate('Y-m-d',time()+60*86400)],JSON_THROW_ON_ERROR))]);
$fresh=new ListWatches;$only=static fn()=>WatchName::whereKey($intervalSeed);
same([],$fresh->expiryQuery($only(),7)->pluck('id')->all(),'Seven-day scan excludes a sixty-day expiry');
same([],$fresh->expiryQuery($only(),30)->pluck('id')->all(),'Thirty-day scan excludes a sixty-day expiry');
same([$intervalSeed],$fresh->expiryQuery($only(),90)->pluck('id')->all(),'Ninety-day scan does not reuse a shorter interval');
DB::table('domains_watch_registry')->insert([...$near,'watch_id'=>$intervalSeed]);
same([$intervalSeed],(new ListWatches)->expiryQuery($only(),7)->pluck('id')->all(),'A new request rechecks changed expiry facts');
DB::rollBack();
expect($indicatorErrors===[],"Expiry indicator regressions:\n".implode("\n",$indicatorErrors));

$nativeSuccessful=true;echo "Domain monitoring and DNS templates pages passed.\n";
