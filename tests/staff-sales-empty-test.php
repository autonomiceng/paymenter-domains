<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
extract(commerceFixture(true,registrars:['enom']));staffSalesFixture();tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>'{"sell":false}']);
$page=Livewire::actingAs($actor)->test(WhatYouSell::class,['account'=>$t->server_id]);
$page->assertCountTableRecords(0)->assertSee('No TLDs are for sale')->assertSee('Clear For sale to see every TLD.');
$page->searchTable('missing')->assertSee('No TLDs match')->assertDontSee('Clear For sale to see every TLD.');
$page->searchTable('')->filterTable('for_sale',false)->assertCountTableRecords(1);
DB::table('domains_prices')->where('server_id',$t->server_id)->delete();
DB::table('domains_tlds')->where('id',$t->id)->delete();
$empty=Livewire::actingAs($actor)->test(WhatYouSell::class,['account'=>$t->server_id]);
$empty->assertCountTableRecords(0)->assertSee('No prices yet')->assertSee('The first read runs by itself.')->assertSee('Sync prices');
same(0,$reads->calls,'Empty-state rendering performs no provider reads');
$nativeSuccessful=true;echo "Native sale empty states passed.\n";
