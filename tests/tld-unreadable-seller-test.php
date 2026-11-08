<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\SellerAvailability;
use Paymenter\Extensions\Servers\Domains\Models\Account;
extract(commerceFixture());tldPanel();pinStaffClock($now);[$good,$bad]=$tlds;
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$query=fn()=>DB::table('domains_prices')->where('server_id',$bad->server_id)->where('action','renew')->where('term',1);
$saved=$query()->value('catalogue_observation');$errors=[];
foreach(['{','{"action":"renew","term":"bad"}'] as $broken){
    $query()->update(['catalogue_observation'=>$broken]);
    try{
        $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$good->id])->mountAction('seller')->assertSuccessful();
        $schema=$page->instance()->getSchema($page->instance()->getMountedActionSchemaName());$select=$schema->getComponentByStatePath('seller');$options=$select->getOptions();
        expect($select->isOptionDisabled($bad->server_id,$options[$bad->server_id]),'Unreadable renewal is not usable seller evidence');
        expect(!$select->isOptionDisabled($good->server_id,$options[$good->server_id]),'A malformed sibling does not disable healthy choice');
        expect(str_contains($page->effects['partials']['action-modals.0']??'','No NameSilo price for .com yet.'),'Dialog gives the missing-price reason');
    }catch(Throwable $e){$errors[]=$e::class.': '.$e->getMessage();}
}
$query()->update(['catalogue_observation'=>$saved]);
expect(SellerAvailability::hasPrices(Account::where('server_id',$bad->server_id)->sole(),$bad),'Valid evidence remains usable');
same(0,$reads->calls,'Registrar choices do not read providers');
expect($errors===[],implode("\n",$errors));$nativeSuccessful=true;echo "Unreadable seller prices stay isolated.\n";
