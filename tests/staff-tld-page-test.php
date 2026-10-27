<?php
// Native TLDs & pricing list and TLD page: sales, price sync with its stored cause, prices by account; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require_once __DIR__.'/Support/accounts.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Price};
use Paymenter\Extensions\Servers\Domains\Services\{LifecyclePolicies,ObservationSchema,PriceCatalogue,StaffSettingsReview as Review};
$f=commerceFixture();extract($f);tldPanel();ObservationSchema::install();Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');Carbon\Carbon::setTestNow($now);
[$enom,$silo]=$tlds;
DB::table('extensions')->where('id',$enom->server_id)->update(['name'=>'Enom TEST identity check']);
DB::table('extensions')->where('id',$silo->server_id)->update(['name'=>'OFFLINE DEMO NameSilo (synthetic)']);
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['stable_identity'=>Illuminate\Support\Facades\Crypt::encryptString('demo-namesilo')]);
Domain::create(['server_id'=>$enom->server_id,'registrar_key'=>'enom','account_fingerprint'=>$enom->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);
$text=static fn(string $html):string=>preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',$html)),ENT_QUOTES|ENT_HTML5));
$banned=['Refresh failed','Catalogue reader unavailable','read_unavailable','catalogue_','Pricing for .','Revision history','Renewal holders','Annual renewal',
    'Cost refresh','Default rule','TLD override','Seller','Account #','account #','Unknown','Review required','None','Revision','Margin','staff alert','Pricing & seller',"\u{2014}"];
$clean=static function(string $html,string $where)use($text,$banned):void {
    $plain=$text($html);foreach($banned as $old){expect(!str_contains($plain,$old),$where.': no "'.$old.'"');}
};
$renew=static fn($t)=>'USD '.PriceCatalogue::retail(Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'renew','term'=>1])->value('retail'));

// Prices synced: one row per registrar account, the account that takes new orders is marked.
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertCanSeeTableRecords($tlds);same([],$list->instance()->getBreadcrumbs(),'TLD list has no breadcrumb, like Domains');
foreach(['Registrar account','Renewal price','Cost','Domains','Status','Enom TEST identity check','OFFLINE DEMO NameSilo (synthetic)',
    'Enom · default pricing · new orders','NameSilo · default pricing','For sale','USD 8.00',$renew($enom)] as $see){$list->assertSee($see);}
$plain=$text($list->html());
expect(str_contains($plain,'OFFLINE DEMO NameSilo (synthetic) Demo'),'List: Demo tag after the demo account name');
expect(!str_contains($plain,'NameSilo · default pricing · new orders'),'List: only the selected account takes new orders');
$list->assertDontSee('Price sync failed');$clean($list->html(),'List synced');

$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful();
// The first render needs a section button, even while More is closed.
$query=static function(DOMDocument $dom,string $path): DOMNodeList {
    $nodes=(new DOMXPath($dom))->query($path);
    if(!$nodes instanceof DOMNodeList){throw new RuntimeException('Bad XPath: '.$path);}
    return $nodes;
};
$priceButtons=static function(string $html)use($query): DOMNodeList {
    $dom=new DOMDocument();@$dom->loadHTML($html);
    return $query($dom,'//section[header//h2[normalize-space(.)="Prices by registrar account"]]/header//button[normalize-space(.)="Edit prices"]');
};
$shortcut=$priceButtons($page->html());
same(1,$shortcut->length,'First load shows one Edit prices button in the prices section');
$button=$shortcut->item(0);
if(!$button instanceof DOMElement||!$button->ownerDocument instanceof DOMDocument){throw new RuntimeException('Section shortcut is not an element');}
for($node=$button;$node instanceof DOMElement;$node=$node->parentNode){
    expect(!$node->hasAttribute('hidden')&&!$node->hasAttribute('x-cloak')&&!$node->hasAttribute('x-show')
        &&$node->getAttribute('aria-hidden')!=='true'&&!str_contains($node->getAttribute('class'),'fi-dropdown-panel')
        &&!preg_match('/display\s*:\s*none/i',$node->getAttribute('style')),'Section shortcut is outside hidden menu content');
}
$click=$button->getAttribute('wire:click');
expect((bool)preg_match("/^mountAction\\('override'(?:,|\\))/",$click),'Shortcut mounts the shared Edit prices action');
$menu=$query($button->ownerDocument,'//button[contains(@class,"fi-dropdown-list-item")][normalize-space(.)="Edit prices"]');
same(1,$menu->length,'More keeps its Edit prices menu item');
$item=$menu->item(0);
same($click,$item instanceof DOMElement?$item->getAttribute('wire:click'):null,'Section and menu open the same form');
// Click the rendered shortcut: send exactly the call its wire:click makes.
expect((bool)preg_match("/^mountAction\\('([A-Za-z_]+)', (\\{.*?\\}), JSON\\.parse\\('(.*)'\\)\\)$/s",$click,$call),'Shortcut click is a plain mountAction call');
$context=json_decode((string)json_decode('"'.$call[3].'"'),true,flags:JSON_THROW_ON_ERROR);
$page->call('mountAction',$call[1],json_decode($call[2],true,flags:JSON_THROW_ON_ERROR),$context)
    ->assertSet('mountedActions.0.name','override')->assertSet('mountedActions.0.context',['recordKey'=>(string)$enom->id])
    ->assertActionDataSet(['pricing'=>'default']);
same('Save and publish',$page->instance()->getMountedAction()->getModalSubmitActionLabel(),'Shortcut opens the current pricing form');
$page->unmountAction();
foreach([
    array_values(array_diff($permissions,[Review::permission('override'),Review::permission('remove_override')])),
    array_values(array_diff($permissions,[Review::permission('publish')])),
] as $withoutPrices){
    $restricted=staffUser($withoutPrices);
    $hidden=Livewire::actingAs($restricted)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertActionHidden('override');
    same(0,$priceButtons($hidden->html())->length,'Missing price-edit or publish grants hides the section shortcut');
}
Auth::setUser($u);
same('.com',$page->instance()->getTitle(),'TLD page title is the TLD');
foreach(['For sale','Change registrar','More','Sales','Registrar for new orders','Existing domains stay with their registrar','Terms','1 to 3 years',
    'Renewal grace period','Redemption period','Not set','Late renewals are off for this TLD.','Restoring expired domains is off for this TLD.','Price sync','Last sync','Oct 18, 2026 12:00 UTC','Valid until Oct 19, 2026 12:00 UTC',
    'Prices by registrar account','Edit prices','Account','Pricing','Registration','Renewal','Transfer','Default pricing','New orders','History','No changes yet'] as $see){$page->assertSee($see);}
$plain=$text($page->html());
expect(!str_contains($plain,'No lifecycle policy saved'),'Unset grace and redemption are not a fault');
expect(str_contains($plain,'OFFLINE DEMO NameSilo (synthetic) Demo'),'Prices table: Demo tag after the demo account');
expect(str_contains($plain,'Enom TEST identity check New orders'),'Prices table: the account for new orders is marked');
$page->assertDontSee('Last good prices')->assertDontSee('Sync failed');$clean($page->html(),'TLD page synced');
same(['label'=>'For sale','tone'=>'success','reason'=>null],TldFacts::salesState($enom->fresh()),'Healthy TLD is for sale');

// The detail table shares page facts for prices, sellers and holders as accounts grow.
$detailRead=static function($grants)use($enom):array{
    DB::flushQueryLog();DB::enableQueryLog();$html=TldFacts::accountsTable($enom,$grants)->toHtml();$queries=DB::getQueryLog();DB::disableQueryLog();
    $counts=[];foreach(['domains_prices','domains_domains','domains_sales_policies','domains_accounts'] as $table){$counts[$table]=count(array_filter($queries,fn($q)=>($table==='domains_accounts'?str_starts_with($q['query'],'select * from "domains_accounts" '):str_contains($q['query'],'from "'.$table.'"'))));}
    return [$html,$counts];
};
$grants=Staff::requireView();[, $twoAccounts]=$detailRead($grants);
DB::beginTransaction();
foreach([1,2] as $i){
    $binding=fixtureAccount((int)App\Models\Server::max('id')+1,'enom','detail-table-'.$i);
    App\Models\Server::whereKey($binding['server_id'])->update(['name'=>'<Extra & '.$i.'>']);
    $saved=$enom->getRawOriginal();unset($saved['id']);DB::table('domains_tlds')->insert([...$saved,...$binding]);
    foreach(Price::where('server_id',$enom->server_id)->get() as $price){$saved=$price->getRawOriginal();unset($saved['id']);DB::table('domains_prices')->insert([...$saved,...$binding]);}
}
[$detail,$fourAccounts]=$detailRead($grants);
// Default-rule validation checks one account per server, in addition to the shared account read.
same(3,$twoAccounts['domains_accounts'],'Two accounts use one shared read and two default-rule checks');
same(5,$fourAccounts['domains_accounts'],'Four accounts use one shared read and four default-rule checks, with no demo-tag reads');
unset($twoAccounts['domains_accounts'],$fourAccounts['domains_accounts']);
same($twoAccounts,$fourAccounts,'Price, holder and seller reads stay fixed across detail accounts');
foreach($fourAccounts as $table=>$count){same(1,$count,'One detail query for '.$table);}
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
[$hidden]=$detailRead($withoutCosts);expect(!str_contains($hidden,'<th scope="col" style="padding:8px 12px;text-align:left;white-space:nowrap;font-weight:500">Cost'),'Supplied grants hide cost even when current staff may read it');
[$shown]=$detailRead($viewerGrants);expect(str_contains($shown,'Registrations and transfers below cost are held.'),'Supplied cost grant keeps cost help');
DB::rollBack();

// Sync failed: the stored cause is shown as words, with the last good prices kept.
DB::table('domains_observation_queues')->insert(['server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,
    'catalogue_hold'=>'catalogue_reader_unavailable','catalogue_audit'=>json_encode([['code'=>'catalogue_reader_unavailable','server_id'=>$enom->server_id,
        'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T13:05:00+00:00']])]);
$cause="Couldn't load the registrar account's settings";
same(['cause'=>$cause,'at'=>'Oct 18, 2026 13:05 UTC'],TldFacts::syncFailure($enom->server_id),'Stored failure code becomes its cause');
same('Sync failed: '.$cause,TldFacts::refreshState($enom->server_id),'Refresh state names the cause');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()
    ->assertSee('Price sync failed for 1 registrar account.')->assertSee('Enom TEST identity check: '.$cause.'.')->assertSee('Saved prices are still used.')->assertSee('Open Registrars');
$clean($list->html(),'List sync failed');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()
    ->assertSee('Sync failed')->assertSee($cause.' · Oct 18, 2026 13:05 UTC')->assertSee('Open Registrars')->assertSee('Last good prices')->assertSee('Valid until Oct 19, 2026 12:00 UTC');
$clean($page->html(),'TLD page sync failed');
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$silo->id])->assertSuccessful()->assertDontSee('Sync failed');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update(['catalogue_hold'=>'read_unavailable','catalogue_audit'=>'[]']);
same(['cause'=>'Registrar read failed','at'=>null],TldFacts::syncFailure($enom->server_id),'Older hold code keeps a plain cause and no time');
// A retained failure older than the latest saved prices is history, not a current failure.
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update(['catalogue_hold'=>null,'catalogue_audit'=>json_encode([['code'=>'read_deadline',
    'server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T11:00:00+00:00']])]);
same(null,TldFacts::syncFailure($enom->server_id),'Prices synced after the failure clear it');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update(['catalogue_audit'=>json_encode([['code'=>'read_deadline',
    'server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T12:30:00+00:00']])]);
same(['cause'=>"Registrar didn't answer in time",'at'=>'Oct 18, 2026 12:30 UTC'],TldFacts::syncFailure($enom->server_id),'A failure after the latest prices still shows');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->delete();

// No registrar for new orders.
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null]);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertSee('No registrar selected')->assertSee('Change registrar');
expect(str_contains($text($page->html()),'Registrar for new orders Missing'),'No registrar for new orders is Missing');
$clean($page->html(),'TLD page no registrar');
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'No registrar selected'],TldFacts::salesState($enom->fresh()),'No registrar for new orders is not for sale');
$page->assertSee('Not for sale')->assertDontSee('For sale');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertSee('No registrar selected')->assertDontSee('For sale');
expect(!str_contains($text($list->html()),'new orders'),'List: no account takes new orders');
$clean($list->html(),'List no registrar');
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>$enom->server_id]);

// Saved grace and redemption policy.
$server=App\Models\Server::findOrFail($enom->server_id);$at='2026-10-17T09:00:00Z';
$server->settings()->create(['key'=>LifecyclePolicies::key('com'),'type'=>'string','encrypted'=>true,'value'=>json_encode(['binding'=>$enom->account_fingerprint,'tld'=>'com','facts'=>[
    'grace_days'=>30,'restore_days'=>30,'grace_supported'=>true,'restore_supported'=>true,'auto_renew_only'=>false,'conflicting'=>false,
    'verified_at'=>$at,'source'=>'https://registry.example.test/policy','grace_extra'=>'0.0000','grace_currency'=>'USD','grace_verified_at'=>$at,'grace_source'=>'Registrar price list',
    'restore_fee'=>'80.0000','restore_currency'=>'USD','restore_verified_at'=>$at,'restore_source'=>'Registrar price list']],JSON_THROW_ON_ERROR)]);
DB::table('settings')->where('key',LifecyclePolicies::key('com'))->update(['updated_at'=>'2026-10-17 09:00:00']);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()
    ->assertSee('30 days')->assertSee('Last saved Oct 17, 2026')->assertSee('Restore fee')->assertSee('USD 80.00')->assertDontSee('Late renewal fee')
    ->assertDontSee('Late renewals are off for this TLD.')->assertDontSee('Registry auto-renew only');
$clean($page->html(),'TLD page lifecycle');

// Below cost: red Cost with its reason, and the TLD is not for sale.
$rules=new \Paymenter\Extensions\Servers\Domains\Services\PricingRules;
$rules->saveDefaultRule($enom->server_id,['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>array_fill_keys(['register','renew','transfer'],'1.00')],$prices->republish(...));
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'Below cost'],TldFacts::salesState($enom->fresh()),'Below cost is not for sale');
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertSee('Not for sale')->assertSee('Below cost')->assertSee('USD 1.00');
$clean($list->html(),'List below cost');
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()->assertSee('Not for sale')->assertSee('Below cost');
$clean($page->html(),'TLD page below cost');

// Without the costs permission no cost is shown.
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);
$list=Livewire::actingAs($viewer)->test(ListTlds::class)->assertSuccessful()->assertDontSee('USD 8.00');
$page=Livewire::actingAs($viewer)->test(ViewTld::class,['record'=>$silo->id])->assertSuccessful()->assertDontSee('USD 8.00')->assertDontSee('Restore fee');
echo "Native TLDs & pricing list and TLD page passed.\n";$nativeSuccessful=true;
