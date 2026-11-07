<?php
// Native dialog regression: accounts without this TLD must explain why they cannot take its orders.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/renewal-initialized.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
extract(commerceFixture(initialize:initializedRenewalFixture(...)));tldPanel();pinStaffClock($now);
[$enom,$silo]=$tlds;
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
DB::table('extensions')->where('id',$silo->server_id)->update(['name'=>'Demo NameSilo OTE']);
// Replay W8: Enom has this TLD; the connected NameSilo account has no row or price for it.
DB::table('domains_prices')->where('server_id',$silo->server_id)->delete();
DB::table('domains_tlds')->where('id',$silo->id)->delete();
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$enom->id])->mountAction('seller');
$schema=$page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
$select=$schema->getComponentByStatePath('seller');
$options=$select->getOptions();
expect(!isset($options[$silo->server_id])||$select->isOptionDisabled($silo->server_id,$options[$silo->server_id]),'NameSilo without a .com price cannot be selected');
$page->assertSee('No NameSilo price for .com yet. Sync prices first.');
// A stale or forged selection reaches the command guard, not ModelNotFoundException or a generic toast.
$commands=new Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands(
    new Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$reviewClass=Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
$r=new $reviewClass($actor->id,$enom->server_id,$enom->id,'seller',$reviewClass::ticket($actor->id,$enom->server_id,$enom->id,'seller',
    Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::stamp($enom->server_id,$enom->id)),'Choose another registrar');
try{$commands->run($r,['seller'=>$silo->server_id,'register'=>true,'transfer'=>true]);expect(false,'Missing offer selection must refuse');}
catch(DomainException $error){same('No NameSilo price for .com yet. Sync prices first.',
    Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal($error,'seller',['seller'=>$silo->server_id],$enom->server_id,$enom->id),'Refusal names missing prices and its next step');}
same(0,$reads->calls,'Opening Change registrar makes no provider calls');
echo "TLD registrar options passed.\n";$nativeSuccessful=true;
