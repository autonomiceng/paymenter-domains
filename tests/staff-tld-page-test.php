<?php
// Native TLDs & pricing list and TLD page: sales, price sync with its stored cause, prices by account; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require_once __DIR__.'/Support/accounts.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Price};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationSchema,PriceCatalogue,StaffSettingsReview as Review};
$f=commerceFixture(nameSiloLocal:false,identities:['namesilo'=>'demo-namesilo']);extract($f);staffSalesFixture();tldPanel();ObservationSchema::install();Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');Carbon\Carbon::setTestNow($now);
[$enom,$silo]=$tlds;
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true]);
DB::table('extensions')->where('id',$enom->server_id)->update(['name'=>'Enom TEST identity check']);
DB::table('extensions')->where('id',$silo->server_id)->update(['name'=>'OFFLINE DEMO NameSilo (synthetic)']);
Domain::create(['server_id'=>$enom->server_id,'registrar_key'=>'enom','account_fingerprint'=>$enom->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);
$text=static fn(string $html):string=>preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',$html)),ENT_QUOTES|ENT_HTML5));
$banned=['Catalogue reader unavailable','read_unavailable','catalogue_','Pricing for .','Revision history','Renewal holders','Annual renewal',
    'Cost refresh','Default rule','TLD override','Seller','Account #','account #','Unknown','Review required','None','Revision','Margin','staff alert','Pricing & seller',"\u{2014}"];
$clean=static function(string $html,string $where)use($text,$banned):void {
    $plain=$text($html);foreach($banned as $old){expect(!str_contains($plain,$old),$where.': no "'.$old.'"');}
};
$renew=static fn($t)=>'USD '.PriceCatalogue::retail(Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'renew','term'=>1])->value('retail'));

// Prices synced: one row per registrar account, the account that takes new orders is marked.
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertCanSeeTableRecords($tlds)->assertSeeHtml('data-beco-record="tld-'.$tlds[0]->id.'"');same([],$list->instance()->getBreadcrumbs(),'TLD list has no breadcrumb, like Domains');
same('TLDs from What you sell. Open one for its prices.',$list->instance()->getSubheading(),'The TLDs page says its job in one head line.');$list->assertSee('TLDs from What you sell.');
staffTableStandard($list,'Search TLD',['tld','account','note'],['renewal','cost']);
foreach(['Registrar account','Renewal price','Cost','Domains','Status','Enom TEST identity check','OFFLINE DEMO NameSilo (synthetic)',
    'Enom, default pricing, new orders','NameSilo, default pricing','For sale','USD 8.00',$renew($enom)] as $see){$list->assertSee($see);}
$plain=$text($list->html());
expect(str_contains($plain,'OFFLINE DEMO NameSilo (synthetic) Demo'),'List: Demo tag after the demo account name');
expect(!str_contains($plain,'NameSilo, default pricing, new orders'),'List: only the selected account takes new orders');
$list->assertDontSee('Price sync failed');$clean($list->html(),'List synced');

$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful();
// Edit prices is one button, at the top of the page. The prices section has no copy of it.
$query=static function(DOMDocument $dom,string $path): DOMNodeList {
    $nodes=(new DOMXPath($dom))->query($path);
    if(!$nodes instanceof DOMNodeList){throw new RuntimeException('Bad XPath: '.$path);}
    return $nodes;
};
$editButtons=static function(string $html)use($query): DOMNodeList {
    $dom=new DOMDocument();@$dom->loadHTML($html);
    return $query($dom,'//button[not(contains(@class,"fi-dropdown-list-item"))][normalize-space(.)="Edit prices"]');
};
$buttons=$editButtons($page->html());
same(1,$buttons->length,'The TLD page shows one Edit prices button');
$button=$buttons->item(0);
if(!$button instanceof DOMElement||!$button->ownerDocument instanceof DOMDocument){throw new RuntimeException('Edit prices is not an element');}
same(0,$query($button->ownerDocument,'//section//button[normalize-space(.)="Edit prices"]')->length,'The prices section has no Edit prices copy');
$click=$button->getAttribute('wire:click');
// Click the rendered button: send exactly the call its wire:click makes.
expect((bool)preg_match("/^mountAction\\('([A-Za-z_]+)', (\\{.*?\\}), JSON\\.parse\\('(.*)'\\)\\)$/s",$click,$call),'Edit prices click is a plain mountAction call');
$context=json_decode((string)json_decode('"'.$call[3].'"'),true,flags:JSON_THROW_ON_ERROR);
$page->call('mountAction',$call[1],json_decode($call[2],true,flags:JSON_THROW_ON_ERROR),$context)
    ->assertSet('mountedActions.0.name','override')->assertSet('mountedActions.0.context',['recordKey'=>(string)$enom->id])
    ->assertActionDataSet(['pricing'=>'default']);
same('Save and publish',$page->instance()->getMountedAction()->getModalSubmitActionLabel(),'Edit prices opens the current pricing form');
$page->unmountAction();
foreach([
    array_values(array_diff($permissions,[Review::permission('override'),Review::permission('remove_override')])),
    array_values(array_diff($permissions,[Review::permission('publish')])),
] as $withoutPrices){
    $restricted=staffUser($withoutPrices);
    $hidden=Livewire::actingAs($restricted)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertActionHidden('override');
    same(0,$editButtons($hidden->html())->length,'Missing price-edit or publish grants hides Edit prices');
}
Auth::setUser($u);
same('.com',$page->instance()->getTitle(),'TLD page title is the TLD');
foreach(['For sale','Change registrar','More','Sales','Registrar for new orders','Existing domains stay with their registrar','Terms','1 to 3 years',
    'Price sync','Last updated','Oct 18, 2026','No update scheduled. Check Site setup on the Registrar account page, and the background worker.',
    'Prices by registrar account','Edit prices','Account','Pricing','Registration','Renewal','Transfer','Default pricing','New orders','History'] as $see){$page->assertSee($see);}
// Current fixture publication leaves real history; separately arrange an empty retained window.
expect(TldFacts::history((int)$enom->server_id,(int)$enom->id)!==[],'Published fixture has catalogue history');
$auditQuery=DB::table('settings')->where('settingable_type',(new App\Models\Server)->getMorphClass())->where('settingable_id',$enom->server_id)->whereIn('key',[Review::KEY,Review::KEY.'.catalogue']);
$auditBytes=(clone $auditQuery)->get(['id','value']);
try{
    $auditQuery->update(['value'=>Illuminate\Support\Facades\Crypt::encryptString('[]')]);
    same([],TldFacts::history((int)$enom->server_id,(int)$enom->id),'Empty history fixture contains no events');
    Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSee('No changes yet');
}finally{foreach($auditBytes as $saved){DB::table('settings')->where('id',$saved->id)->update(['value'=>$saved->value]);}}
$plain=$text($page->html());
expect(!str_contains($plain,'grace')&&!str_contains($plain,'Redemption'),'The TLD page asks staff nothing about grace or redemption');
expect(str_contains($plain,'OFFLINE DEMO NameSilo (synthetic) Demo'),'Prices table: Demo tag after the demo account');
expect(str_contains($plain,'Enom TEST identity check New orders'),'Prices table: the account for new orders is marked');
$accountLinks=static fn(string $html,int $server):int=>substr_count($html,'href="'.e(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$server])).'"');
same(2,$accountLinks($page->html(),(int)$enom->server_id),'Sales and the prices table open the account for new orders');
$page->assertDontSee('Last good prices')->assertDontSee('Sync failed')->assertDontSee('Valid until');$clean($page->html(),'TLD page synced');
same(['label'=>'For sale','tone'=>'success','reason'=>null],TldFacts::salesState($enom->fresh()),'Healthy TLD is for sale');

// The detail table shares page facts for prices, sellers and holders as accounts grow.
$detailRead=static function($grants)use($enom):array{
    DB::flushQueryLog();DB::enableQueryLog();$html=TldFacts::accountsTable($enom,$grants)->toHtml();$queries=DB::getQueryLog();DB::disableQueryLog();
    $counts=[];foreach(['domains_prices','domains_domains','domains_sales_policies','domains_accounts'] as $table){$counts[$table]=count(array_filter($queries,fn($q)=>($table==='domains_accounts'?str_starts_with($q['query'],'select * from "domains_accounts" '):str_contains($q['query'],'from "'.$table.'"'))));}
    return [$html,$counts];
};
$grants=Staff::requireView();[, $twoAccounts]=$detailRead($grants);
$extra=[];foreach([1,2] as $i){$extra[$i]=fixtureAccount((int)App\Models\Server::max('id')+1,'enom','detail-table-'.$i);}
$detailTlds=[];$detailPrices=[];
foreach([1,2] as $i){
    $binding=$extra[$i];
    App\Models\Server::whereKey($binding['server_id'])->update(['name'=>'<Extra & '.$i.'>']);
    $saved=$enom->getRawOriginal();unset($saved['id']);$detailTlds[]=DB::table('domains_tlds')->insertGetId([...$saved,...$binding]);
    foreach(Price::where('server_id',$enom->server_id)->get() as $price){$saved=$price->getRawOriginal();unset($saved['id']);$detailPrices[]=DB::table('domains_prices')->insertGetId([...$saved,...$binding]);}
}
[$detail,$fourAccounts]=$detailRead($grants);
// Active snapshots use two account batches and three credential-fenced NameSilo fact reads.
// Rendering more account rows must add no account, policy, holder or price queries.
same($twoAccounts,$fourAccounts,'All detail query counts stay fixed as accounts grow');
same(['domains_prices'=>1,'domains_domains'=>1,'domains_sales_policies'=>2,'domains_accounts'=>5],$fourAccounts,'Only the active batch and credential-fence reads remain');
expect(str_contains($detail,'&lt;Extra &amp; 1&gt;'),'Account names remain escaped');
$dom=new DOMDocument();@$dom->loadHTML($detail);$xpath=new DOMXPath($dom);$rendered=$xpath->query('//tbody/tr');
$all=Paymenter\Extensions\Servers\Domains\Models\Tld::where('tld','com')->orderBy('server_id')->get();same($all->count(),$rendered->length,'One rendered row per bound account');
foreach($all as $i=>$row){
    $saved=TldFacts::prices($row,$grants);$first=static fn($action)=>collect($saved)->where('action',$action)->sortBy('term')->first();
    $expected=[$first('register')['retail'],TldFacts::renewal($saved)['retail'],$first('transfer')['retail'],TldFacts::renewal($saved)['cost'],(string)TldFacts::holders($row)];
    foreach($expected as $j=>$value){same($value,$xpath->evaluate('normalize-space(td['.($j+3).'])',$rendered->item($i)),'Detail values agree with saved reader');}
}
$viewerGrants=Paymenter\Extensions\Servers\Domains\Admin\Support\StaffGrants::current();
Auth::setUser(staffUser([Staff::VIEW]));$withoutCosts=Staff::requireView();Auth::setUser($u);
[$hidden]=$detailRead($withoutCosts);expect(!str_contains($hidden,'<th scope="col">Cost'),'Supplied grants hide cost even when current staff may read it');
[$shown]=$detailRead($viewerGrants);expect(str_contains($shown,'Registrations and transfers below cost are held.'),'Supplied cost grant keeps cost help');
DB::table('domains_prices')->whereIn('id',$detailPrices)->delete();DB::table('domains_tlds')->whereIn('id',$detailTlds)->delete();


// Missing-registrar guidance follows the same fresh permissions as Change registrar.
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null]);
foreach([[Staff::VIEW],[Staff::VIEW,Staff::POLICY],[Staff::VIEW,Review::permission('seller')],
    [Staff::VIEW,Staff::POLICY,Review::permission('seller')]] as $rights){
    $reader=staffUser($rights);Auth::setUser($reader);
    $canChange=in_array(Staff::POLICY,$rights,true)&&in_array(Review::permission('seller'),$rights,true);
    $missing=Livewire::actingAs($reader)->test(ViewTld::class,['record'=>$silo->id])->assertSuccessful();
    if($canChange){$missing->assertActionVisible('seller')->assertSee(ViewTld::CHOOSE_SELLER_HEAD);}
    else{$missing->assertActionHidden('seller')->assertDontSee(ViewTld::CHOOSE_SELLER)->assertSee('Ask staff who can change the registrar.');}
}
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>$enom->server_id]);
Auth::setUser($u);

// Sync failed: the stored cause is shown as words, with the last good prices kept.
DB::table('domains_observation_queues')->insert(['server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,
    'catalogue_hold'=>'catalogue_reader_unavailable','catalogue_audit'=>json_encode([['code'=>'catalogue_reader_unavailable','server_id'=>$enom->server_id,
        'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T13:05:00+00:00']])]);
// Current status comes from retained OfferWork fields; legacy catalogue audit columns grant no status.
$syncFailureFixture=static function(?string $code,?string $at)use($enom,$now):void {
    DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update([
        'offer_work'=>json_encode(['upgrade_generation'=>\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::generation(),'feed_due'=>$now->modify('+1 day')->getTimestamp()]),
        'offer_feed_succeeded_at'=>$now->getTimestamp(),'offer_feed_failure'=>$code,
        'offer_feed_failed_at'=>$at===null?null:(new DateTimeImmutable($at))->getTimestamp()]);
};
$syncFailureFixture('catalogue_reader_unavailable','2026-10-18T13:05:00+00:00');
$cause="Couldn't load the registrar account's settings";
same(['cause'=>$cause,'timestamp'=>(new DateTimeImmutable('2026-10-18T13:05:00Z'))->getTimestamp(),'at'=>'Oct 18, 2026 13:05 UTC'],TldFacts::syncFailure($enom->server_id),'Stored failure code becomes its cause');
same('Sync failed: '.$cause,TldFacts::refreshState($enom->server_id),'Refresh state names the cause');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()
    ->assertSee('Price sync failed for 1 registrar account.')->assertSee('Enom TEST identity check (account '.$enom->server_id.'): '.$cause.'.')->assertSee('New sales stop when registrar costs are out of date.')->assertSee('Open Registrars');
$clean($list->html(),'List sync failed');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()
    ->assertSee('Refresh failed')->assertSee($cause)->assertSeeHtml('datetime="2026-10-18T13:05:00+00:00"')->assertSee('Sync prices')->assertSeeHtml(e(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$enom->server_id])))->assertSee('Last updated')->assertSee('Oct 18, 2026')->assertDontSee('Valid until');
$clean($page->html(),'TLD page sync failed');
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$silo->id])->assertSuccessful()->assertDontSee('Sync failed');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update(['catalogue_hold'=>'read_unavailable','catalogue_audit'=>'[]']);
$syncFailureFixture('read_unavailable',null);
same(['cause'=>'Registrar read failed','timestamp'=>null,'at'=>null],TldFacts::syncFailure($enom->server_id),'Older hold code keeps a plain cause and no time');
// A retained failure older than the latest saved prices is history, not a current failure.
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update(['catalogue_hold'=>null,'catalogue_audit'=>json_encode([['code'=>'read_deadline',
    'server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T11:00:00+00:00']])]);
$syncFailureFixture(null,null);
same(null,TldFacts::syncFailure($enom->server_id),'Prices synced after the failure clear it');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update(['catalogue_audit'=>json_encode([['code'=>'read_deadline',
    'server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T12:30:00+00:00']])]);
$syncFailureFixture('read_deadline','2026-10-18T12:30:00+00:00');
same(['cause'=>"Registrar didn't answer in time",'timestamp'=>(new DateTimeImmutable('2026-10-18T12:30:00Z'))->getTimestamp(),'at'=>'Oct 18, 2026 12:30 UTC'],TldFacts::syncFailure($enom->server_id),'A failure after the latest prices still shows');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->delete();

// No registrar for new orders.
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null]);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertSee('No registrar selected')->assertSee('Change registrar');
expect(str_contains($text($page->html()),'Registrar for new orders Missing'),'No registrar for new orders is Missing');
same(1,substr_count($text($page->html()),'No registrar selected'),'The head says No registrar selected once');
expect(str_contains($text($page->html()),'Registrar for new orders Missing Use More → Change registrar.'),'The Missing row says what to do, not the head reason again');
$page->assertSeeHtml('href="'.e(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl()).'"');
same(1,$accountLinks($page->html(),(int)$enom->server_id),'Missing goes to Registrars; the prices table still opens the account');
$clean($page->html(),'TLD page no registrar');
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'No registrar selected'],TldFacts::salesState($enom->fresh()),'No registrar for new orders is not for sale');
$page->assertSee('Not for sale')->assertDontSee('For sale');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertSee('No registrar selected')->assertDontSee('For sale');
expect(!str_contains($text($list->html()),'new orders'),'List: no account takes new orders');
$clean($list->html(),'List no registrar');
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>$enom->server_id]);

// Expired costs stop sales locally and tell staff how to resume.
activeCommerceFacts($now,$now->modify('+30 days'));
$clock->instant=$now->modify('+2 days');app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
Carbon\Carbon::setTestNow($clock->now());
same(['label'=>'Not for sale','tone'=>'danger','reason'=>'Cost out of date'],TldFacts::salesState($enom->fresh()),'The saved-cost age cap has a recoverable staff sales status: '.json_encode(TldFacts::salesState($enom->fresh())));
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertSee('Cost out of date')->assertSee('Sync prices');
$clock->instant=$now;Carbon\Carbon::setTestNow($now);

// Below cost: red Cost with its reason, and the TLD is not for sale.
$rules=new \Paymenter\Extensions\Servers\Domains\Services\PricingRules;
saveDefaultRule(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>array_fill_keys(['register','renew','transfer'],'1.00')],static fn($ending)=>activeFixtureRepublish($prices,$ending));
same(['label'=>'Not for sale','tone'=>'danger','reason'=>'Below cost'],TldFacts::salesState($enom->fresh()),'Below cost is not for sale');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertSee('Not for sale')->assertSee('Below cost')->assertSee('USD 1.00');
$clean($list->html(),'List below cost');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertSee('Not for sale')->assertSee('Below cost');
$clean($page->html(),'TLD page below cost');

// Without the costs permission no cost is shown.
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
$list=Livewire::actingAs($viewer)->test(ListTlds::class)->assertSuccessful()->assertDontSee('USD 8.00');
same('TLDs from What you sell. Open one for its prices.',$list->instance()->getSubheading(),'Staff without costs get the same head line.');
$page=Livewire::actingAs($viewer)->test(ViewTld::class,['record'=>$silo->id])->assertSuccessful()->assertDontSee('USD 8.00')->assertDontSee('Restore fee');
echo "Native TLDs & pricing list and TLD page passed.\n";$nativeSuccessful=true;
