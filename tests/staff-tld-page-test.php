<?php
// Native TLDs & pricing list and TLD page: sales, price sync with its stored cause, prices by account; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
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
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['stable_identity'=>Illuminate\Support\Facades\Crypt::encryptString('beco-demo-namesilo')]);
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
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful()->assertCanSeeTableRecords($tlds);
foreach(['Registrar account','Renewal price','Cost','Domains','Status','Enom TEST identity check','OFFLINE DEMO NameSilo (synthetic)',
    'Enom · default pricing · new orders','NameSilo · default pricing','For sale','USD 8.00',$renew($enom)] as $see){$list->assertSee($see);}
$plain=$text($list->html());
expect(str_contains($plain,'OFFLINE DEMO NameSilo (synthetic) Demo'),'List: Demo tag after the demo account name');
expect(!str_contains($plain,'NameSilo · default pricing · new orders'),'List: only the selected account takes new orders');
$list->assertDontSee('Price sync failed');$clean($list->html(),'List synced');

$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful();
same('.com',$page->instance()->getTitle(),'TLD page title is the TLD');
foreach(['For sale','Change registrar','More','Sales','Registrar for new orders','Existing domains stay with their registrar','Terms','1 to 3 years',
    'Renewal grace period','Redemption period','No lifecycle policy saved','Price sync','Last sync','Oct 18, 2026 12:00 UTC','Valid until Oct 19, 2026 12:00 UTC',
    'Prices by registrar account','Edit prices','Account','Pricing','Registration','Renewal','Transfer','Default pricing','New orders','History','No changes yet'] as $see){$page->assertSee($see);}
$plain=$text($page->html());
expect(substr_count($plain,'Missing')>=2,'Grace and redemption without a policy are Missing');
expect(str_contains($plain,'OFFLINE DEMO NameSilo (synthetic) Demo'),'Prices table: Demo tag after the demo account');
expect(str_contains($plain,'Enom TEST identity check New orders'),'Prices table: the account for new orders is marked');
$page->assertDontSee('Last good prices')->assertDontSee('Sync failed');$clean($page->html(),'TLD page synced');
same(['label'=>'For sale','tone'=>'success','reason'=>null],TldFacts::salesState($enom->fresh()),'Healthy TLD is for sale');

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
$list=Livewire::actingAs($u)->test(ListTlds::class)->assertSuccessful();expect(!str_contains($text($list->html()),'new orders'),'List: no account takes new orders');
$clean($list->html(),'List no registrar');

// Saved grace and redemption policy.
$server=App\Models\Server::findOrFail($enom->server_id);$at='2026-10-17T09:00:00Z';
$server->settings()->create(['key'=>LifecyclePolicies::key('com'),'type'=>'string','encrypted'=>true,'value'=>json_encode(['binding'=>$enom->account_fingerprint,'tld'=>'com','facts'=>[
    'grace_days'=>30,'restore_days'=>30,'grace_supported'=>true,'restore_supported'=>true,'auto_renew_only'=>false,'conflicting'=>false,
    'verified_at'=>$at,'source'=>'https://registry.example.test/policy','grace_extra'=>'0.0000','grace_currency'=>'USD','grace_verified_at'=>$at,'grace_source'=>'Registrar price list',
    'restore_fee'=>'80.0000','restore_currency'=>'USD','restore_verified_at'=>$at,'restore_source'=>'Registrar price list']],JSON_THROW_ON_ERROR)]);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$enom->id])->assertSuccessful()
    ->assertSee('30 days')->assertSee('Updated Oct 17, 2026')->assertSee('Restore fee')->assertSee('USD 80.00')->assertDontSee('Late renewal fee')
    ->assertDontSee('No lifecycle policy saved')->assertDontSee('Registry auto-renew only');
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
