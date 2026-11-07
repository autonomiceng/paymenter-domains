<?php
// Native page regression: the TLD page head and the TLD list read one sales state.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Edit,Facts,FactsObserved,Intent,OfferState,Query};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsWrites};
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);$ports=CallerPorts::routed();
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$account=Account::where('server_id',$t->server_id)->sole();$binding=AccountBindings::binding($account);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$offer=static fn(string $action)=>$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
$head=static fn()=>trim(preg_replace('/\s+/',' ',strip_tags(Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->instance()->getSubheading()->toHtml())));
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
// The account sells only TLDs set to Sell, and .com has no choice of its own.
DB::table('domains_accounts')->where('id',$account->id)->update(['catalogue_defaults'=>OfferState::json([...OfferState::decode($account->catalogue_defaults),'defaultSell'=>false])]);
$ports->apply(Edit::override($binding,'com',new Intent(stops:[])),$offer('register')->revision);
same('Not sold by default',TldFacts::salesState($t->fresh())['reason'],'The TLD list says Not sold by default');
same('Not for sale Not sold by default',$head(),'The head says the same');
// Staff stopped .com: both say Stopped by staff.
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,stops:['register','transfer'])),$offer('register')->revision);
same('Stopped by staff',TldFacts::salesState($t->fresh())['reason'],'The TLD list says Stopped by staff');
same('Not for sale Stopped by staff',$head(),'The head says the same');
echo "TLD sales one state passed.\n";$nativeSuccessful=true;
