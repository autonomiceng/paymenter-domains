<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native offer evidence, queue and Filament rendering must agree; a DTO stub cannot prove them.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,OfferWork,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$calls=0;$readable=true;
$work=new OfferWork($ports->writer(),$clock,new AccountReadBudget($clock,25),static function()use(&$calls){$calls++;throw new LogicException('Rendering must not resolve a provider');},static fn()=>0);
$source=new StaffCatalogue($ports,$clock,$work,static function()use(&$readable){return $readable;});
$t=Tld::findOrFail($tlds[0]->id);$original=$t->getRawOriginal();
$priceRows=DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->get();
$policy=(array)DB::table('domains_sales_policies')->where('tld','com')->first();
$defaults=DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults');
$queue=DB::table('domains_observation_queues')->where('server_id',$binding->serverId);
$reset=static function()use($t,$original,$priceRows,$queue,$policy,$defaults,$binding,&$readable):void {
    $readable=true;\App\Models\Server::whereKey($binding->serverId)->update(['enabled'=>true]);
    DB::table('domains_sales_policies')->where('tld','com')->update($policy);
    DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>$defaults]);
    DB::table('domains_tlds')->where('id',$t->id)->update($original);
    foreach($priceRows as $price){DB::table('domains_prices')->updateOrInsert(['id'=>$price->id],(array)$price);}
    $queue->update(['offer_work'=>'{}','offer_feed_succeeded_at'=>null,'offer_feed_failure'=>null,'offer_lease'=>0]);
};
$clearPrice=static fn()=>DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->update(['retail'=>null]);
function nextTable(): array
{
    global $source,$binding;
    $page=[];$table=new SellTable([$binding->serverId=>$binding],1,true,static function()use(&$page){return $page;},false,$source);
    $page=$table->rows('com')->get()->all();return [$table,$page[0]];
}
$GLOBALS['nextStepSource']=$source;
class NextStepStaffPage extends \Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell
{
    protected function catalogue():?StaffCatalogue{return $GLOBALS['nextStepSource'];}
    public function binding():?\Paymenter\Extensions\Servers\Domains\Data\AccountBinding {return \Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$this->account)->sole());}
}
\Livewire\Livewire::component('next-step-staff-page',NextStepStaffPage::class);
$failures=[];
try {
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->update(['retail'=>null]);
[$table,$row]=nextTable();$cell=$table->cell($row,'register','price');
same('Checking',$cell['text'],'Missing facts queue their own read rather than asking staff to sync');
same('gray',$cell['tone'],'Automatic work is a waiting state');
same('Next check '.SellTable::time($now->getTimestamp()).'.',$cell['next'],'Waiting shows when the queued check is due');
same('queued',$work->status($binding,'com')['state'],'The existing lazy-facts queue receives the hint');
$queued=DB::table('domains_observation_queues')->where('server_id',$binding->serverId)->value('offer_work');
[$again,$row]=nextTable();$again->cell($row,'register','cost');$again->cell($row,'register','price');$again->status($row);
same($queued,DB::table('domains_observation_queues')->where('server_id',$binding->serverId)->value('offer_work'),'Repeated page and cell reads coalesce into one queue entry');
same(0,$calls,'Staff rendering never resolves a provider or sends a request');
// Existing retry dates survive repeated hints, so staff do not bypass the read budget/backoff.
$retry=$now->getTimestamp()+120;
$queue->update(['offer_work'=>OfferState::json(['facts'=>['com'=>['due_at'=>$retry,'tries'=>2,'failure'=>'read_failed']]])]);
[$table,$row]=nextTable();same('Next check '.SellTable::time($retry).'.',$table->cell($row,'register','price')['next'],'Fact retry uses its existing due time');

} catch (Throwable $error) {$failures[]='Case 1: '.$error->getMessage();}
try {
$reset();$clearPrice();
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','price')['text'],'A price read fills an absent retail price without asking staff');
same($now->getTimestamp(),$work->status($binding)['due_at'],'A missing price queues the existing account feed');
same('background',OfferState::decode($queue->value('offer_work'))['origin'],'Automatic price hints use the background allowance');
$queued=$queue->value('offer_work');[$table,$row]=nextTable();$table->cell($row,'register','price');same($queued,$queue->value('offer_work'),'Price hints queue only once');
$queue->update(['offer_work'=>OfferState::json(['feed_due'=>$retry,'tries'=>2])]);
[$table,$row]=nextTable();same('Next check '.SellTable::time($retry).'.',$table->cell($row,'register','price')['next'],'Price hints preserve failure backoff');

} catch (Throwable $error) {$failures[]='Case 2: '.$error->getMessage();}
try {
$reset();
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->delete();
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','cost')['text'],'An unread cost queues a price read');
} catch (Throwable $error) {$failures[]='Unread cost: '.$error->getMessage();}
try {
$reset();DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->delete();
$queue->update(['offer_work'=>OfferState::json(['feed_due'=>$now->getTimestamp()+86400]),'offer_feed_succeeded_at'=>$now->getTimestamp()]);
[$table,$row]=nextTable();$cell=$table->cell($row,'register','cost');same('Missing',$cell['text'],'A completed read with no registrar cost needs a person');
same('Ask the registrar for a 1-year registration cost for .com.',$cell['next'],'Missing registrar cost names the exact question and where to ask');

} catch (Throwable $error) {$failures[]='Case 3: '.$error->getMessage();}
try {
$reset();$clearPrice();
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->update(['retail'=>'0.00']);
$intent=OfferState::decode($original['catalogue_intent']);$intent['transfers']=false;DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
[$table,$row]=nextTable();$status=$table->status($row);
same('Set a positive registration price in Edit prices on .com.',$status['next'],'A saved zero price names the staff choice and its page');same('tld',$status['open'],'The staff price choice links to the TLD page');
same('0.00',$table->cell($row,'register','price')['text'],'Saved prices remain visible while staff correct them');

} catch (Throwable $error) {$failures[]='Case 4: '.$error->getMessage();}
try {
$reset();$clearPrice();$readable=false;
[$table,$row]=nextTable();$cell=$table->cell($row,'register','price');same('Missing',$cell['text'],'An account without a reader needs setup');same('account',$cell['open'],'Account setup links to Registrar account');
$readable=true;
} catch (Throwable $error) {$failures[]='Case 5: '.$error->getMessage();}
try {
$reset();$clearPrice();
DB::table('domains_tlds')->where('id',$t->id)->increment('catalogue_revision');
[$table,$row]=nextTable();$cell=$table->cell($row,'register','price');same('Checking',$cell['text'],'Pending publication wakes local work');same(null,$work->status($binding)['due_at'],'Local publication adds no price read');

} catch (Throwable $error) {$failures[]='Case 6: '.$error->getMessage();}
try {
$reset();$clearPrice();
$facts=OfferState::decode($original['catalogue_facts']);$facts['terms']['register']=[];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($facts)]);
[$table,$row]=nextTable();$cell=$table->cell($row,'register','price');same('Off',$cell['text'],'A term the registrar does not offer is Off');same('Not offered by this registrar for this term.',$cell['next'],'Off explains the registrar limitation');

} catch (Throwable $error) {$failures[]='Case 7: '.$error->getMessage();}
try {
$reset();$clearPrice();
$cost=OfferState::decode($priceRows->first(fn($p)=>$p->action==='register'&&(int)$p->term===1)->catalogue_observation);$cost['enabled']=false;$cost['total']=null;
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',1)->update(['catalogue_observation'=>OfferState::json($cost)]);
[$table,$row]=nextTable();same('Off',$table->cell($row,'register','price')['text'],'An explicitly disabled registrar offer is Off');

} catch (Throwable $error) {$failures[]='Case 8: '.$error->getMessage();}
try {
$reset();$clearPrice();
$intent=OfferState::decode($original['catalogue_intent']);$intent['sell']=false;DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
$beforeQueue=$queue->value('offer_work');
[$table,$row]=nextTable();same('Off',$table->cell($row,'register','price')['text'],'Staff-stopped sales remain Off');same($beforeQueue,$queue->value('offer_work'),'Off sales do not queue reads');

} catch (Throwable $error) {$failures[]='Case 9: '.$error->getMessage();}
try {
$reset();$clearPrice();
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
$queue->update(['offer_work'=>OfferState::json(['facts'=>['com'=>['unsupported'=>true,'due_at'=>null]]])]);
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','price')['text'],'Even without a facts reader, the system fills prices before asking staff');
foreach($priceRows as $price){DB::table('domains_prices')->where('id',$price->id)->update((array)$price);}
[$table,$row]=nextTable();same('Ask the registrar to confirm terms for .com.',$table->status($row)['next'],'A missing facts reader gives a specific human step after prices are filled');

} catch (Throwable $error) {$failures[]='Case 10: '.$error->getMessage();}
try {
$reset();$clearPrice();
$facts=OfferState::decode($original['catalogue_facts']);$facts['freshUntil']=$now->modify('-1 second')->format(DATE_ATOM);$facts['observedAt']=$now->modify('-2 days')->format(DATE_ATOM);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($facts)]);
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','price')['text'],'Expired facts request an independent facts read');

} catch (Throwable $error) {$failures[]='Case 11: '.$error->getMessage();}
try {
$reset();$clearPrice();
$cost=OfferState::decode($priceRows->first(fn($p)=>$p->action==='register'&&(int)$p->term===1)->catalogue_observation);$cost['fresh_until']=$now->modify('-1 second')->format(DATE_ATOM);$cost['observed_at']=$now->modify('-2 days')->format(DATE_ATOM);
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',1)->update(['catalogue_observation'=>OfferState::json($cost)]);
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','price')['text'],'Expired costs queue a price read');

} catch (Throwable $error) {$failures[]='Case 12: '.$error->getMessage();}
try {
$reset();
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null,'registrar_key'=>null,'account_fingerprint'=>null]);
$beforeQueue=$queue->value('offer_work');
[$table,$row]=nextTable();same('Choose the registrar in More on .com.',$table->status($row)['next'],'Seller selection remains a specific staff choice');same($beforeQueue,$queue->value('offer_work'),'A staff seller choice does not queue needless reads');

} catch (Throwable $error) {$failures[]='Case 13: '.$error->getMessage();}
try {
$reset();
$d=OfferState::decode($defaults);$d['maxAnnualCost']='1.0000';DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json($d)]);
[$table,$row]=nextTable();same('Change the cost limit under Default for new TLDs in What you sell.',$table->status($row)['next'],'A cost limit names the staff setting');

} catch (Throwable $error) {$failures[]='Case 14: '.$error->getMessage();}
try {
$reset();$clearPrice();
\App\Models\Server::whereKey($binding->serverId)->update(['enabled'=>false]);
$beforeQueue=$queue->value('offer_work');
[$table,$row]=nextTable();same('account',$table->cell($row,'register','price')['open'],'A disabled account points to account setup');same($beforeQueue,$queue->value('offer_work'),'Disabled accounts do not queue work');

} catch (Throwable $error) {$failures[]='Case 15: '.$error->getMessage();}
try {
$reset();$clearPrice();
$facts=OfferState::decode($original['catalogue_facts']);$facts['requiresNameservers']=true;DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($facts)]);
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','price')['text'],'The system fills missing prices before asking about known registrar requirements');
foreach($priceRows as $price){DB::table('domains_prices')->where('id',$price->id)->update((array)$price);}
[$table,$row]=nextTable();same('Ask the registrar to confirm renewal and nameserver requirements for .com.',$table->status($row)['next'],'The requirements step names exactly what to ask after prices are filled');
$public=$ports->readOffers(new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'register'),\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Public)[0];same('unavailable',$public->reason,'Customers keep the generic public decision without staff reasons');
} catch (Throwable $error) {$failures[]='Requirements: '.$error->getMessage();}
try {
$reset();DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_retained_annual_retail'=>null]);
[$table,$row]=nextTable();same('Checking',$table->cell($row,'renew','price')['text'],'Missing retained annual retail is filled automatically');
same($now->getTimestamp(),$work->status($binding)['due_at'],'Missing retained retail reuses the account price read');
// Render the actual staff cell. Visible descriptions and real links cannot be proved by a helper-only assertion.

} catch (Throwable $error) {$failures[]='Case 16: '.$error->getMessage();}
// One table build shares the account price read across its actions: Register and Renew hints queue one sync.
$syncs=0;$countSyncs=false;DB::listen(static function($q)use(&$syncs,&$countSyncs):void {if($countSyncs&&preg_match('/^update [`"]domains_observation_queues[`"]/i',$q->sql)){$syncs++;}});
try {
$reset();$clearPrice();DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_retained_annual_retail'=>null]);
[$table,$row]=nextTable();$countSyncs=true;
$hints=[$table->cell($row,'register','price')['text'],$table->cell($row,'renew','price')['text']];$countSyncs=false;
same(['Checking','Checking'],$hints,'Register and Renew both wait on the account price read');
same(1,$syncs,'One table build queues the account price read once across Register and Renew');
} catch (Throwable $error) {$countSyncs=false;$failures[]='Shared account sync: '.$error->getMessage();}
// Review probe A/B: an unreadable saved rule is a staff edit, never automatic recovery.
foreach([false,true] as $pending){
try {
$reset();$clearPrice();
$intent=OfferState::decode($original['catalogue_intent']);$intent['transfers']=false;
$intent['pricing_rule']=['mode'=>'bogus','round_up'=>'none','markup'=>'0','fixed'=>[]];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
if($pending){DB::table('domains_tlds')->where('id',$t->id)->increment('catalogue_revision');}
[$table,$row]=nextTable();
same('Left out',$table->cell($row,'register','price')['text'],'Unreadable pricing remains Left out in money cells');
$status=$table->status($row);
same('Fix the pricing rule in Edit prices on .com.',$status['next'],'Unreadable pricing asks for the saved rule to be fixed');
expect($status['label']!=='Checking','A saved staff rule cannot be repaired by repeated publication');
same(null,$work->status($binding)['due_at'],'Unreadable pricing does not queue a provider price read');
$html=\Livewire\Livewire::actingAs($actor)->test(NextStepStaffPage::class)->assertSee('Left out')->html();
$dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
$url=\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource::getUrl('view',['record'=>$t->id]);
expect($xpath->query('//td[contains(@class,"domains-sell-status")]//a[@href="'.$url.'" and contains(.,"Fix the pricing rule in Edit prices on .com.")]')->length===1,'The table renders the invalid row with its visible Edit prices step and TLD link');
} catch (Throwable $error) {$failures[]='Unreadable rule '.($pending?'pending':'current').': '.$error->getMessage();}
}
try {
$reset();$clearPrice();$readable=false;
$intent=OfferState::decode($original['catalogue_intent']);$intent['transfers']=false;DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
$html=\Livewire\Livewire::actingAs($actor)->test(NextStepStaffPage::class)->assertSee('Missing')->html();
$dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
expect($xpath->query('//*[contains(@class,"description") and contains(.,"Check account setup on Registrar account.")]')->length>0,'The next step is visible beside Missing, outside its tooltip');
$url=\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$binding->serverId]);
expect($xpath->query('//a[@href="'.$url.'" and contains(.,"Missing")]')->length>0,'Missing links to the account page that fixes setup');
expect($xpath->query('//td[contains(@class,"domains-sell-status")]//a[@href="'.$url.'" and contains(.,"Check account setup on Registrar account.")]')->length===1,'The Status setup step links to Registrar account, like the money cell');
} catch (Throwable $error) {$failures[]='Account status link: '.$error->getMessage();}
// Review probe C: exercise the actual Status anchors, including deliberate absence of a link.
foreach(['cost limit','registrar cost','registrar terms','registrar requirements','price choice'] as $scenario){
try {
$reset();$intent=OfferState::decode($original['catalogue_intent']);$intent['transfers']=false;
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
$open=null;$url=null;
switch($scenario){
case 'cost limit':
    $d=OfferState::decode($defaults);$d['maxAnnualCost']='1.0000';DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json($d)]);
    $next='Change the cost limit under Default for new TLDs in What you sell.';break;
case 'registrar cost':
    DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->delete();
    $queue->update(['offer_work'=>OfferState::json(['feed_due'=>$now->getTimestamp()+86400]),'offer_feed_succeeded_at'=>$now->getTimestamp()]);
    $next='Ask the registrar for a 1-year registration cost for .com.';break;
case 'registrar terms':
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
    $queue->update(['offer_work'=>OfferState::json(['facts'=>['com'=>['unsupported'=>true,'due_at'=>null]]])]);
    $next='Ask the registrar to confirm terms for .com.';break;
case 'registrar requirements':
    $facts=OfferState::decode($original['catalogue_facts']);$facts['requiresNameservers']=true;DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($facts)]);
    $next='Ask the registrar to confirm renewal and nameserver requirements for .com.';break;
case 'price choice':
    DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->update(['retail'=>'0.00']);
    $next='Set a positive registration price in Edit prices on .com.';$open='tld';
    $url=\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource::getUrl('view',['record'=>$t->id]);break;
}
[$table,$row]=nextTable();$status=$table->status($row);
same($next,$status['next'],'The Status step names the action for '.$scenario);
same($open,$status['open'],'The Status preserves the step destination for '.$scenario);
$html=\Livewire\Livewire::actingAs($actor)->test(NextStepStaffPage::class)->html();
$dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
$cell='//td[contains(@class,"domains-sell-status") and contains(.,"'.$next.'")]';
same(1,$xpath->query($cell)->length,'The next step is visible in Status for '.$scenario);
if($url===null){same(0,$xpath->query($cell.'//a[@href]')->length,'Status has no unrelated TLD link for '.$scenario);}
else{same(1,$xpath->query($cell.'//a[@href="'.$url.'"]')->length,'Status links to the TLD that fixes '.$scenario);}
} catch (Throwable $error) {$failures[]='Status link '.$scenario.': '.$error->getMessage();}
}
try {
same(0,$calls,'All states render without provider resolution or a send');
$actor->role()->first()->update(['permissions'=>[Staff::VIEW]]);[$table,$row]=nextTable();same(null,$table->cell($row,'register','price')['text'],'Staff without cost permission do not see money-cell reasons');

} catch (Throwable $error) {$failures[]='Case 17: '.$error->getMessage();}
try {
$reset();
$intent=OfferState::decode($original['catalogue_intent']);$intent['stops']=['register'];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','transfer')->update(['retail'=>'0.00']);
$actor->role()->first()->update(['permissions'=>array_keys(Staff::permissions())]);
[$table,$row]=nextTable();$status=$table->status($row);
same('Set a positive transfer price in Edit prices on .com.',$status['next'],'Stopped registration keeps the active transfer repair step');
same('tld',$status['open'],'Transfer repair links to Edit prices');
} catch (Throwable $error) {$failures[]='Transfer status repair: '.$error->getMessage();}
foreach(['stopped','account'] as $scenario){
try {
$reset();
if($scenario==='stopped'){
    $intent=OfferState::decode($original['catalogue_intent']);$intent['sell']=false;
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
}else{\App\Models\Server::whereKey($binding->serverId)->update(['enabled'=>false]);}
$actor->role()->first()->update(['permissions'=>[Staff::VIEW]]);
[$table,$row]=nextTable();$status=$table->status($row);
same($scenario==='stopped'?'Stopped by staff':'Check registrar account',$status['next'],'Restricted viewers retain the specific sale reason');
same(null,$status['open']??null,'Restricted status avoids the unrelated TLD repair link');
} catch (Throwable $error) {$failures[]='Restricted status '.$scenario.': '.$error->getMessage();}
}
$actor->role()->first()->update(['permissions'=>array_keys(Staff::permissions())]);
foreach([false,true] as $canRead){
try {
$reset();$readable=$canRead;
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','renew')->delete();
[$table,$row]=nextTable();$cell=$table->cell($row,'renew','cost');
same($canRead?'Checking':'Missing',$cell['text'],'Retained renewal costs preserve automatic recovery');
same($canRead?null:'account',$cell['open'],'Retained renewal setup preserves its account destination');
if(!$canRead){same('Check account setup on Registrar account.',$cell['next'],'Retained renewal cost explains account repair');}
} catch (Throwable $error) {$failures[]='Retained renewal recovery '.($canRead?'queued':'account').': '.$error->getMessage();}
}
try {
$reset();
$nt=$tlds[1]->fresh();$ns=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$nt->server_id)->sole());
$q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($ns,['com'],'register');
$rev=static fn()=>$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
$nf=fixtureNameSiloFacts($ns,'com',new \Paymenter\Extensions\Servers\Domains\Catalogue\Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day')));
$ports->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FactsObserved($ns,'com',$nf),$rev());
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::seller($ns,'com',['register','transfer']),$rev());$nt=$nt->fresh();
$key=$nt->server_id.':'.$nt->tld;
$page=$source->page(collect([$nt]));
same('ready',$source->nextSteps(collect([$nt]),[$key=>$page[$nt->id]],'register',1)[$key]['state'],'Current NameSilo TEST facts remain usable');
$originalAccounts=$accounts=config('domains.namesilo_test.accounts');$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$ns->serverId)->sole();
unset($accounts[$account->stable_identity]['facts'][$nt->tld]);config(['domains.namesilo_test.accounts'=>$accounts]);
$nsQueue=DB::table('domains_observation_queues')->where('server_id',$ns->serverId);
foreach([false,true] as $alreadyQueued){
    $nsQueue->update(['offer_work'=>OfferState::json($alreadyQueued?['facts'=>[$nt->tld=>['sold_only'=>true,'due_at'=>$now->getTimestamp()+86400]]]:[])]);
    $page=[];$table=new SellTable([$ns->serverId=>$ns],1,true,static function()use(&$page){return $page;},false,$source);
    $page=$table->rows('com')->get()->all();$status=$table->status($page[0]);
    same('Checking',$status['label'],'Rejected fresh TEST facts need recovery rather than a ready step');
    same($now->getTimestamp(),$work->status($ns,$nt->tld)['due_at'],'Rejected TEST evidence is scheduled now, including an existing freshness deadline');
}
$future=$now->getTimestamp()+120;
$nsQueue->update(['offer_work'=>OfferState::json(['facts'=>[$nt->tld=>['due_at'=>$future,'tries'=>2,'failure'=>'read_failed']]])]);
$work->requestFacts($ns,$nt->tld);same($future,$work->status($ns,$nt->tld)['due_at'],'Rejected TEST evidence still respects failure backoff');
same(0,$calls,'Rejected TEST evidence only queues local recovery');
config(['domains.namesilo_test.accounts'=>$originalAccounts]);
} catch (Throwable $error) {$failures[]='NameSilo TEST facts recovery: '.$error->getMessage();}
try {
$reset();$clearPrice();
$cost=OfferState::decode($priceRows->first(fn($p)=>$p->action==='register'&&(int)$p->term===1)->catalogue_observation);
$cost['enabled']=false;$cost['total']=null;$cost['fresh_until']=$now->modify('-1 second')->format(DATE_ATOM);$cost['observed_at']=$now->modify('-2 days')->format(DATE_ATOM);
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action','register')->where('term',1)->update(['catalogue_observation'=>OfferState::json($cost)]);
[$table,$row]=nextTable();same('Checking',$table->cell($row,'register','price')['text'],'An expired disabled cost queues a new price read');
same($now->getTimestamp(),$work->status($binding)['due_at'],'Expired refusal schedules the existing price worker');
} catch (Throwable $error) {$failures[]='Expired disabled cost: '.$error->getMessage();}
foreach(['renew','transfer'] as $action){foreach([true,false] as $canRead){
try {
$reset();$readable=$canRead;
$cost=OfferState::decode($priceRows->first(fn($p)=>$p->action===$action&&(int)$p->term===1)->catalogue_observation);
$cost['source']='enom_bulk_bucket_1';$cost['enabled']=false;$cost['total']=null;
$cost['fresh_until']=$now->modify('-1 second')->format(DATE_ATOM);$cost['observed_at']=$now->modify('-2 days')->format(DATE_ATOM);
DB::table('domains_prices')->where('server_id',$binding->serverId)->where('tld','com')->where('action',$action)->where('term',1)->update(['catalogue_observation'=>OfferState::json($cost)]);
$metadata=OfferState::facts($original['catalogue_facts']);
$refusal=new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding,'com',[OfferState::cost($cost)]);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::encodeFacts($refusal->facts($metadata))]);
$current=$t->fresh();$key=$current->server_id.':'.$current->tld;$page=$source->page(collect([$current]));
$step=$source->nextSteps(collect([$current]),[$key=>$page[$current->id]],$action,1)[$key];
same($canRead?'checking':'missing',$step['state'],'Expired disabled '.$action.' terms reach price recovery');
if($canRead){same($now->getTimestamp(),$work->status($binding)['due_at'],'Expired annual refusal queues the price feed');}
else{same('account',$step['open'],'Unreadable accounts retain the setup repair');same(null,$work->status($binding)['due_at'],'Unreadable accounts do not queue reads');}
} catch(Throwable $error){$failures[]='Expired annual refusal '.$action.': '.$error->getMessage();}
}}
expect($failures===[],implode("\n",$failures));
$nativeSuccessful=true;echo "Staff catalogue next steps: ok\n";
