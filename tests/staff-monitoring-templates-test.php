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
use Paymenter\Extensions\Servers\Domains\Presentation\State;
// DNS templates.
$templates=Livewire::actingAs($manager)->test(ListTemplates::class)->assertCanSeeTableRecords([$profile])->assertActionVisible('save');$clean($templates->html(),'DNS templates');staffRowsStandard($templates);
same(['No DNS templates','Add one with Add DNS template.'],[$templates->instance()->getTable()->getEmptyStateHeading(),$templates->instance()->getTable()->getEmptyStateDescription()],'No templates says how to add one');
expect(str_starts_with((string)$templates->instance()->getTitle(),RoutingProfileResource::getNavigationLabel()),'The browser title starts with the open tab');
// the card owns the default, rather than the subheading.
$templates->assertActionHidden('siteDefault');
same('On', $column($templates, 'enabled')->record($profile)->getState(), 'The template list shows whether monitoring is enabled');
same('1', $column($templates, 'assigned')->record($profile)->getState(), 'The template list includes the assigned domain');
$template = Livewire::actingAs($manager)->test(ViewTemplate::class, ['record'=>$profile->id])->assertActionHidden('enable');
same(RoutingProfileResource::getUrl('edit', ['record'=>$profile]), $template->instance()->getAction('save')->getUrl(), 'Edit opens this template');
$off=DB::transaction(fn()=>WatchProfile::create(['name'=>'Spare template','nameservers'=>['ns1.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic spare']));
$spare=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$off->id]);same('Turn on',$spare->instance()->getAction('enable')->getLabel(),'Turn on template');
$spare->callAction('enable')->assertNotified('DNS template turned on');expect(WatchProfile::find($off->id)->enabled,'Turn on saves');

DB::table('domains_watch_product_maps')->where('id',$map->id)->update(['enabled'=>false]);
// Expiry wording past and on the date; a paused name shows no Missing DNS records.
$day=strtotime('2026-01-03 12:00:00 UTC');foreach(['2026-01-01'=>'Expired 2 days ago','2026-01-02'=>'Expired 1 day ago','2026-01-03'=>'Expires today','2026-01-04'=>'Expires in 1 day','2026-01-05'=>'Expires in 2 days'] as $date=>$text){same($text,WatchFindings::expires($date,$day),'Expiry wording '.$date);}
$acts=\Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions::watch($watch,(int)$watch->revision);$more=[];foreach(end($acts)->getActions() as $a){$more[$a->getName()]=$a->getModalSubmitActionLabel();}
same(['note'=>'Add note'],$more,'More submit labels');
DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);$paused=WatchFacts::for(WatchName::find($watch->id));expect(!array_key_exists('DNS records',$paused['Checks']),'Paused name with no record results hides DNS records, not Missing');

$nativeSuccessful=true;echo "Staff monitoring DNS templates passed\n";
