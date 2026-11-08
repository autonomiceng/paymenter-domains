<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The real Filament render is needed to reproduce filters removing the warning's own rows.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$GLOBALS['emptyFilterSource']=new StaffCatalogue($ports,$clock);
class EmptyFilterSellPage extends WhatYouSell
{
    protected function catalogue():?StaffCatalogue{return $GLOBALS['emptyFilterSource'];}
    public function binding():?AccountBinding{return $this->account?\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$this->account)->sole()):null;}
}
Livewire::component('empty-filter-sell-page',EmptyFilterSellPage::class);
foreach(['own'=>false,'default'=>true] as $filter=>$defaults){
    DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_intent'=>'{"sell":','use_default_rule'=>$defaults]);
    $page=Livewire::actingAs($actor)->test(EmptyFilterSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false);
    $page->filterTable('show',$filter)->assertCountTableRecords(0)->assertDontSee('Prices left out');
    expect(str_contains($page->html(),'No prices to show. Some TLDs are left out.'),'Empty filter explains omissions without a missing callout pointer');
    expect(!str_contains($page->html(),'see above'),'Empty filter must not point at a missing callout');
    $page->searchTable('missing')->assertSee('No TLDs match');
}
$nativeSuccessful=true;echo "Empty price filters explain omissions without pointing at a missing callout.\n";
