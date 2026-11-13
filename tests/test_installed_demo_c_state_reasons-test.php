<?php
// Native Filament is required: list columns, account links and mounted price forms are the failing boundary.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Cost,OfferState};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
extract(commerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];
$failures=[];$case=static function(string $name,Closure $check)use(&$failures):void{try{$check();}catch(Throwable $e){$failures[]=$name.': '.substr($e->getMessage(),0,240);echo 'FAIL '.$name.': '.substr($e->getMessage(),0,240)."\n";}};
$case('B01 disabled renewal',function()use($t,$now){
    $p=Price::where('server_id',$t->server_id)->where('action','renew')->firstOrFail();$saved=$p->getAttributes();
    $cost=new Cost('renew',1,false,null,CostUnit::TermTotal,'synthetic',$now,$now->modify('+1 day'));
    DB::table('domains_prices')->where('id',$p->id)->update([...costColumns(OfferState::json(OfferState::encodeCost($cost)))]);
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_retained_annual_retail'=>null]);
    $list=Livewire::actingAs(auth()->user())->test(ListTlds::class)->instance();$column=$list->getTable()->getColumn('renewal')->record($t->fresh());
    $html=(string)$column->formatState($column->getState());
    expect(str_contains($html,'Renewal not offered by this registrar'),'Disabled is quiet and says why');
    same('Renewal not offered by this registrar',TldFacts::accounts($t,Staff::requireView())[0]['renew']['value'],'Account prices agree');
    DB::table('domains_prices')->where('id',$p->id)->update($saved);
});
$case('D01 complete selected terms count',function()use($t,$actor,$tlds){
    $policy=SalesPolicy::where('tld',$t->tld)->firstOrFail()->getAttributes();unset($policy['id']);
    for($i=1;$i<600;$i++){$copy=$t->replicate();$copy->tld='state'.strtr(sprintf('%03d',$i),'0123456789','abcdefghij');$copy->catalogue_facts=$i===599?null:($i===1?$tlds[1]->catalogue_facts:$t->catalogue_facts);$copy->catalogue_retained_annual_retail=null;$copy->save();DB::table('domains_sales_policies')->insert([...$policy,'tld'=>$copy->tld]);}
    $rows=AccountChecklist::renewals((int)$t->server_id);$summary=collect($rows)->firstWhere('label','Selected TLD terms');
    same('599 of 600 read',$summary['value']??null,'Counts the full selected set including annual-only facts');
    expect(str_contains((string)$summary['cause'],'1 needs attention'),'Missing facts count is explicit');
    $link=$summary['buttons'][0]->getUrl();expect(str_contains($link,'needs_attention')&&str_contains($link,'server_id'),'Attention link is account scoped');
    Livewire::actingAs($actor)->test(ListTlds::class)->filterTable('server_id',(string)$t->server_id)->filterTable('needs_attention')->assertCountTableRecords(1);
    Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSee('Selected TLD terms')->assertDontSee('Supported terms');
});
$case('D05 D06 feed absence and no seller',function()use($t,$actor,$now){
    $missing=Tld::where('tld','statefjj')->sole();
    DB::table('domains_sales_policies')->where('tld',$missing->tld)->update(['updated_at'=>$now->modify('-1 hour')]);
    DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint],['offer_feed_succeeded_at'=>$now->getTimestamp()+1]);
    $list=Livewire::actingAs($actor)->test(ListTlds::class)->searchTable($missing->tld)->instance();$col=$list->getTable()->getColumn('renewal')->record($list->getTableRecords()->first());
    $html=html_entity_decode((string)$col->formatState($col->getState()),ENT_QUOTES);
    expect(str_contains($html,'Not in the registrar\'s price feed; not for sale'),'Feed gap says why: '.strip_tags($html));
    DB::table('domains_sales_policies')->where('tld',$missing->tld)->delete();
    $state=TldFacts::priceState(TldFacts::snapshot($missing));
    expect(str_contains($state['cause'],'No registrar selected'),'Unsold without a registrar says why');same(null,$state['tone'],'Unsold gap is quiet');
    // slop-sweep: the list says No registrar selected once per row, in Note, with its next step; price cells show only the price.
    $list=Livewire::actingAs($actor)->test(ListTlds::class)->searchTable($missing->tld)->instance();$rec=$list->getTableRecords()->first();
    same([null,null],[$list->getTable()->getColumn('renewal')->record($rec)->getDescriptionBelow(),$list->getTable()->getColumn('cost')->record($rec)->getDescriptionBelow()],'Price cells do not repeat No registrar selected');
    expect(str_contains((string)$list->getTable()->getColumn('note')->record($rec)->getState(),'No registrar selected. Open the TLD and use Change registrar.'),'Note says it once with the next step');
});
$case('D02 automatic refresh times',function()use($t,$actor,$now){
    DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint],['offer_work'=>OfferState::json(['feed_due'=>$now->getTimestamp()+86400]),'offer_feed_succeeded_at'=>$now->getTimestamp(),'turn'=>0]);
    $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id]);$html=$page->html();expect(str_contains($html,'Last updated')&&str_contains($html,'Next update')&&!str_contains($html,'Sync prices to resume'),'Refresh facts and scheduled due time visible');expect(str_contains($html,'datetime="'.gmdate('c',$now->getTimestamp()).'"')&&str_contains($html,Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at($now->getTimestamp()+86400)),'Account refresh times are exact, even when this ending has no separate read');
});
$case('D03 legacy TEST blocker',function()use($t){
    $legacy=new Account(['server_id'=>$t->server_id,'registrar_key'=>'namesilo','environment'=>'test','stable_identity'=>\Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::PREFIX.str_repeat('a',64)]);
    $row=AccountChecklist::runtime($legacy);same('This account uses the old balance-only TEST connection. Connect an authenticated TEST account.',$row['cause'],'Staff sees exact blocker');
});
$case('B06 invalid fixed rule page and modal',function()use($t,$actor){
    DB::table('domains_tlds')->where('id',$t->id)->update(['use_default_rule'=>false,'pricing_mode'=>'fixed','catalogue_intent'=>OfferState::json(['pricing_rule'=>['mode'=>'fixed','markup'=>'0.00','round_up'=>'none','fixed'=>['register'=>'0.00','renew'=>'0.00','transfer'=>'0.00']]])]);
    $page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id]);expect(str_contains($page->html(),'Invalid fixed price rule')&&str_contains($page->html(),'Not set'),'Invalid rule is explained with Edit prices');
    $page->mountAction('override')->assertActionMounted('override')->call('$refresh');$modal=$page->effects['partials']['action-modals.0']??'';expect(str_contains($modal,'Invalid fixed price rule')&&str_contains($modal,'Missing'),'Invalid rule is explicitly explained inside the repair modal');
});
expect($failures===[],implode("\n",$failures));$nativeSuccessful=true;echo "Saved TLD and account state reasons passed.\n";
