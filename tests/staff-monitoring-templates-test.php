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
// DNS templates.
$templates=Livewire::actingAs($manager)->test(ListTemplates::class)->assertCanSeeTableRecords([$profile])->assertActionVisible('save');$clean($templates->html(),'DNS templates');staffRowsStandard($templates);
$ns=$templates->instance()->getTable()->getColumn('nameservers');same([true,2],[$ns->canWrap(),$ns->getLineClamp()],'Nameservers wrap to 2 lines');
same(['No DNS templates','Add one with Add DNS template.'],[$templates->instance()->getTable()->getEmptyStateHeading(),$templates->instance()->getTable()->getEmptyStateDescription()],'No templates says how to add one');
same('DNS templates',$templates->instance()->getTitle(),'Templates title');
// the card owns the default, rather than the subheading.
$templates->assertSee('Default nameservers')->assertActionHidden('siteDefault');same('Add DNS template',$templates->instance()->getAction('save')->getLabel(),'Add template');
foreach(['name'=>'Template','nameservers'=>'Nameservers','enabled'=>'Status'] as $name=>$label){same($label,$column($templates,$name)->getLabel(),'Template column '.$name);}
same(null,$column($templates,'revision'),'No revision column');same('On',$column($templates,'enabled')->record($profile)->getState(),'Status On');same(null,$column($templates,'used_by'),'No Used by column');
// The list retains status marks; the detail page has a reversible monitoring switch.
expect(!str_contains($templates->html(),'role="switch"'),'Template list shows no switch');expect(preg_match('~data-domains-tone="ok"[^>]*data-domains-record="template-'.$profile->id.'"~',$templates->html())===1,'Template list marks On');
$template=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$profile->id])->assertSeeInOrder(['On','Records','Nameservers','Website records','Email records','Used by','Monitored domains'])->assertActionHidden('enable');$clean($template->html(),'DNS template');
expect(str_contains($template->html(),'role="switch"'),'Template page has a monitoring switch');
// Nothing goes beside the sheet, so it is one column with no empty rail.
expect(str_contains($template->html(),'domains-split-single') && !str_contains($template->html(),'domains-rail'),'Template page is a single-column sheet');expect(str_contains((string)$template->instance()->getSubheading()?->toHtml(),'data-domains-tone="ok"'),'Template page head marks On');
same('Synthetic defaults',$template->instance()->getTitle(),'Template title');same('Edit',$template->instance()->getAction('save')->getLabel(),'Edit template');
same([RoutingProfileResource::getUrl()=>'DNS templates','Synthetic defaults'],$template->instance()->getBreadcrumbs(),'Template breadcrumb');
$off=DB::transaction(fn()=>WatchProfile::create(['name'=>'Spare template','nameservers'=>['ns1.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic spare']));
$spare=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$off->id]);same('Turn on',$spare->instance()->getAction('enable')->getLabel(),'Turn on template');
expect(str_contains((string)$spare->instance()->getSubheading()?->toHtml(),'data-domains-tone="off"'),'Off template head marks Off');
same('Future checks use these records. Monitoring stays on if this template is on. Enter one value per line.',$spare->instance()->getAction('save')->getModalDescription(),'Edit body');
// The form is short: one note for every box, record groups headed and not boxed.
$add=$templates->instance()->getAction('save');same(['Add DNS template','Enter one value per line.',\Filament\Support\Enums\Width::Medium],[$add->getModalHeading(),$add->getModalDescription(),$add->getModalWidth()],'Add form head and width');
$form=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$off->id])->mountAction('save');same('Edit DNS template',$form->instance()->getMountedAction()->getModalHeading(),'Edit form heading');
$html=$form->html().implode('',array_values($form->effects['partials']??[]));expect(!str_contains($html,'One per line.'),'No note under each box');expect(substr_count($html,'fi-fieldset-not-contained')===2,'Record groups are not boxed');
$spare->callAction('enable')->assertNotified('DNS template turned on');expect(WatchProfile::find($off->id)->enabled,'Turn on saves');

DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);
// Expiry wording past and on the date; a paused name shows no Missing DNS records.
$day=strtotime('2026-01-03 12:00:00 UTC');foreach(['2026-01-01'=>'Expired 2 days ago','2026-01-02'=>'Expired 1 day ago','2026-01-03'=>'Expires today','2026-01-04'=>'Expires in 1 day','2026-01-05'=>'Expires in 2 days'] as $date=>$text){same($text,WatchFindings::expires($date,$day),'Expiry wording '.$date);}
$acts=\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions::watch($watch,(int)$watch->revision);$more=[];foreach(end($acts)->getActions() as $a){$more[$a->getName()]=$a->getModalSubmitActionLabel();}
same(['profile'=>'Save','note'=>'Add note'],$more,'More submit labels');
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);$paused=WatchFacts::for(WatchName::find($watch->id));expect(!array_key_exists('DNS records',$paused['Checks']),'Paused name with no record results hides DNS records, not Missing');

$nativeSuccessful=true;echo "Staff monitoring DNS templates passed\n";
