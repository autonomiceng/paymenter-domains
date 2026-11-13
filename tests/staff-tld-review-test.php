<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native policies and Filament account filters.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts,TldTerms};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,CallerRouting,Capabilities,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\SalesPolicy;
extract(commerceFixture());tldPanel();pinStaffClock($now);$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);$t=$tlds[0];$errors=[];
$check=static function(bool $ok,string $why)use(&$errors):void{if(!$ok){$errors[]=$why;}};
$c=new StaffCatalogue($ports,$clock);(new ReflectionProperty(CallerRouting::class,'probe'))->setValue(null,[StaffCatalogue::class=>$c,Capabilities::class=>$caps]);
$p=SalesPolicy::where('tld',$t->tld)->sole()->getAttributes();
$check(!TldTerms::chosen($t,[...$p,'server_id'=>$tlds[1]->server_id],[]),'Changed seller cannot count against the prior account');
// A switch between the bound TLD query and policy query must not count the stale row.
$race=true;DB::listen(static function($q)use(&$race,$t,$tlds):void{if($race&&str_contains($q->sql,'select * from "domains_tlds"')){$race=false;DB::table('domains_sales_policies')->where('tld',$t->tld)->update(['server_id'=>$tlds[1]->server_id]);}});
$check(TldTerms::summary((int)$t->server_id)['total']===0,'Summary rechecks a seller changed between reads');DB::table('domains_sales_policies')->where('id',$p['id'])->update($p);
$selected=$now->modify('-1 hour')->format('Y-m-d H:i:s');DB::table('domains_sales_policies')->where('id',$p['id'])->update(['updated_at'=>$selected]);
DB::table('domains_tlds')->where('id',$t->id)->update(['updated_at'=>$now->modify('+1 hour')->format('Y-m-d H:i:s')]);
DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint],['offer_feed_succeeded_at'=>$now->getTimestamp()]);
$fact=$c->page(collect([$t->fresh()]))[$t->id];$check($fact['refresh']['selected_at']===$now->modify('-1 hour')->getTimestamp(),'Pricing-only TLD writes do not move selection time');
foreach([null,'not-a-date']as$undated){DB::table('domains_sales_policies')->where('id',$p['id'])->update(['updated_at'=>$undated]);
 $fact=$c->page(collect([$t->fresh()]))[$t->id];$fact['prices']=[];$fact['selected']=true;
 $check(!str_contains(TldFacts::priceState($fact)['cause']??'',"Not in the registrar's price feed"),'Undated selection does not accept historical feed success');}
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();$checkpoint=strtotime((string)DB::table('domains_sales_policies')->where('id',$p['id'])->value('updated_at'));
$check($checkpoint===$now->getTimestamp(),'Upgrade establishes a conservative legacy selection checkpoint');
DB::table('domains_observation_queues')->where('server_id',$t->server_id)->update(['offer_feed_succeeded_at'=>$now->getTimestamp()+1]);
$fact=$c->page(collect([$t->fresh()]))[$t->id];$fact['prices']=[];$fact['selected']=true;
$check(str_contains(TldFacts::priceState($fact)['cause']??'',"Not in the registrar's price feed"),'Feed success after checkpoint confirms absence');
$intent=OfferState::decode($t->catalogue_intent);DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json([...$intent,'transfers'=>false,'pricing_rule'=>['mode'=>'invalid']])]);
$own=TldFacts::accounts($t->fresh(),Staff::requireView())[0];
$check(($own['transfer']['value']??null)==='Missing','Disabled transfer cannot hide an invalid price as Off');
$broken=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class,['record'=>$t->id]);
$check(str_contains(strip_tags(TldFacts::salesState($t->fresh())['reason']),'Edit prices')&&str_contains($broken->html(),'Edit prices'),'The page retains the invalid-rule repair beside the shared state');
// A broken own pricing rule must not hide the independent no-registrar cause in the real account table.
DB::table('domains_sales_policies')->where('tld',$t->tld)->update(['server_id'=>null]);
$accountNote=TldFacts::accounts($t->fresh(),Staff::requireView())[0]['note'];
$check(str_contains($accountNote,TldFacts::NO_SELLER),'Invalid own pricing keeps the independent missing registrar reason');
$reader=staffUser([Staff::VIEW]);Auth::setUser($reader);
$notes=Livewire\Livewire::actingAs($reader)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds::class);
$column=$notes->instance()->getTable()->getColumn('note')->record($tlds[1]);
$check(str_contains((string)$column->getState(),'Ask staff who can change the registrar.'),'Read-only list recovery does not offer a hidden Change registrar action');
Auth::setUser($actor);
// Retained per-TLD transfer defaults apply when the account has not overridden them.
$a=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole();$defaults=OfferState::decode($a->catalogue_defaults);unset($defaults['defaultTransfers']);$a->catalogue_defaults=OfferState::json($defaults);$a->save();
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json([...array_diff_key($intent,['transfers'=>true]),'defaultTransfers'=>true])]);
Paymenter\Extensions\Servers\Domains\Models\Price::where(['server_id'=>$t->server_id,'tld'=>$t->tld,'action'=>'transfer'])->delete();
$own=TldFacts::accounts($t->fresh(),Staff::requireView())[0];
$check($own['transfer']!==null,'Retained enabled transfer intent is not displayed as Off');
DB::table('domains_sales_policies')->where('id',$p['id'])->update($p);
// Missing prices must carry the recovery instruction into the actual account-table note.
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_retained_annual_retail'=>null]);
DB::table('domains_prices')->where(['server_id'=>$t->server_id,'tld'=>$t->tld,'action'=>'renew'])->update(['retail'=>null]);
$own=TldFacts::accounts($t->fresh(),Staff::requireView())[0];
$check(($own['renew']['value']??null)==='Missing'&&str_contains($own['note'],'Edit prices must supply a valid price.'),'Actual missing-renewal price has its displayed price-edit next step: '.json_encode([$own['renew'],$own['note']]));
// Actual Filament filter composition must scope its summary before reading other accounts.
$unscoped=false;$observe=false;DB::listen(static function($q)use(&$observe,&$unscoped):void{if($observe&&str_starts_with($q->sql,'select * from "domains_accounts"')&&!str_contains($q->sql,'where')){$unscoped=true;}});
$list=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds::class)->filterTable('server_id',(string)$t->server_id);
$observe=true;$list->filterTable('needs_attention');$observe=false;$check(!$unscoped,'Account attention filter avoids an all-account summary');
expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Staff policy timing, repair and scoped summaries passed\n";
