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
// Visibility and dispatch both use the current native staff grant.
$viewer=staffUser([Staff::VIEW]);
Livewire::actingAs($viewer)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling')->assertActionHidden('stop_selling');
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->mountAction('start_selling');
$before=$t->fresh()->catalogue_intent;
$actor->role->update(['permissions'=>[Staff::VIEW]]);$actor->unsetRelation('role');Auth::setUser($actor);
$page->callMountedAction()->assertActionHidden('start_selling');
same($before,$t->fresh()->catalogue_intent,'Revoking the grant after mount refuses the native dispatch');
echo "TLD start and stop sales passed.\n";$nativeSuccessful=true;
