<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\State;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
// Unchecked: Monitoring, with Last check Missing and its cause.
$list=Livewire::actingAs($manager)->test(ListWatches::class)->assertCanSeeTableRecords([$watch]);$clean($list->html(),'monitoring list');
assertDomainNameOneLine($list->html(),$watch->canonical_name,'Monitoring list');
same(null,$list->instance()->getSubheading(),'No list subheading');
foreach(['canonical_name'=>'Domain','status'=>'Status','registration'=>'Registrar','delegation.dns'=>'Nameservers','last_check'=>'Last check','expiry'=>'Expires'] as $name=>$label){same($label,$column($list,$name)->getLabel(),'Column '.$name);}
foreach(['findings','user_id','state','work_due'] as $gone){same(null,$column($list,$gone),'Old column '.$gone.' removed');}
same(State::Monitored->label(),$column($list,'status')->record($watch)->getState(),'Unchecked domain is Monitored');
$cause=fn($cell)=>html_entity_decode(strip_tags((string)$cell->formatState($cell->getState())));
same(State::NotReadYet->label(),$column($list,'last_check')->record($watch)->getState(),'Last check not read yet');expect(str_contains($cause($column($list,'last_check')->record($watch)),'Fills in by itself after the next check'),'Not read yet says its cause');
same(State::NotReadYet->label(),$column($list,'registration')->record($watch)->getState(),'Registrar not read yet');expect(str_contains($cause($column($list,'registration')->record($watch)),'Fills in by itself after the next check'),'Registrar not read yet says its cause');
same('Customer #'.$uid,$column($list,'customer')->record($watch)->getState(),'Customer has its own column');same(null,$column($list,'canonical_name')->record($watch)->getDescriptionBelow(),'The name is one line');
foreach(['status'=>'Status','customer'=>'Customer','expiry'=>'Expires within'] as $name=>$label){same($label,$filter($list,$name)->getLabel(),'Filter '.$name);}
same(null,$filter($list,'attention'),'Attention filter merged into Status');same(null,$filter($list,'drift'),'Drift filter merged into Status');same(null,$filter($list,'state'),'State filter merged into Status');
// operator-pass-review-R1 The status filter includes the shared Needs attention destination.
same(['attention'=>'Needs attention','monitoring'=>State::Monitored->label(),'problem'=>State::NeedsReview->label(),'failed'=>State::Failed->label(),'paused'=>State::Paused->label(),'stopped'=>State::Stopped->label()],$filter($list,'status')->getOptions(),'Status filter options');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','paused')->assertCountTableRecords(0)->assertSee('No monitored domains match')->assertSee('Clear the search or filters to see all rows.')->assertDontSee('Turn on monitoring on a hosting product.');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('customer',['customer'=>(string)$uid])->assertCanSeeTableRecords([$watch]);

// Checked: nameservers moved away and expiry within 30 days is Problem found with the reason.
extract($checked());
$list=Livewire::actingAs($manager)->test(ListWatches::class);$record=WatchName::find($id);
same(State::NeedsReview->label(),$column($list,'status')->record($record)->getState(),'Problem found reads Needs review');
same('Nameservers changed. Expires in '.$days.' days.',$column($list,'note')->record($record)->getState(),'Note retains the observed mismatch and the expiry warning');
same('Other',$column($list,'delegation.dns')->record($record)->getState(),'Nameservers elsewhere read Other');
expect($column($list,'last_check')->record($record)->getState()!==State::NotReadYet->label(),'Last check has a time');
expect(str_contains((string)$column($list,'last_check')->record($record)->formatState($column($list,'last_check')->getState()),'data-domains-time'),'Last check shows in the viewer time');
same(null,$column($list,'expiry')->record($record)->getDescriptionBelow(),'Expires is one value; the days left are in Note');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','problem')->assertCanSeeTableRecords([$record]);
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','monitoring')->assertCanNotSeeTableRecords([$record]);

// Monitored domain page: heading pill, header buttons, sections, one notice.
$view=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);$page=$view->instance();$clean($view->html(),'monitored domain');
expect(str_contains(html_entity_decode(strip_tags($page->getHeading()->toHtml())),$watch->canonical_name) && str_contains($page->getSubheading()->toHtml(),\Paymenter\Extensions\Servers\Domains\Presentation\State::Monitored->label()),'An active watch keeps its own state in the head');
expect(str_contains(panelNoticeText($view->html()),'Nameservers changed'),'Observed mismatch leads the sheet');
$seen=preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',$view->html())),ENT_QUOTES|ENT_HTML5));
same([1,0],[substr_count($seen,'Nameservers changed'),substr_count($seen,'Nameservers changed:')],'Nameservers changed shows once, with no notice repeating the found nameservers');
same([WatchResource::getUrl(),0],array_keys($page->getBreadcrumbs()),'Breadcrumb leads back to the monitored domains list');
foreach(['read'=>'Check now','pause'=>'Pause monitoring','profile'=>'Change DNS template','note'=>'Add note'] as $name=>$label){same($label,$page->getAction($name)?->getLabel(),'Action '.$name);}
$view->assertActionHidden('resume');
foreach(['read','pause'] as $name){same(false,$page->getAction($name)->isConfirmationRequired(),$name.' has no dialog');}
$view->assertActionDoesNotExist('retire');
same('Future checks compare against this DNS template.',$page->getAction('profile')->getModalDescription(),'Template dialog body');
$view->assertSeeInOrder(['Domain','Customer','Expected nameservers','DNS template','Synthetic defaults','Registrar','Expires','Hosting service','Hosting','Latest checks','Nameservers','DNS records','Website points to','Email points elsewhere','Registry','Registered','Last check','Next check','Monitoring alerts','On','Activity','No history yet']);
$view->assertSee('Expires in '.$days.' days')->assertDontSee('Findings')->assertDontSee('Saved domain checks')->assertDontSee('Notification activity')->assertDontSee('Watch state');
$facts=WatchFacts::for($record);same(['Domain','Checks','Evidence'],array_keys($facts),'External monitoring keeps domain facts, checks and saved public evidence');expect(!isset($facts['Domain']['Registrar records'],$facts['Domain']['Billing and ownership']),'External monitoring makes no registrar or billing claim');same('Other',$facts['Domain']['Registrar']['value']??'Missing','Registrar from the saved registration');
// Last check already says when; Evidence from that same check says no second Checked time. An older answer keeps its own.
$lastAt=(int)($facts['Checks']['Last check']['at']??0);expect($lastAt>0&&isset($facts['Evidence']['Public DNS'])&&$record->points!==null,'Fixture saves public DNS and a last check');
$saved=fn(int $at)=>\Illuminate\Support\Facades\DB::table($record->points->getTable())->where('id',$record->points->id)->update(['started_at'=>$at,'completed_at'=>$at+1]);$saved($lastAt-3600);
expect(str_contains((string)WatchFacts::for(WatchName::find($id))['Evidence']['Public DNS']['sub'],'Checked'),'An older saved answer keeps its Checked time');
$saved($lastAt);
same(null,WatchFacts::for(WatchName::find($id))['Evidence']['Public DNS']['sub'],'Evidence does not repeat the Last check time');
// Separate checks seconds apart in one minute each keep their own time; only the DNS answer Last check describes drops it.
$times=fn($html)=>array_map(fn($n)=>$n->getAttribute('datetime'),iterator_to_array(storefrontDom('<div id="times">'.$html.'</div>')->query('//*[@id="times"]//time')));
$minute=(intdiv($lastAt,60)+1)*60;$registryId=(int)DB::table('domains_watch_registry')->where('watch_id',$id)->max('id');
$registry=fn(int $at)=>DB::table('domains_watch_registry')->where('id',$registryId)->update(['started_at'=>$at,'completed_at'=>$at+1]);
$registry($minute+40);$saved($minute+10);$facts=WatchFacts::for(WatchName::find($id));
same($minute+40,$facts['Checks']['Last check']['at'],'A newer registry check supplies Last check');
same([gmdate('c',$minute+10)],$times($facts['Evidence']['Public DNS']['sub']),'An earlier DNS answer in the same minute keeps its own Checked time');
$saved($minute+50);$facts=WatchFacts::for(WatchName::find($id));
same([$minute+50,null],[$facts['Checks']['Last check']['at'],$facts['Evidence']['Public DNS']['sub']],'The DNS answer that supplies Last check shows no second time');
$saved($minute-3600);same([gmdate('c',$minute-3600)],$times(WatchFacts::for(WatchName::find($id))['Evidence']['Public DNS']['sub']),'Older evidence keeps its Checked time');
// With no template to compare, saved answers fill DNS records with their own exact time, even beside a newer check in the same minute.
$registry($minute+40);$template=WatchName::find($id)->ns_profile_id;DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>null]);
$untemplated=WatchName::find($id);same(null,\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchProfile($untemplated),'No DNS template applies');
$untemplated->setRelation('points',new \Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation(['check_kind'=>'points','started_at'=>$minute+17,'completed_at'=>$minute+18,'result'=>'complete','partial'=>false,'valid_until'=>$minute+300,
    'facts'=>['queries'=>[['name'=>$untemplated->canonical_name,'type'=>'A','records'=>[['value'=>'192.0.2.10']]]]]]));
$records=WatchFacts::for($untemplated,['keys'=>[],'summary'=>null])['Checks']['DNS records'];
same('A @ → 192.0.2.10',$records['value'],'Saved answers fill DNS records without a template');
same([gmdate('c',$minute+17)],$times($records['sub']),'DNS records keeps the saved answer time');
$recordsText=trim(preg_replace('/\s+/',' ',html_entity_decode(strip_tags((string)$records['sub']),ENT_QUOTES|ENT_HTML5)));
expect(str_starts_with($recordsText,'Historical answers. Checked ')&&str_ends_with($recordsText,'. Choose a DNS template to compare these records.'),'DNS records says Checked and how to compare: '.$recordsText);
DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>$template]);$saved($lastAt);

$nativeSuccessful=true;echo "Staff monitoring list and details passed\n";
