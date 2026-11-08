<?php
// Native page regression: transfers the account does not sell never count as a staff stop.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Edit,Facts,FactsObserved,Intent,OfferState,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsWrites};
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);$ports=CallerPorts::routed();
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$account=Account::where('server_id',$t->server_id)->sole();$binding=AccountBindings::binding($account);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$offer=static fn(string $action)=>$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
// The account does not sell transfers; the registrar would accept 1-year transfers.
DB::table('domains_accounts')->where('id',$account->id)->update(['catalogue_defaults'=>OfferState::json([...OfferState::decode($account->catalogue_defaults),'defaultTransfers'=>false])]);
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,stops:[])),$offer('register')->revision);
same(true,$offer('register')->available,'Registrations are for sale: '.$offer('register')->reason);
$head=static fn($page)=>trim(preg_replace('/\s+/',' ',strip_tags($page->instance()->getSubheading()->toHtml())));
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id]);
same('For sale',$head($page),'A TLD for sale says For sale, not Stopped by staff');
$page->assertActionHidden('start_selling')->assertActionVisible('stop_selling');
// Stop then start: transfers stay off, as the account set them.
$page->mountAction('stop_selling')->callMountedAction()->assertHasNoActionErrors()->assertActionVisible('start_selling');
$page->mountAction('start_selling');
expect(!str_contains((string)$page->instance()->getMountedAction()->getModalContent()?->toHtml(),'Transfer')&&!str_contains($page->instance()->getMountedAction()->getModalDescription(),'transfers'),'The confirm names only registrations');
$page->callMountedAction()->assertHasNoActionErrors()->assertActionHidden('start_selling');
$current=$t->fresh();
same(false,StaffCatalogue::routed()->setting($current,'transfer'),'Start selling leaves transfers off');
same(false,(bool)(OfferState::decode($current->catalogue_intent)['transfers']??false),'Start selling does not turn transfers on');
same(true,$offer('register')->available,'Start selling reopens registrations');
same('For sale',$head($page),'The head says For sale again');
echo "TLD sales with transfers off passed.\n";$nativeSuccessful=true;
