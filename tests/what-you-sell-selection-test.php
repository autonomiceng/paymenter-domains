<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture: What you sell must show the rows Select all picks, say what a bulk save will change and did change,
// and refuse a Sell that no registrar would act on.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$GLOBALS['selectionSource']=new StaffCatalogue($ports,$clock);
class SelectionSellPage extends WhatYouSell
{
    protected function catalogue():?StaffCatalogue{return $GLOBALS['selectionSource'];}
    public function binding():?AccountBinding{return $this->account?\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$this->account)->sole()):null;}
}
Livewire::component('selection-sell-page',SelectionSellPage::class);
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
Paymenter\Extensions\Servers\Domains\Catalogue\OfferScheduler::routed();
$writes=new class($native,$sellers,$bindings) extends StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$writes->commands=$commands;app()->instance(StaffSettingsWrites::class,$writes);
foreach(['net','org','info'] as $ending){$copy=$tlds[0]->fresh()->replicate();$copy->tld=$ending;$copy->catalogue_intent=null;$copy->save();}
DB::table('domains_sales_policies')->whereIn('tld',['net','org','info'])->delete();
$notes=static function():array{$n=new \Filament\Notifications\Livewire\Notifications;$n->mount();return $n->notifications->map(static fn($x)=>[$x->getTitle(),$x->getBody()])->values()->all();};
$rows=static fn($page)=>collect($page->instance()->getTableRecords()->items())->pluck('tld')->sort()->values()->all();
$page=Livewire::actingAs($actor)->test(SelectionSellPage::class,['account'=>$binding->serverId]);
same(['com'],$rows($page),'The page opens on TLDs for sale');
// Removing the filter must show every row Select all would pick, not the rows from before.
$page->call('removeTableFilters');
same(['com','info','net','org'],$rows($page),'Rows shown after removing the filter are the rows Select all picks');
same(4,$page->instance()->getAllSelectableTableRecordsCount(),'Select all counts the rows shown');
expect(str_contains($page->html(),'.net'),'The page shows the rows the filter no longer hides');
// The dialog names what a bulk save will change.
$ids=Tld::where('server_id',$binding->serverId)->orderBy('id')->pluck('id')->all();
$page->mountTableBulkAction('edit',array_slice($ids,0,3))->call('$refresh');$modal=$page->effects['partials']['action-modals.0']??'';
expect(str_contains($modal,'Saves these 3 TLDs: .com, .net, .org.'),'Bulk dialog names the TLDs it saves');
$saveOff=static function(string $modal):bool{preg_match_all('/<button\b[^>]*>.*?<\/button>/s',$modal,$m);$save=array_values(array_filter($m[0],static fn($b)=>str_contains($b,'Save terms')));same(1,count($save),'Dialog has one Save terms button');return str_contains($save[0],'aria-disabled="true"');};
expect(!$saveOff($modal),'Save is open for a selection it can save');
for($i=0;$i<23;$i++){$copy=$tlds[0]->fresh()->replicate();$copy->tld='bulk'.chr(97+$i);$copy->catalogue_intent=null;$copy->save();}
$many=Tld::where('server_id',$binding->serverId)->orderBy('id')->pluck('id')->all();same(27,count($many),'Fixture has more TLDs than one save takes');
$wide=Livewire::actingAs($actor)->test(SelectionSellPage::class,['account'=>$binding->serverId])->call('removeTableFilters');
$wide->mountTableBulkAction('edit',$many)->call('$refresh');$modal=$wide->effects['partials']['action-modals.0']??'';
expect(str_contains($modal,'You selected 27 TLDs. Edit selected saves 25 or fewer at a time.'),'Bulk dialog says before saving that the selection is too large');
expect($saveOff($modal),'Save is off for a selection it would refuse');
// Sell with no registrar for new orders is refused with its reason and saves nothing.
$net=Tld::where('server_id',$binding->serverId)->where('tld','net')->sole();$before=StaffCommands::stamp($binding->serverId);
session()->forget('filament.notifications');
$page=Livewire::actingAs($actor)->test(SelectionSellPage::class,['account'=>$binding->serverId])->call('removeTableFilters');
$page->callTableBulkAction('edit',[$net->id],['choice'=>'sell','longest'=>'keep','transfer'=>'keep']);
same([["Couldn't save",'No registrar takes its new orders yet, so Sell would do nothing. Use Start selling on its TLD page to choose one. Nothing was saved.']],$notes(),'Sell without a registrar is refused with its reason');
same(null,OfferState::decode($net->fresh()->catalogue_intent)['sell']??null,'Refused Sell leaves the choice unchanged');same($before,StaffCommands::stamp($binding->serverId),'Refused Sell writes nothing');
// A save says what it did, not that sales start.
session()->forget('filament.notifications');
$page=Livewire::actingAs($actor)->test(SelectionSellPage::class,['account'=>$binding->serverId]);
$page->callTableBulkAction('edit',[$tlds[0]->id],['choice'=>'skip','longest'=>'keep','transfer'=>'keep']);
same([['Saved','.com is no longer for sale.']],$notes(),"Don't sell says the TLD stopped selling");
$nativeSuccessful=true;
