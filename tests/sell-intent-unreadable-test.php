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
// Keep the genuine installed scheduler generation before replacing this isolated write port.
Paymenter\Extensions\Servers\Domains\Catalogue\OfferScheduler::routed();
$writes=new class($native,$sellers,$bindings) extends StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$writes->commands=$commands;app()->instance(StaffSettingsWrites::class,$writes);
$broken=$tlds[0]->fresh()->replicate();$broken->tld='invalid';$broken->save();
foreach(['{"sell":','"invalid"',OfferState::json(['sell'=>[]]),OfferState::json(['longest'=>11])] as $bad){
    DB::table('domains_tlds')->where('id',$broken->id)->update(['catalogue_intent'=>$bad]);
    $before=StaffCommands::stamp($binding->serverId);
    $page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false)->assertSuccessful()->assertCountTableRecords(2)->assertSee('7.80');
    same([$broken->id],array_column($page->instance()->savedPrices()['skipped'],'id'),'Only unreadable intent is left out');
    $dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
    same(".invalid on Synthetic has prices left out: its custom pricing can't be read. Open .invalid and use Edit prices.",
        $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Page retains the named warning and existing repair wording');
    $records=collect($page->instance()->getTableRecords()->items());
    $column=$page->instance()->getTable()->getColumn('register_price');
    same('7.80',$column->record($records->firstWhere('tld','com'))->getState(),'Healthy TLD keeps its exact displayed price');
    same('Left out',$column->record($records->firstWhere('tld','invalid'))->getState(),'Unreadable TLD displays an explicit omission');
    same($before,StaffCommands::stamp($binding->serverId),'Opening the page changes no saved intent or publication');
    try{$page->callTableBulkAction('edit',[$broken->id],['choice'=>'sell','longest'=>'keep','transfer'=>'keep'])->assertHasNoTableBulkActionErrors()->assertNotified("Couldn't save");}
    catch(Throwable $error){throw new RuntimeException('Unreadable bulk intent '.$bad.': '.$error->getMessage(),0,$error);}
    $review=new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview($actor->id,$binding->serverId,null,'acceptance',\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ticket($actor->id,$binding->serverId,null,'acceptance',StaffCommands::stamp($binding->serverId)),'Refuse unreadable selected intent');
    $refused=false;try{$commands->run($review,['sell'=>['choices'=>['invalid'=>'sell']]]);}catch(DomainException|InvalidArgumentException|JsonException|TypeError){$refused=true;}
    expect($refused,'Typed command refuses the malformed persisted intent before any save');
    same($before,StaffCommands::stamp($binding->serverId),'Bulk save refuses the broken TLD without writing it');
}
// Select-all can exceed the command's 25-TLD review boundary; refuse before entering the write.
$ids=[];
for($i=0;$i<26;$i++){$copy=$tlds[0]->fresh()->replicate();$copy->tld='bulk'.chr(97+$i);$copy->save();$ids[]=$copy->id;}
$before=StaffCommands::stamp($binding->serverId);
$page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false);
try{$page->callTableBulkAction('edit',$ids,['choice'=>'skip','longest'=>'keep','transfer'=>'keep'])->assertNotified(\Filament\Notifications\Notification::make()->warning()
    ->title('Pick at most 25 TLDs')->body('Select 25 or fewer TLDs, then try again.'));}
catch(Throwable $error){throw new RuntimeException('Bulk selection 26: '.$error->getMessage(),0,$error);}
same($before,StaffCommands::stamp($binding->serverId),'Oversized selection never changes intent, prices or audit');
// Start the separate valid-boundary interaction with fresh mounted form state and review ticket.
$page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false);
try{$page->callTableBulkAction('edit',array_slice($ids,0,25),['choice'=>'skip','longest'=>'keep','transfer'=>'keep'])->assertNotified('Saved');}
catch(Throwable $error){$notices=new Filament\Notifications\Livewire\Notifications;$notices->mount();throw new RuntimeException('Bulk selection 25: '.json_encode($notices->notifications->map(fn($n)=>[$n->getTitle(),$n->getBody()])->all()).': '.$error->getMessage(),0,$error);}
foreach(array_slice($ids,0,25) as $id){same(false,OfferState::decode(\Paymenter\Extensions\Servers\Domains\Models\Tld::find($id)->catalogue_intent)['sell'],'Exactly 25 selected TLDs save');}
same(true,OfferState::decode(\Paymenter\Extensions\Servers\Domains\Models\Tld::find($ids[25])->catalogue_intent)['sell'],'Unselected TLD keeps its choice');
// Bad account defaults: the row names the account setting, and Default for new TLDs opens and saves the repair.
$savedDefaults=OfferState::decode(DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults'));
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$savedDefaults,'defaultLongest'=>11])]);
$reason=Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::ruleProblem(['invalid_rule_kind'=>'account_defaults']);
$page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false)->searchTable('com')->assertSuccessful()->assertSee($reason);
$row=collect($page->instance()->getTableRecords()->items())->firstWhere('tld','com');
same($reason,$page->instance()->getTable()->getColumn('status')->record($row)->getDescriptionBelow(),'Status names the account setting');
same([],$page->instance()->savedPrices()['skipped'],'Bad account defaults leave no price out');
$page->mountAction('rule')->assertActionDataSet(['sell_new'=>'no','years'=>null])->callMountedAction()->assertHasActionErrors(['years'])->assertHasNoActionErrors(['sell_new']);
$page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId]);
$page->callAction('rule',['sell_new'=>'yes','max_cost'=>null,'years'=>2,'transfers'=>true])->assertHasNoActionErrors()->assertNotified('Saved');
same(2,OfferState::decode(DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults'))['defaultLongest'],'Default for new TLDs replaces the bad defaults');
$defaultsNow=static fn():array=>OfferState::decode(DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults'));
// One bad default leaves the other saved values in the form, so a save keeps them.
$savedDefaults=$defaultsNow();
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$savedDefaults,'defaultSell'=>false,'defaultLongest'=>11,'defaultTransfers'=>true,'maxAnnualCost'=>'25.0000'])]);
$page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->mountAction('rule')
    ->assertActionDataSet(['sell_new'=>'no','max_cost'=>'25.0000','transfers'=>true,'years'=>null])->assertSchemaComponentVisible('max_cost');
$page->setActionData(['years'=>4])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
same([false,4,true,'25.0000'],array_values(array_intersect_key($defaultsNow(),array_flip(['defaultSell','defaultLongest','defaultTransfers','maxAnnualCost']))),'Repairing one bad default keeps the valid cost limit, transfers and sell choice');
// Defaults saved as broken JSON still open the form and save the repair.
$published=$tlds[0]->fresh();$marker=OfferState::decode($published->catalogue_published_revision);
DB::table('domains_tlds')->where('id',$published->id)->update(['catalogue_published_revision'=>OfferState::json([...$marker,'defaults'=>40])]);
foreach(['{"defaultSell":','"text"'] as $bad){
    DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>$bad]);
    $page=Livewire::actingAs($actor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->assertSuccessful();
    try{$page->callAction('rule',['sell_new'=>'yes','max_cost'=>'30','years'=>3,'transfers'=>false])->assertHasNoActionErrors()->assertNotified('Saved');}
    catch(Throwable $error){throw new RuntimeException('Broken defaults '.$bad.': '.$error->getMessage(),0,$error);}
    $repaired=$defaultsNow();
    same([true,3,false],[$repaired['defaultSell'],$repaired['defaultLongest'],$repaired['defaultTransfers']],'Broken JSON defaults are replaced: '.$bad);
    expect($repaired['_revision']>40,'The repaired revision moves past every saved publication, so none reads as current: '.$bad);
}
DB::table('domains_tlds')->where('id',$published->id)->update(['catalogue_published_revision'=>OfferState::json($marker)]);
// An editor without Costs can't see the cost limit, so their save keeps it.
$editor=staffUser(array_values(array_diff(array_keys(Staff::permissions()),[Staff::COSTS])));
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$defaultsNow(),'maxAnnualCost'=>'25.0000'])]);
// The cost limit field is hidden from staff without Costs.
$page=Livewire::actingAs($editor)->test(UnreadableIntentSellPage::class,['account'=>$binding->serverId])->mountAction('rule')->assertActionDataSet(['years'=>3])->assertSchemaComponentHidden('max_cost');
$page->setActionData(['years'=>5])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
same([5,'25.0000'],[$defaultsNow()['defaultLongest'],$defaultsNow()['maxAnnualCost']],'A save without Costs keeps the saved cost limit');
$review=new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview($editor->id,$binding->serverId,null,'acceptance',\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ticket($editor->id,$binding->serverId,null,'acceptance',StaffCommands::stamp($binding->serverId)),'');
$commands->run($review,['sell'=>['rule'=>['mode'=>'except','max_cost'=>'1','years'=>2,'transfers'=>false]]]);
same([2,'25.0000'],[$defaultsNow()['defaultLongest'],$defaultsNow()['maxAnnualCost']],'The command ignores a cost limit sent by staff without Costs');
// A bad revision alone leaves the saved cost limit readable, so a save without Costs still keeps it.
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>'{"_revision":"bad","maxAnnualCost":"25.0000"}']);
$review=new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview($editor->id,$binding->serverId,null,'acceptance',\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ticket($editor->id,$binding->serverId,null,'acceptance',StaffCommands::stamp($binding->serverId)),'');
$commands->run($review,['sell'=>['rule'=>['mode'=>'except','max_cost'=>'1','years'=>3,'transfers'=>false]]]);
same([3,'25.0000'],[$defaultsNow()['defaultLongest'],$defaultsNow()['maxAnnualCost']],'A bad saved revision keeps the readable cost limit');
expect(is_int($defaultsNow()['_revision']),'The save repairs the bad revision');
Auth::setUser($actor);
same(0,$reads->calls,'Page reads and refused saves make no provider calls');
echo "What you sell isolates unreadable intent, names the TLD and refuses its save.\n";$nativeSuccessful=true;
