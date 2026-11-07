<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture is needed for the real Filament query, warning, money cells and bulk-save refusal.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsWrites};
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$GLOBALS['intentSource']=new StaffCatalogue($ports,$clock);
class UnreadableIntentSellPage extends WhatYouSell
{
    protected function catalogue():?StaffCatalogue{return $GLOBALS['intentSource'];}
    // As in offer-staff-reads-test, Blade needs this private base helper on the concrete fixture component.
    public function binding():?AccountBinding{return $this->account?\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$this->account)->sole()):null;}
}
Livewire::component('unreadable-intent-sell-page',UnreadableIntentSellPage::class);
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$writes=new class($native,$sellers,$bindings) extends StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$writes->commands=$commands;app()->instance(StaffSettingsWrites::class,$writes);
$broken=$tlds[0]->fresh()->replicate();$broken->tld='invalid';$broken->save();
foreach(['{"sell":','"invalid"',OfferState::json(['sell'=>[]]),OfferState::json(['longest'=>11])] as $bad){
    DB::table('domains_tlds')->where('id',$broken->id)->update(['catalogue_intent'=>$bad]);
    $before=StaffCommands::stamp($binding->serverId);
    $page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId,'tableFilters'=>['for_sale'=>['isActive'=>false]]])->assertSuccessful()->assertCountTableRecords(2)->assertSee('7.80');
    same([$broken->id],array_column($page->instance()->savedPrices()['skipped'],'id'),'Only unreadable intent is left out');
    $dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
    same(".invalid on Synthetic has no price: its custom pricing can't be read. Open .invalid and use Edit prices.",
        $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Page retains the named warning and existing repair wording');
    $records=collect($page->instance()->getTableRecords()->items());
    $column=$page->instance()->getTable()->getColumn('register_price');
    same('7.80',$column->record($records->firstWhere('tld','com'))->getState(),'Healthy TLD keeps its exact displayed price');
    same('Left out',$column->record($records->firstWhere('tld','invalid'))->getState(),'Unreadable TLD displays an explicit omission');
    same($before,StaffCommands::stamp($binding->serverId),'Opening the page changes no saved intent or publication');
    $page->callTableBulkAction('edit',[$broken->id],['choice'=>'sell'])->assertNotified("Couldn't save");
    same($before,StaffCommands::stamp($binding->serverId),'Bulk save refuses the broken TLD without writing it');
}
// Select-all can exceed the command's 25-TLD review boundary; refuse before entering the write.
$ids=[];
for($i=0;$i<26;$i++){$copy=$tlds[0]->fresh()->replicate();$copy->tld='bulk'.chr(97+$i);$copy->save();$ids[]=$copy->id;}
$before=StaffCommands::stamp($binding->serverId);
$page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId,'tableFilters'=>['for_sale'=>['isActive'=>false]]]);
$page->callTableBulkAction('edit',$ids,['choice'=>'skip'])->assertNotified(\Filament\Notifications\Notification::make()->warning()
    ->title('Pick at most 25 TLDs')->body('Select 25 or fewer TLDs, then try again.'));
same($before,StaffCommands::stamp($binding->serverId),'Oversized selection never changes intent, prices or audit');
$page->callTableBulkAction('edit',array_slice($ids,0,25),['choice'=>'skip'])->assertNotified('Saved');
foreach(array_slice($ids,0,25) as $id){same(false,OfferState::decode(\Paymenter\Extensions\Servers\Domains\Models\Tld::find($id)->catalogue_intent)['sell'],'Exactly 25 selected TLDs save');}
same(true,OfferState::decode(\Paymenter\Extensions\Servers\Domains\Models\Tld::find($ids[25])->catalogue_intent)['sell'],'Unselected TLD keeps its choice');
same(0,$reads->calls,'Page reads and refused saves make no provider calls');
echo "What you sell isolates unreadable intent, names the TLD and refuses its save.\n";$nativeSuccessful=true;
