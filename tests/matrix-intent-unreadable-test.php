<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture covers the real saved-price matrix, Blade warning and strict publication boundary.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Services\PriceList;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);
$list=new class extends PriceList {public StaffCatalogue $source;protected function catalogue():?StaffCatalogue{return $this->source;}};$list->source=$source;
$broken=$tlds[0]->fresh()->replicate();$broken->tld='invalid';$broken->save();
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
foreach(['{"sell":','"invalid"',OfferState::json(['sell'=>[]]),OfferState::json(['longest'=>11])] as $bad){
    DB::table('domains_tlds')->where('id',$broken->id)->update(['catalogue_intent'=>$bad]);
    $before=StaffCommands::stamp($binding->serverId);
    $matrix=$list->read();
    same(array_column($tlds,'id'),array_column($matrix['rows'],'id'),'Healthy TLDs remain in the price matrix');
    same('7.80',$matrix['rows'][0]['prices']['register']['price'],'Healthy published retail stays exact');
    same('6.0000',$matrix['rows'][0]['prices']['register']['cost'],'Healthy normalized cost stays exact');
    same([$broken->id],array_column($matrix['skipped'],'id'),'Only unreadable intent is left out');
    $html=view('domains::admin.sell-note',['warning'=>$matrix['warnings'][0]])->render();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    same(".invalid on Synthetic has prices left out: its custom pricing can't be read. Open .invalid and use Edit prices.",
        $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Existing named warning and repair wording are preserved');
    expect(str_contains($list->csv(),"Left out: custom pricing can't be read"),'CSV discloses the omitted price');
    same($before,StaffCommands::stamp($binding->serverId),'Reading leaves intent and publication unchanged');
    $refused=false;try{$commands->publish($broken->id);}catch(Throwable){$refused=true;}
    expect($refused,'Unreadable intent still refuses publication');
    same($before,StaffCommands::stamp($binding->serverId),'Refused publication preserves all saved state');
}
// Bad account defaults are the account's problem: TLD pricing stays readable, sales stop and holders keep their renewal price.
DB::table('domains_tlds')->where('id',$broken->id)->delete();$healthy=$tlds[0]->fresh();
$renewal=static fn(array $fact):?string=>collect($fact['prices'])->first(fn($p)=>$p['action']==='renew'&&$p['term']===1)['retail']??null;
$retained=$renewal($source->page(collect([$healthy]))[$healthy->id]);expect($retained!==null,'Fixture has a saved holder renewal price');
$savedDefaults=OfferState::decode(DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults'));
$reason=TldFacts::ruleProblem(['invalid_rule_kind'=>'account_defaults']);
// A known renewal cost above the saved holder price stays an exact loss, though bad defaults leave no offers to read it from.
$renewRow=static fn()=>DB::table('domains_prices')->where('server_id',$healthy->server_id)->where('action','renew')->where('term',1);$renewSaved=costEvidence($renewRow()->first());
$renewRow()->update([...costColumns(OfferState::json([...OfferState::decode($renewSaved),'total'=>'50.0000']))]);
foreach([['defaultLongest'=>11],['maxAnnualCost'=>'not money'],['defaultTransfers'=>'yes']] as $bad){
    DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$savedDefaults,...$bad])]);
    $fact=$source->page(collect([$healthy->fresh()]))[$healthy->id];
    same(['account_defaults',false,'Not for sale',$reason],[$fact['invalid_rule_kind'],$fact['unreadable'],$fact['sales']['label'],$fact['sales']['reason']],'Bad account defaults refuse sales and name the account setting, not TLD pricing');
    expect(!str_contains($reason,'Edit prices'),'Edit prices is not offered for an account setting');
    same($retained,$renewal($fact),'Holders keep their saved renewal price');
    same([$retained,$reason],[TldFacts::priceState($fact,'renew')['value'],TldFacts::priceState($fact,'renew')['cause']],'TLD page shows the renewal price and the account cause');
    same(Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL,TldFacts::priceState($fact,'register')['value'],'New sale prices stay closed');
    $matrix=$list->read();
    same([],$matrix['skipped'],'Bad account defaults leave no price out of the list');
    same($retained,'$'.$matrix['rows'][0]['prices']['renew']['price'],'Price list keeps the saved renewal price');
    $renew=$matrix['rows'][0]['prices']['renew'];
    same(['50.0000','-40.2016',true,true,'Below cost'],[$renew['cost'],$renew['profit'],$renew['below_cost'],$renew['loses_money'],$renew['status']],'The saved renewal cost and its loss stay exact');
    same([$healthy->id],array_column($list->read(1,'below_cost')['rows'],'id'),'Below cost keeps the TLD that loses money on renewals');
    same([],$source->omissions(collect([$healthy->fresh()])),'Off-page warnings do not call bad account defaults a pricing error');
}
$renewRow()->update([...costColumns($renewSaved)]);
// Saving Default for new TLDs replaces the bad defaults whole, so the named repair works.
$review=new Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview($actor->id,$binding->serverId,null,'acceptance',Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::ticket($actor->id,$binding->serverId,null,'acceptance',StaffCommands::stamp($binding->serverId)),'');
$commands->run($review,['sell'=>['rule'=>['mode'=>'except','max_cost'=>null,'years'=>3,'transfers'=>true]]]);
same(3,OfferState::decode(DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults'))['defaultLongest'],'Saved defaults replace the bad ones');
expect(($source->page(collect([$healthy->fresh()]))[$healthy->id]['invalid_rule_kind'])!=='account_defaults','Repaired defaults clear the account cause');
same(0,$reads->calls,'Price matrix and refused writes make no provider calls');
echo "Price matrix isolates unreadable intent and retains warnings without weakening publication.\n";$nativeSuccessful=true;
