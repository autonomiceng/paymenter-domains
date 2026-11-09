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
// A damaged saved cost and an absent one are different problems with different fixes.
$unreadable=["Seller price unreadable.","The saved NameSilo price for .com can't be read. Sync prices to replace it."];
foreach(['{'=>$unreadable,'{"action":"renew","term":"bad"}'=>$unreadable,'"invalid"'=>$unreadable,'null'=>['Seller price missing.','No NameSilo price for .com yet. Sync prices first.']] as $broken=>[$refusal,$reason]){
    if($broken==='null'){$broken=null;}
    $query()->update(['catalogue_observation'=>$broken]);
    try{
        $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$good->id])->mountAction('seller')->call('$refresh')->assertSuccessful();
        $schema=$page->instance()->getSchema($page->instance()->getMountedActionSchemaName());$select=$schema->getComponentByStatePath('seller');$options=$select->getOptions();
        expect($select->isOptionDisabled($bad->server_id,$options[$bad->server_id]),'Unreadable renewal is not usable seller evidence');
        expect(!$select->isOptionDisabled($good->server_id,$options[$good->server_id]),'A malformed sibling does not disable healthy choice');
        $review=Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;$commands=Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::class;
        $r=new $review($actor->id,$good->server_id,$good->id,'seller',$review::ticket($actor->id,$good->server_id,$good->id,'seller',$commands::stamp($good->server_id,$good->id)),'Choose this registrar for new orders');
        try{(new $commands(new Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null))->run($r,['seller'=>$bad->server_id,'register'=>true]);expect(false,'Malformed renewal evidence cannot be selected');}
        catch(DomainException $e){same($refusal,$e->getMessage(),'Staff dispatch names the price problem');}
        same($reason,Paymenter\Extensions\Servers\Domains\Admin\Support\TldRegistrar::choices($good->id)[$bad->server_id]['reason'],'Registrar choice names the price problem');
        expect(str_contains(html_entity_decode($page->effects['partials']['action-modals.0']??'',ENT_QUOTES),$reason),'Dialog gives the price problem and its fix');
        same($reason,Paymenter\Extensions\Servers\Domains\Admin\Support\TldRegistrar::refusal($e,['seller'=>$bad->server_id],$good->id),'Refused save gives the same reason');
    }catch(Throwable $e){$errors[]=$e::class.': '.$e->getMessage();}
}
expect($errors===[],implode("\n",$errors));
// The fix the reason names works: the next price sync replaces the damaged cost.
$query()->update(['catalogue_observation'=>'{']);$account=Account::where('server_id',$bad->server_id)->sole();
same(SellerAvailability::UNREADABLE,SellerAvailability::state($account,$bad->fresh()),'Damaged cost is reported as unreadable');
$b=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);$fresh=$clock->now();
$ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($b,'com',[new Paymenter\Extensions\Servers\Domains\Catalogue\Cost('renew',1,true,'8.0000',Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$fresh,$fresh->modify('+1 day'))]),
    Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::revision($bad->fresh(),Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($account->catalogue_defaults),DB::table('domains_sales_policies')->where('tld','com')->first()));
same('8.0000',Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($query()->value('catalogue_observation'))['total'],'Price sync replaces the damaged cost');
expect(SellerAvailability::hasPrices($account,$bad->fresh()),'Repaired evidence is usable');
$query()->update(['catalogue_observation'=>$saved]);
expect(SellerAvailability::hasPrices(Account::where('server_id',$bad->server_id)->sole(),$bad),'Valid evidence remains usable');
same(0,$reads->calls,'Registrar choices do not read providers');
$nativeSuccessful=true;echo "Unreadable seller prices stay isolated.\n";
