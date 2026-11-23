<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-templates.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,DnsTemplate};
use Paymenter\Extensions\Servers\Domains\Services\DnsTemplateWrites as Writes;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
// DNS templates.
$templates=Livewire::actingAs($manager)->test(ListTemplates::class)->assertCanSeeTableRecords([$profile])->assertActionVisible('save');$clean($templates->html(),'DNS templates');staffRowsStandard($templates);
same(['No DNS templates','Add one with Add DNS template.'],[$templates->instance()->getTable()->getEmptyStateHeading(),$templates->instance()->getTable()->getEmptyStateDescription()],'No templates says how to add one');
expect(str_starts_with((string)$templates->instance()->getTitle(),RoutingProfileResource::getNavigationLabel()),'The browser title starts with the open tab');
// the card owns the default, rather than the subheading.
$templates->assertActionHidden('siteDefault');
same(\Paymenter\Extensions\Servers\Domains\Presentation\Plain::On->label(), $column($templates, 'enabled')->record($profile)->getState(), 'The template list shows whether the template is enabled');
$template = Livewire::actingAs($manager)->test(ViewTemplate::class, ['record'=>$profile->id])->assertActionHidden('enable');
same(RoutingProfileResource::getUrl('edit', ['record'=>$profile]), $template->instance()->getAction('save')->getUrl(), 'Edit opens this template');
$off=DB::transaction(fn()=>DnsTemplate::create(['name'=>'Spare template','nameservers'=>['ns1.spare.test'],'edited_by'=>$manager->id,'reason'=>'Synthetic spare']));
$spare=Livewire::actingAs($manager)->test(ViewTemplate::class,['record'=>$off->id]);same('Turn on',$spare->instance()->getAction('enable')->getLabel(),'Turn on template');
$spare->callAction('enable')->assertNotified('DNS template turned on');expect(DnsTemplate::find($off->id)->enabled,'Turn on saves');

$nativeSuccessful=true;echo "Staff monitoring DNS templates passed\n";
