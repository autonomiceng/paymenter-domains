<?php
// Native page regression: Stop selling pauses sales without rewriting the TLD's transfer choice, and Start selling
// restores exactly what was sold before.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Edit,Facts,FactsObserved,Intent,OfferState,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsWrites};
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);$ports=CallerPorts::routed();
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$account=Account::where('server_id',$t->server_id)->sole();$binding=AccountBindings::binding($account);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$offer=static fn(string $action)=>$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
$intent=static fn()=>OfferState::decode($t->fresh()->catalogue_intent);
$defaults=static fn(bool $transfers)=>DB::table('domains_accounts')->where('id',$account->id)->update(['catalogue_defaults'=>OfferState::json([...OfferState::decode(Account::find($account->id)->catalogue_defaults),'defaultTransfers'=>$transfers])]);
$cycle=static function()use($actor,$t):void {
    $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id]);
    $page->mountAction('stop_selling')->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded')->assertActionVisible('start_selling');
    $page->mountAction('start_selling')->callMountedAction()->assertHasNoActionErrors()->assertActionHidden('start_selling');
};
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
// (a) .com sells transfers by its own choice; the account does not (the demo's .org).
$defaults(false);
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,transfers:true,stops:[]),null,true),$offer('register')->revision);
same(true,$offer('transfer')->available,'Transfers are for sale: '.$offer('transfer')->reason);
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id]);
$page->mountAction('stop_selling')->callMountedAction()->assertHasNoActionErrors();
same(true,$intent()['transfers']??null,'Stop selling keeps the TLD’s own transfer choice');
same(false,$offer('transfer')->available,'Stop selling ends transfers');
$page->mountAction('start_selling');
expect(str_contains($page->instance()->getMountedAction()->getModalDescription(),'transfers')&&str_contains((string)$page->instance()->getMountedAction()->getModalDescription(),'Transfer'),'The confirm names transfers again');
$page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded');
same([true,true,[]],[$intent()['sell']??null,$intent()['transfers']??null,$intent()['stops']??[]],'Start selling restores what was sold before');
same(true,$offer('transfer')->available,'Start selling reopens transfers: '.$offer('transfer')->reason);
same(true,$offer('register')->available,'Start selling reopens registrations: '.$offer('register')->reason);
// (b) .com inherits transfers from an account that doesn't sell them. Stop then Start saves no choice, so it still
// follows the account when the account turns transfers on.
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,stops:[])),$offer('register')->revision);
$cycle();
same(null,$intent()['transfers']??null,'Stop then Start saves no transfer choice');
$defaults(true);
same(true,StaffCatalogue::routed()->setting($t->fresh(),'transfer'),'.com follows the account’s transfers again');

// (c) The chosen registrar sells transfers and renewals, with no registration terms.
$terms = static function (array $transfer) use ($t, $now): void {
    $facts = new Facts(['register'=>[],'renew'=>[1],'transfer'=>$transfer],[],true,false,false,'synthetic',$now,$now->modify('+1 day'));
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::encodeFacts($facts)]);
};
$terms([1]);
$ports->apply(Edit::seller($binding,'com',['transfer'],new Intent(sell:true,longest:3,transfers:true,stops:['register'])), $offer('transfer')->revision);
$policy = static fn() => (array) DB::table('domains_sales_policies')->where('tld','com')->sole();
$before = $policy();
$transferPrice = $offer('transfer')->retail;
$renewalPrice = $offer('renew')->retail;
expect($transferPrice !== null && $renewalPrice !== null, 'The selected offer sells transfers and renewals before the pause');
$page = Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionVisible('stop_selling');
$page->mountAction('stop_selling')->callMountedAction()->assertHasNoActionErrors();
same([false,false,true], array_map(fn($action) => $offer($action)->available, ['register','transfer','renew']),
    'The pause closes transfers and keeps renewals available');
$terms([]);
Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling');
$terms([1]);
$page->assertActionVisible('start_selling')->mountAction('start_selling')->assertActionMounted('start_selling');
$description = (string) $page->instance()->getMountedAction()->getModalDescription();
expect(str_contains($description, 'Transfer '.TldFacts::money($transferPrice))
    && str_contains($description, 'Renew '.TldFacts::money($renewalPrice))
    && !str_contains($description, 'Register $'), 'Confirmation quotes only transfer and renewal prices');
$page->callMountedAction()->assertHasNoActionErrors()
    ->assertActionHidden('start_selling')->assertActionVisible('stop_selling');
same([false,true,true], array_map(fn($action) => $offer($action)->available, ['register','transfer','renew']),
    'The reviewed confirmation resumes transfers with registration still closed');
same([['register'],3,false], [$intent()['stops'],$intent()['longest'],$intent()['salesPaused']],
    'Resume preserves the registration stop and saved term, and clears the pause');
same($before, $policy(), 'Neither Stop nor Start changes the bound seller or its acquisition flags');
same(0,$reads->calls,'Stopping and starting use saved registrar evidence');
echo "TLD stop keeps transfers passed.\n";$nativeSuccessful=true;
