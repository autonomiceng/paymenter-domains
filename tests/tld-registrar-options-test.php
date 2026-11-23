<?php
// Native dialog regression: accounts without this TLD must explain why they cannot take its orders.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Facts,OfferState,Query,StaffCatalogue,CatalogueActivation};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,PricingRule};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;

extract(commerceFixture(ending:'abc'));staffSalesFixture();tldPanel();pinStaffClock($now);
[$enom,$silo]=$tlds;
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
DB::table('extensions')->where('id',$enom->server_id)->update(['name'=>'Demo Enom TEST']);
DB::table('extensions')->where('id',$silo->server_id)->update(['name'=>'Demo NameSilo OTE']);
// Replay the retained .abc picker. NameSilo has other TLDs on the account, but no .abc price or record.
$renameSilo=static function(string $from,string $to)use($silo):void {
    DB::table('domains_prices')->where('server_id',$silo->server_id)->where('tld',$from)->update(['tld'=>$to]);
    DB::table('domains_tlds')->where('id',$silo->id)->update(['tld'=>$to]);
};
$renameSilo('abc','net');
DB::table('domains_tlds')->where('id',$enom->id)->update(['catalogue_facts'=>OfferState::encodeFacts(
    new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,true,false,'synthetic',$now,$now->modify('+1 day')))]);
$feed=static function(array $work=[],array $result=[])use($silo,$now):void {
    DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$silo->server_id],[
        'account_fingerprint'=>$silo->account_fingerprint,
        'offer_work'=>OfferState::json(['catalogue_generation'=>CatalogueActivation::generation(),'feed_due'=>$now->modify('+1 day')->getTimestamp(),...$work]),
        'offer_feed_succeeded_at'=>null,'offer_feed_failed_at'=>null,'offer_feed_failure'=>null,...$result]);
};
$feed();
$open=static fn($user)=>Livewire::actingAs($user)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful();
$picker=static fn($page)=>$page->mountAction('seller')->call('$refresh')->assertSuccessful();
$modal=static fn($page)=>$page->effects['partials']['action-modals.0']??'';
$link='href="'.e(RegistrarAccount::getUrl(['account'=>$silo->server_id])).'"';
$blocked=$open($actor)->assertActionHidden('start_selling');
expect(str_contains($blocked->html(),$link),'Sales recovery opens the NameSilo account with Import rights and a price reader. '.TldFacts::salesState($enom->fresh())['reason']);
$rule=PricingRule::validate(['mode'=>'fixed','round_up'=>'none','fixed'=>['register'=>'5','renew'=>'10','transfer'=>'5']]);
$held=collect(StaffCatalogue::routed()->preview($enom->fresh(),$rule))->firstWhere('action','register');
same(['5.00','unsupported_facts'],[$held['new'],$held['hold']],'Enom terms keep a below-cost registration off sale');
$preview=(new ReflectionMethod(SettingsActions::class,'preview'))->invoke(null,$enom->id,$rule)->toHtml();
expect(str_contains($preview,'needs nameservers at registration'),'Edit prices discloses the requirement that blocks registration');
expect(!str_contains($preview,'Change registrar'),'Edit prices does not recommend a removal-only registrar action');
$page=$picker($blocked);
$schema=$page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
$select=$schema->getComponentByStatePath('seller');$options=$select->getOptions();
foreach([$enom,$silo] as $t){
    expect($select->isOptionDisabled($t->server_id,$options[$t->server_id]),$t->registrar_key.' cannot be selected for .abc');
}
expect(str_contains($modal($page),'needs nameservers at registration'),'The dialog names the Enom terms refusal');
expect(str_contains($modal($page),'prices for .abc'),'The dialog names the NameSilo price refusal');
expect(str_contains($modal($page),$link),'The dialog links to the applicable account refresh');
expect($page->instance()->getMountedAction()->getModalSubmitAction()->isDisabled(),'The blocked stored seller cannot be resubmitted');

foreach([
    [Staff::VIEW,Staff::POLICY,Review::permission('seller')],
    [Staff::VIEW,Review::permission('import')],
    [Staff::VIEW,Staff::POLICY,Review::permission('import')],
] as $rights){
    $reader=staffUser($rights);
    $view=$open($reader);
    if(in_array(Review::permission('seller'),$rights,true)){
        $view->assertDontSeeHtml($link);
        expect(!str_contains($modal($picker($view)),$link),'Seller permission alone cannot offer a refresh');
    }elseif(in_array(Staff::POLICY,$rights,true)){
        $view->assertActionHidden('seller')->assertSeeHtml($link);
    }else{
        $view->assertActionHidden('seller')->assertDontSeeHtml($link);
    }
}
config(['domains.namesilo_test.catalogue'=>false]);
$open($actor)->assertDontSeeHtml($link);
config(['domains.namesilo_test.catalogue'=>true]);
$feed(['feed_due'=>$now->getTimestamp()]);
$open($actor)->assertDontSeeHtml($link);
$feed(['tries'=>1],['offer_feed_failure'=>'read_deadline','offer_feed_failed_at'=>$now->getTimestamp()]);
$open($actor)->assertDontSeeHtml($link)->assertSee("Registrar didn't answer in time");
$feed([],['offer_feed_succeeded_at'=>$now->getTimestamp()]);
$completed=$open($actor)->assertSeeHtml($link)->assertSee('prices for .abc')->assertActionHidden('start_selling');
expect(str_contains($modal($picker($completed)),$link),'A completed account sync still offers a manual recheck in the dialog');
$open(staffUser([Staff::VIEW,Staff::POLICY,Review::permission('seller')]))->assertDontSeeHtml($link);

// A stale or forged selection reaches the command guard, not ModelNotFoundException or a generic toast.
$commands=new Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands(
    new Paymenter\Extensions\Servers\Domains\Catalogue\Writer(new Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$reviewClass=Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class;
$r=new $reviewClass($actor->id,$enom->server_id,$enom->id,'seller',$reviewClass::ticket($actor->id,$enom->server_id,$enom->id,'seller',
    Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::stamp($enom->server_id,$enom->id)),'Choose another registrar');
try{$commands->run($r,['seller'=>$silo->server_id,'register'=>true,'transfer'=>true]);expect(false,'Missing offer selection must refuse');}
catch(DomainException $error){
    same('Seller offer missing.',$error->getMessage(),'The writer refuses a forged seller with no offer');
    expect(str_contains(SettingsActions::refusal($error,'seller',['seller'=>$silo->server_id],$enom->server_id,$enom->id),'prices for .abc'),'The refusal names the missing offer');
}
// An unrecognized registrar refusal on a TLD that still exists gives the safe next step, never its private text.
foreach([new DomainException('Unrecognized private seller detail'),new RuntimeException('Unrecognized private seller detail')] as $error){
    foreach([$silo->server_id,$enom->server_id,0] as $seller){
        $text=Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal($error,'seller',['seller'=>$seller],$enom->server_id,$enom->id);
        same('The registrar change could not be saved. Reopen Change registrar and try again.',$text,'Unknown seller refusal on an existing TLD: '.get_class($error).' '.$seller);
        expect(!str_contains($text,'private'),'Unknown seller refusal hides its private text');
    }
}
// The alternate must offer the selected actions in its credential-bound TEST protocol terms.
$renameSilo('net','abc');
$savedSilo=$silo->fresh();
$account=Account::where('server_id',$silo->server_id)->sole();
$siloDefaults=$account->catalogue_defaults;
DB::table('domains_accounts')->where('id',$account->id)->update(['catalogue_defaults'=>OfferState::json(['defaultSell'=>false,'defaultLongest'=>1])]);
DB::table('domains_tlds')->where('id',$silo->id)->update(['catalogue_intent'=>OfferState::json([
    ...OfferState::decode($savedSilo->catalogue_intent),'sell'=>null,'longest'=>null,
])]);
$protocol=config('domains.namesilo_test.accounts');
$binding=AccountBindings::binding($account);
$terms=static function(array $register)use($binding,$silo,$now):void{
    $facts=fixtureNameSiloFacts($binding,'abc',new Facts(['register'=>$register,'renew'=>[1],'transfer'=>[]],
        [],true,false,false,'synthetic',$now,$now->modify('+1 day')));
    DB::table('domains_tlds')->where('id',$silo->id)->update(['catalogue_facts'=>OfferState::encodeFacts($facts)]);
};
$option=static function($page)use($silo):bool{
    $livewire=$page->instance();
    $select=$livewire->getSchema($livewire->getMountedActionSchemaName())->getComponentByStatePath('seller');
    return $select->isOptionDisabled($silo->server_id,$select->getOptions()[$silo->server_id]);
};
$terms([]);
$ineligible=$open($actor);
expect(!collect($ineligible->instance()->getCachedHeaderActions())->contains(fn($action)=>$action instanceof \Filament\Actions\Action&&$action->getName()==='seller'),
    'Unavailable acquisition terms do not promote Change registrar to the header');
expect($option($picker($ineligible)),'An account with no acquisition path cannot be selected');
$terms([1]);
$registrationOnly=$picker($open($actor));
expect($option($registrationOnly),'A registration-only registrar cannot take the proposed transfers');
$registrationOnly->set('mountedActions.0.data.transfer',false);
expect(!$option($registrationOnly),'Turning off transfers makes the one-year registration path selectable');
config(['domains.namesilo_test.accounts'=>$protocol]);
DB::table('domains_accounts')->where('id',$account->id)->update(['catalogue_defaults'=>$siloDefaults]);
DB::table('domains_tlds')->where('id',$silo->id)->update([
    'catalogue_facts'=>$savedSilo->catalogue_facts,'catalogue_intent'=>$savedSilo->catalogue_intent,
]);
$renameSilo('abc','net');

// The blocked dialog only allows an explicit choice of None to clear the existing policy.
$remove=$picker($open($actor))->set('mountedActions.0.data.seller',null)->set('mountedActions.0.data.reason','Remove the blocked seller');
expect(!$remove->instance()->getMountedAction()->getModalSubmitAction()->isDisabled(),'Selecting None allows the seller to be cleared');
$remove->callMountedAction()->assertHasNoActionErrors();
same(null,TldFacts::sellerId('abc'),'The removal clears the seller policy for new orders');
$noSeller=$picker($open($actor)->assertActionDisabled('start_selling'));
same(null,$noSeller->instance()->getMountedAction()->getModalSubmitAction(),'No seller and no eligible account leaves an informational dialog');

// A valid price path with pending terms can still record a reviewed choice.
$renameSilo('net','abc');
DB::table('domains_tlds')->where('id',$silo->id)->update(['catalogue_facts'=>null]);
$pick=$picker($open($actor)->assertActionVisible('seller'));
$schema=$pick->instance()->getSchema($pick->instance()->getMountedActionSchemaName());
$select=$schema->getComponentByStatePath('seller');
expect(!$select->isOptionDisabled($silo->server_id,$select->getOptions()[$silo->server_id]),'NameSilo with eligible saved prices is selectable');
$pick->set('mountedActions.0.data.seller',(string)$silo->server_id)
    ->set('mountedActions.0.data.register',true)->set('mountedActions.0.data.transfer',false)
    ->set('mountedActions.0.data.reason','Select the alternate registrar')->callMountedAction()->assertHasNoActionErrors()
    ->assertRedirect(TldResource::getUrl('view',['record'=>$silo->id]));
same($silo->server_id,TldFacts::sellerId('abc'),'The alternate becomes the seller for new orders');
$binding=Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
$decision=$ports->readOffers(new Query($binding,['abc'],'register'),Context::Staff)[0];
same([false,'unknown_facts'],[$decision->available,$decision->reason],'Choosing a registrar with saved prices leaves registration pending independent terms');
same(0,$reads->calls,'Viewing the picker and changing the seller make no provider calls');
echo "TLD registrar options passed.\n";$nativeSuccessful=true;
