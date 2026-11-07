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
extract(commerceFixture(true,registrars:['enom']));staffSalesFixture();tldPanel();pinStaffClock($now);$ports=CallerPorts::routed();
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$account=Account::where('server_id',$t->server_id)->sole();$binding=AccountBindings::binding($account);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$offer=static fn(string $action)=>$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$offer('register')->revision);
$ports->apply(Edit::override($binding,'com',new Intent(sell:false,stops:['register','transfer'])),$offer('register')->revision);
// Saved prices identify the price account, but do not select a registrar for new orders.
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null]);
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertSuccessful();
$page->assertActionVisible('start_selling')->assertActionDisabled('start_selling');
same('true',$page->instance()->startSellingAction()->getExtraAttributes()['aria-disabled']??null,'The blocked action exposes its state');
expect(str_contains($page->instance()->getSubheading()->toHtml(),'data-beco-tone="off"'),'Not for sale has the same gray mark in the head and lists');
$page->assertSee('Choose a registrar for new orders');
$doc=Dom\HTMLDocument::createFromString($page->html(),LIBXML_NOERROR);
$registrarRows=[];
foreach($doc->querySelectorAll('.domains-row') as $row){if(str_contains($row->textContent,'Registrar for new orders')){$registrarRows[]=$row;}}
same(1,count($registrarRows),'One registrar selection row');
expect($registrarRows[0]->querySelector('[data-beco-tone="warn"]')!==null,'A missing registrar needs attention, rather than a failure mark');
$nativeSuccessful=true; echo "TLD sale controls agree with seller selection.\n";
