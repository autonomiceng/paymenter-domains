<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The native field action fills the shared explicit request.
declare(strict_types=1);
$siteServers=['ns1.staff.example.org','ns2.staff.example.org'];
$profile=Illuminate\Support\Facades\DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create([
    'name'=>'Saved staff DNS','nameservers'=>$siteServers,'website_endpoints'=>[],'email_endpoints'=>[],
    'enabled'=>true,'edited_by'=>$staff->id,'reason'=>'Synthetic site default']));
fixtureClearBusinessChoice(\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::KEY);
fixtureBusinessChoice(\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::KEY,(string)$profile->id,'string');
$nsAction=\Filament\Actions\Testing\TestAction::make('edit_nameservers')->schemaComponent('fact_'.md5('Nameservers'),'infolist');
$nsModal=\Livewire\Livewire::actingAs($staff)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->mountAction($nsAction);
$nsRegistrar=$d->registrar_key==='enom'?'Enom':'NameSilo';
$nsModal->assertMountedActionModalSee('Registrar: '.$nsRegistrar.'.')->assertMountedActionModalSee('Use site default nameservers');
$schema=$nsModal->instance()->getSchema('mountedActionSchema0');
same(null,$schema->getComponentByStatePath('use_default'),'Staff nameserver editor has no registrar-reset toggle');
$count=$d->operations()->count();
$nsModal->callAction(\Filament\Actions\Testing\TestAction::make('fill_site_default_nameservers')->schemaComponent('nameservers','mountedActionSchema0'));
$nsModal->assertActionDataSet(['nameservers'=>implode("\n",$siteServers)]);
same($count,$d->operations()->count(),'Staff fill does not submit a change');
$profile->nameservers=['ns1.new-site.example.net','ns2.new-site.example.net'];$profile->saveQuietly();
$nsModal->callMountedAction()->assertNotified(\Paymenter\Extensions\Servers\Domains\Services\ManagementForms::REQUESTED);
$nsSaved=$d->operations()->where('action','nameservers')->latest('id')->firstOrFail();
same(['nameservers'=>$siteServers],$nsSaved->payload,'Staff save freezes the filled list even after the site default changes');
same((int)$staff->id,(int)$nsSaved->authorized_by,'The explicit change is credited to staff');
Illuminate\Support\Facades\Cache::flush();
same('succeeded',$management->run($nsSaved->id,$driver)->state,'Staff explicit change uses the synthetic registrar and readback');
