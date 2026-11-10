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
// A damaged annual cost beside readable registration and renewal costs still gets its sync step before selection.
$row=fn(object $t,string $action,int $term)=>DB::table('domains_prices')->where('server_id',$t->server_id)->where('action',$action)->where('term',$term);
$transfer=$row($bad,'transfer',1)->value('catalogue_observation');$row($bad,'transfer',1)->update(['catalogue_observation'=>'{']);
same(SellerAvailability::UNREADABLE,SellerAvailability::state(Account::where('server_id',$bad->server_id)->sole(),$bad->fresh()),'A damaged annual transfer is named even beside healthy registration and renewal');
same(['reason'=>$unreadable[1],'disabled'=>true],array_intersect_key(Paymenter\Extensions\Servers\Domains\Admin\Support\TldRegistrar::choices($good->id)[$bad->server_id],['reason'=>1,'disabled'=>1]),'Registrar choice gives the sync step for the damaged transfer');
expect(SellerAvailability::hasPrices(Account::where('server_id',$good->server_id)->sole(),$good->fresh()),'The healthy sibling account stays selectable');
$row($bad,'transfer',1)->update(['catalogue_observation'=>$transfer]);
// A feed that repairs the renewal persists although a different saved cost it leaves out is damaged. That one stays closed.
$codec=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::class;
foreach([[$bad,$good,'transfer',1],[$good,$bad,'register',3]] as [$t,$sibling,$action,$term]){
    $t=$t->fresh();$account=Account::where('server_id',$t->server_id)->sole();$b=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
    $kept=$row($t,$action,$term)->value('catalogue_observation');
    $row($t,$action,$term)->update(['catalogue_observation'=>'{']);$row($t,'renew',1)->update(['catalogue_observation'=>'{']);
    $damaged=$row($t,$action,$term)->first();$siblingPrices=DB::table('domains_prices')->where('server_id',$sibling->server_id)->orderBy('id')->get()->toJson();
    $ports->apply(new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($b,'com',[new Paymenter\Extensions\Servers\Domains\Catalogue\Cost('renew',1,true,'9.5000',Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$fresh,$fresh->modify('+1 day'))]),
        $codec::revision($t,$codec::decode($account->catalogue_defaults),DB::table('domains_sales_policies')->where('tld','com')->first()));
    $renew=$codec::query('domains_prices')->where('server_id',$t->server_id)->where('action','renew')->where('term',1)->sole();
    same(['9.5000','9.5000'],[$codec::decode($renew->catalogue_observation)['total'],$codec::money($renew->wholesale,4)],'The repaired renewal is saved and published at its exact cost: '.$t->registrar_key);
    same((array)$damaged,(array)$row($t,$action,$term)->first(),'The damaged '.$action.' cost it left out is unchanged');
    $offers=$ports->displayBatch([new Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,['com'],$action)])[0]['com']??[];
    expect(!($offers[$action.':'.$term]->decision->available??false),'The damaged '.$action.' offer stays closed');
    $sb=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$sibling->server_id)->sole());
    expect(isset($ports->displayBatch([new Paymenter\Extensions\Servers\Domains\Catalogue\Query($sb,['com'],$action)])[0]['com'][$action.':'.$term]),'The healthy sibling account still reads its '.$action.' offer');
    same($siblingPrices,DB::table('domains_prices')->where('server_id',$sibling->server_id)->orderBy('id')->get()->toJson(),'The healthy sibling account keeps its prices');
    $row($t,$action,$term)->update(['catalogue_observation'=>$kept]);
}
same(0,$reads->calls,'Registrar choices do not read providers');
$nativeSuccessful=true;echo "Unreadable seller prices stay isolated.\n";
