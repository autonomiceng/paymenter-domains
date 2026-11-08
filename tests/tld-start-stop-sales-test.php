<?php
// Native page regression: the TLD page head starts and stops sales itself.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Edit,Facts,FactsObserved,Intent,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsWrites};
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);$ports=CallerPorts::routed();
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$binding=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$offer=static fn(string $action)=>$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
// Independent synthetic registrar terms, as the registrar would report them.
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
$ports->apply(Edit::override($binding,'com',new Intent(sell:false,stops:['register','renew','transfer'])),$offer('register')->revision);
$head=static fn($page)=>trim(preg_replace('/\s+/',' ',strip_tags($page->instance()->getSubheading()->toHtml())));
// Stopped: Start selling is the primary button, and its confirm names the prices going on sale.
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionVisible('start_selling')->assertActionHidden('stop_selling')->assertDontSee('Open in What you sell');
same('primary',$page->instance()->startSellingAction()->getColor(),'Start selling is the one primary action');
expect(str_contains($head($page),'Not for sale')&&str_contains($head($page),'Stopped by staff'),'The head says the state once, with its reason');
$page->mountAction('start_selling');
expect(str_contains((string)$page->instance()->getMountedAction()->getModalContent()?->toHtml(),'Register'),'The confirm lists the prices that go on sale');
$page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded')->assertActionHidden('start_selling');
same(true,$offer('register')->available,'Start selling makes registrations purchasable: '.$offer('register')->reason);
// For sale: no Start selling; Stop selling ends new orders and keeps renewals.
$page->assertActionVisible('stop_selling');
same('For sale',$head($page),'The head says For sale and nothing else');
$page->mountAction('stop_selling')->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded');
$catalogue=StaffCatalogue::routed();$current=$t->fresh();
same([false,true,false],array_map(fn($key)=>$catalogue->setting($current,$key),['register','renew','transfer']),'Stop selling keeps renewals');
same(false,$offer('register')->available,'Stop selling ends registrations');
$page->assertActionVisible('start_selling')->assertActionHidden('stop_selling');
// A pause must not rewrite independently saved transfer and term choices.
$failures=[];
$case=static function(string $label,Closure $body)use(&$failures):void{try{$body();echo "PASS contract: $label\n";}catch(Throwable $e){$failures[]=$label.': '.$e->getMessage();echo "FAIL contract: ".end($failures)."\n";}};
$reset=static function(array $intent,bool $sellerTransfers,int $defaultLongest=1)use($t,$binding,$ports,$offer):void{
    $a=Account::where('server_id',$binding->serverId)->sole();$defaults=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($a->catalogue_defaults);$defaults['defaultLongest']=$defaultLongest;
    Illuminate\Support\Facades\DB::table('domains_accounts')->where('id',$a->id)->update(['catalogue_defaults'=>json_encode($defaults)]);
    Illuminate\Support\Facades\DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>json_encode($intent)]);
    Illuminate\Support\Facades\DB::table('domains_sales_policies')->where('tld','com')->update(['transfer_enabled'=>$sellerTransfers]);
    $ports->apply(Edit::republish($binding,'com'),$offer('register')->revision);
};
$cycle=static function()use($actor,$t):void{
    Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('stop_selling')->callMountedAction()->assertNotified('Change recorded');
    Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('start_selling')->callMountedAction()->assertNotified('Change recorded');
};
foreach([[false,[],false],[true,['transfer'],false],[true,[],true]]as[$sellerTransfers,$stops,$restored]){
    $case('transfer restrictions '.json_encode([$sellerTransfers,$stops]),static function()use($reset,$cycle,$catalogue,$t,$sellerTransfers,$stops,$restored,$offer):void{
        $reset(['sell'=>true,'transfers'=>true,'stops'=>$stops],$sellerTransfers);
        $description=Livewire::test(ViewTld::class,['record'=>$t->id])->instance()->startSellingAction()->getModalDescription();
        same($restored,str_contains((string)$description,'registrations and transfers'),'Confirmation describes only permitted transfers');
        $cycle();$cycle();
        same($sellerTransfers,(bool)Illuminate\Support\Facades\DB::table('domains_sales_policies')->where('tld','com')->value('transfer_enabled'),'Seller transfer choice is unchanged');
        same($stops,json_decode($t->fresh()->catalogue_intent,true)['stops'],'Independent stops survive repeat cycles');
        same($restored,$offer('transfer')->available,'Transfer admission follows the independent restrictions');
        same(true,$offer('renew')->available,'Renewals remain available');
    });
}
foreach([10,null]as$longest){
    $case('saved longest '.json_encode($longest),static function()use($reset,$cycle,$t,$longest,$catalogue,$binding,$ports,$now,$offer):void{
        $reset(['sell'=>true,'longest'=>$longest,'transfers'=>false],false,10);$cycle();
        same($longest,json_decode($t->fresh()->catalogue_intent,true)['longest']??null,'Start preserves explicit or inherited longest choice');
        same(10,$catalogue->setting($t->fresh(),'max_years'),'Saved ten-year preference stays ten');
        same(false,$ports->readOffers(new Query($binding,['com'],'register',10),Context::Staff)[0]->available,'Unsupported checkout term stays closed');
        $ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3,10],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
        same(10,$catalogue->setting($t->fresh(),'max_years'),'Restored registrar support keeps the preference');
        $ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
    });
}
$case('conditional confirmation',static function()use($reset,$t):void{
    $reset(['sell'=>false],false);
    foreach([null,'{}']as$value){
        Illuminate\Support\Facades\DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_published_revision'=>$value]);
        $description=(string)Livewire::test(ViewTld::class,['record'=>$t->id])->instance()->startSellingAction()->getModalDescription();
        expect(!str_contains($description,'open at once')&&str_contains($description,'availability checks'),'Confirmation never promises immediate availability');
    }
});
$case('term-limit recovery',static function()use($reset,$t,$catalogue):void{
    $reset(['sell'=>true,'longest'=>1],false);
    $step=$catalogue->recovery(collect([$t->fresh()]),'register',3)[$t->server_id.':com'];
    expect(str_contains($step['next'],'Edit terms')&&!str_contains($step['next'],'Start selling'),'Term-limit recovery names the available editor');
});
expect($failures===[],implode("\n",$failures));
$reset(['sell'=>false],false);

// Visibility and dispatch both use the current native staff grant.
$viewer=staffUser([Staff::VIEW]);
Livewire::actingAs($viewer)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling')->assertActionHidden('stop_selling');
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('start_selling');
$before=$t->fresh()->catalogue_intent;
$actor->role->update(['permissions'=>[Staff::VIEW]]);$actor->unsetRelation('role');Auth::setUser($actor);
$page->callMountedAction();
same($before,$t->fresh()->catalogue_intent,'Revoking the grant after mount refuses the native dispatch');
Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling')->assertActionHidden('stop_selling');
echo "TLD start and stop sales passed.\n";$nativeSuccessful=true;
