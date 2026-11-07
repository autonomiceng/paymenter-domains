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
$catalogue=Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class,['account'=>$t->server_id])->assertSuccessful();
expect(!$catalogue->instance()->getTableRecords()->contains('tld','com'),'What you sell initially excludes a TLD that is not for sale');
$catalogue->filterTable('for_sale',false);
$catalogue->set('tableDeferredFilters',$catalogue->get('tableFilters'))->call('applyTableFilters')->assertSuccessful();
expect($catalogue->instance()->getTableRecords()->contains('tld','com'),'Clearing For sale lets staff configure stopped TLDs');
$nativeSuccessful=true; echo "What you sell defaults to sale offers.\n";
