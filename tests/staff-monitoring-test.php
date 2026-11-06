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
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
// Unchecked: Monitoring, with Last check Missing and its cause.
$list=Livewire::actingAs($manager)->test(ListWatches::class)->assertCanSeeTableRecords([$watch]);$clean($list->html(),'monitoring list');
assertDomainNameOneLine($list->html(),$watch->canonical_name,'Monitoring list');
same('Domain monitoring',$list->instance()->getTitle(),'List title');same(null,$list->instance()->getSubheading(),'No list subheading');
foreach(['canonical_name'=>'Domain','status'=>'Status','registration'=>'Registrar','delegation.dns'=>'Nameservers','last_check'=>'Last check','expiry'=>'Expires'] as $name=>$label){same($label,$column($list,$name)->getLabel(),'Column '.$name);}
foreach(['findings','user_id','state','work_due'] as $gone){same(null,$column($list,$gone),'Old column '.$gone.' removed');}
same('Monitoring',$column($list,'status')->record($watch)->getState(),'Unchecked domain is Monitoring');
same('Missing',$column($list,'last_check')->record($watch)->getState(),'Last check Missing');same('Fills in by itself after the next check',$column($list,'last_check')->record($watch)->getDescriptionBelow(),'Missing says its cause');
expect(str_contains($column($list,'last_check')->record($watch)->getExtraAttributes()['class']??'','domains-line')&&preg_match('/\.domains-line\.fi-ta-text-has-descriptions\{display:flex/',file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/staff-styles.blade.php'))===1,'Missing and its cause share one line');
same('Missing',$column($list,'registration')->record($watch)->getState(),'Registrar Missing');same('Fills in by itself after the next check',$column($list,'registration')->record($watch)->getDescriptionBelow(),'Registrar Missing says its cause');
same('Customer #'.$uid,$column($list,'customer')->record($watch)->getState(),'Customer has its own column');same(null,$column($list,'canonical_name')->record($watch)->getDescriptionBelow(),'The name is one line');
foreach(['status'=>'Status','customer'=>'Customer','expiry'=>'Expires within'] as $name=>$label){same($label,$filter($list,$name)->getLabel(),'Filter '.$name);}
same(null,$filter($list,'attention'),'Attention filter merged into Status');same(null,$filter($list,'drift'),'Drift filter merged into Status');same(null,$filter($list,'state'),'State filter merged into Status');
same(['monitoring'=>'Monitoring','problem'=>'Problem found','paused'=>'Paused','stopped'=>'Stopped'],$filter($list,'status')->getOptions(),'Status filter options');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','paused')->assertCountTableRecords(0)->assertSee('No monitored domains match')->assertSee('Clear the search or filters to see all rows.')->assertDontSee('Turn on monitoring on a hosting product.');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('customer',['customer'=>(string)$uid])->assertCanSeeTableRecords([$watch]);

// Checked: nameservers moved away and expiry within 30 days is Problem found with the reason.
extract($checked());
$list=Livewire::actingAs($manager)->test(ListWatches::class);$record=WatchName::find($id);
same('Problem found',$column($list,'status')->record($record)->getState(),'Problem found pill');same('warning',$column($list,'status')->record($record)->getColor('Problem found'),'Problem found is warning');
same('Nameservers changed. Expires in '.$days.' days.',$column($list,'note')->record($record)->getState(),'Note retains the observed mismatch and the expiry warning');
same('Other',$column($list,'delegation.dns')->record($record)->getState(),'Nameservers elsewhere read Other');
expect($column($list,'last_check')->record($record)->getState()!=='Missing','Last check has a time');
expect(str_contains((string)$column($list,'last_check')->record($record)->formatState($column($list,'last_check')->getState()),'data-domains-time'),'Last check shows in the viewer time');
same(null,$column($list,'expiry')->record($record)->getDescriptionBelow(),'Expires is one value; the days left are in Note');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','problem')->assertCanSeeTableRecords([$record]);
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','monitoring')->assertCanNotSeeTableRecords([$record]);

// Monitored domain page: heading pill, header buttons, sections, one notice.
$view=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);$page=$view->instance();$clean($view->html(),'monitored domain');
expect(str_contains($page->getHeading()->toHtml(),'font-mono') && str_contains($page->getSubheading()->toHtml(),'Problem found'),'Mono name, then the status line');
assertDomainNameWraps($page->getHeading()->toHtml(),$watch->canonical_name,'Monitored domain title','title');
expect(str_contains($page->getSubheading()->toHtml(),'Nameservers changed'),'Observed mismatch leads the heading');
// r16: the head says the problem and Latest checks lists the found nameservers; no notice repeats either.
$seen=preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',$view->html())),ENT_QUOTES|ENT_HTML5));
same([1,0],[substr_count($seen,'Nameservers changed'),substr_count($seen,'Nameservers changed:')],'Nameservers changed shows once, with no notice repeating the found nameservers');
same([WatchResource::getUrl()=>'Domain monitoring','example.com'],$page->getBreadcrumbs(),'Breadcrumb');
foreach(['read'=>'Check now','pause'=>'Pause monitoring','profile'=>'Change DNS template','note'=>'Add note','retire'=>'Stop monitoring'] as $name=>$label){same($label,$page->getAction($name)?->getLabel(),'Action '.$name);}
$view->assertActionHidden('resume');
foreach(['read','pause'] as $name){same(false,$page->getAction($name)->isConfirmationRequired(),$name.' has no dialog');}
same('Stop monitoring?',$page->getAction('retire')->getModalHeading(),'Stop dialog heading');same("Checks and alerts stop. The domain won't be added again automatically.",$page->getAction('retire')->getModalDescription(),'Stop dialog body');
same('Future checks compare against this DNS template.',$page->getAction('profile')->getModalDescription(),'Template dialog body');
$view->assertSeeInOrder(['Domain','Customer','Expected nameservers','DNS template','Synthetic defaults','Registrar','Expires','Hosting service','Hosting','Latest checks','Nameservers','DNS records','Website points to','Email points elsewhere','Registry','Registered','Last check','Next check','Monitoring alerts','On','Activity','No history yet']);
$view->assertSee('Expires in '.$days.' days')->assertDontSee('Findings')->assertDontSee('Saved domain checks')->assertDontSee('Notification activity')->assertDontSee('Watch state');
$facts=WatchFacts::for($record);same(['Domain','Checks','Evidence'],array_keys($facts),'All three fact sections');same('Other',$facts['Domain']['Registrar']['value']??'Missing','Registrar from the saved registration');

$nativeSuccessful=true;echo "Staff monitoring list and details passed\n";
