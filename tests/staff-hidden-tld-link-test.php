<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\{Tld,Price};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();

// Twenty-five rows fill page one; the unreadable override is on page two.
$seed=$t->getRawOriginal();unset($seed['id']);
for($i=0;$i<24;$i++){DB::table('domains_tlds')->insert([...$seed,'tld'=>'pagea'.chr(97+$i)]);}
$id=DB::table('domains_tlds')->insertGetId([...$seed,'tld'=>'zzbroken','catalogue_intent'=>'{"sell":']);
$GLOBALS['hiddenLinkSource']=$source;
class HiddenLinkStaffPage extends Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell
{
    protected function catalogue():?StaffCatalogue{return $GLOBALS['hiddenLinkSource'];}
    // Blade calls this private parent helper on the concrete synthetic component.
    public function binding():?Paymenter\Extensions\Servers\Domains\Data\AccountBinding
    {return $this->account?Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$this->account)->sole()):null;}
}
Livewire\Livewire::component('hidden-link-staff-page',HiddenLinkStaffPage::class);
$page=Livewire\Livewire::actingAs($actor)->test(HiddenLinkStaffPage::class)->filterTable('for_sale',false);
same(26,$page->instance()->getTableRecords()->total(),'The omitted TLD is off the first page');
$page->assertSee('No price for .zzbroken')->assertSee('Open .zzbroken')
    ->assertSeeHtml('href="'.e(Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource::getUrl('view',['record'=>$id])).'"');
$page->searchTable('com')->assertDontSee('No price for .zzbroken')->assertDontSee('Open .zzbroken');
$nativeSuccessful=true;echo "Off-page pricing warning names and links the omitted TLD.\n";
