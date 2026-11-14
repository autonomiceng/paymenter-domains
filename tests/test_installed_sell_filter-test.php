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
// A matching healthy TLD keeps the filtered page nonempty. The left-out TLD keeps its warning and stays out of the table.
$codec=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::class;
// The fixture's own copy still holds the readable intent the loop above replaced.
$sibling=$tlds[0]->replicate();$sibling->tld='net';$sibling->save();
foreach(['own'=>false,'default'=>true] as $filter=>$defaults){
    DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_intent'=>'{"sell":','use_default_rule'=>$defaults]);
    $rule=$defaults?null:['mode'=>'markup_amount','markup'=>'1.25','round_up'=>'none'];
    DB::table('domains_tlds')->where('id',$sibling->id)->update(['catalogue_intent'=>$codec::json([...$codec::decode($sibling->catalogue_intent),'pricing_rule'=>$rule]),
        'use_default_rule'=>$defaults,...($defaults?[]:['pricing_mode'=>'markup_amount','markup'=>'1.25','round_up'=>'none'])]);
    $page=Livewire::actingAs($actor)->test(EmptyFilterSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false)->filterTable('show',$filter);
    same(['net'],collect($page->instance()->getTableRecords()->items())->pluck('tld')->all(),'Show lists only the matching healthy TLD: '.$filter);
    $page->assertSee('Prices left out for .com')->assertSee('It is not shown on this page. Open it to fix the pricing.');
    $page->searchTable('net')->assertDontSee('Prices left out');
}
// Mixed omissions need different recovery: unreadable settings cannot be repaired by the pricing action.
$invalid=$codec::decode($sibling->catalogue_intent);$invalid['pricing_rule']=['mode'=>'bogus','round_up'=>'none','markup'=>'0','fixed'=>[]];
DB::table('domains_tlds')->where('id',$sibling->id)->update(['catalogue_intent'=>$codec::json($invalid),'use_default_rule'=>false]);
DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_intent'=>'{"sell":','use_default_rule'=>false]);
$page=Livewire::actingAs($actor)->test(EmptyFilterSellPage::class,['account'=>$binding->serverId])->filterTable('for_sale',false)->filterTable('show','own')->assertCountTableRecords(0);
$table=new \Paymenter\Extensions\Servers\Domains\Admin\Support\SellTable([$binding->serverId=>$binding],1,true,fn()=>[],catalogue:$GLOBALS['emptyFilterSource']);
same(['unreadable','custom_rule'],array_values(array_unique($table->leftOutCauses('own'))),'The empty own-pricing filter retains both recovery causes');
$grants=\Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants::current();
$unreadable=\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::accounts($tlds[0]->fresh(),$grants)[0];
$custom=\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::accounts($sibling->fresh(),$grants)[0];
same(null,$unreadable['fix'],'Unreadable settings offer no pricing action that cannot decode them');
expect($custom['fix']!==null,'An invalid custom rule retains its pricing repair action');
$nativeSuccessful=true;echo "Empty price filters explain omissions without pointing at a missing callout.\n";
