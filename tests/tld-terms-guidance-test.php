<?php
// Open in What you sell and Sync prices say what this account allows; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,SellRow};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();pinStaffClock($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY,Staff::COSTS];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));
// The demo account: .com for 1 to 3 years with annual renewal; transfers are off.
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
CatalogueAcceptances::save($binding,CatalogueAcceptances::load($binding)->revision,['com'=>['register'=>[1,2,3],'renew'=>[1],'transfer'=>[],'registry'=>true,'renewal_safe'=>true]]);
$t->update(['transfer_enabled'=>false]);$t=$t->fresh();
$before=$t->only(['min_years','max_years','enabled','register_enabled','transfer_enabled','renew_enabled']);

// Follow the native action URL: checking its string alone missed an ignored query value.
$detail=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id]);
$url=$detail->instance()->getAction('tld_settings',false)->getUrl();parse_str(parse_url($url,PHP_URL_QUERY),$query);
$rows=[];foreach(['com','com.au','org'] as $ending){$rows[$ending]=SellRow::firstOrCreate(['server_id'=>$t->server_id,'account_fingerprint'=>$binding->fingerprint,'tld'=>$ending]);}
$focused=Livewire::withQueryParams($query)->actingAs($u)->test(WhatYouSell::class)->assertSet('tableSearch','com')
    ->assertCountTableRecords(1)->assertCanSeeTableRecords([$rows['com']])->assertCanNotSeeTableRecords([$rows['com.au'],$rows['org']]);
$focused->set('tableSearch','')->assertCanSeeTableRecords(array_values($rows));
$refusal=static fn(string $message,array $data)=>SettingsActions::refusal(new DomainException($message),'tld_settings',$data,(int)$t->server_id,(int)$t->id);
// Accepted terms describe our account setup, never a registrar's own limits.
foreach([
    ['Explicit verified widening required.',['min_years'=>1,'max_years'=>10,'verified'=>true]],
    ['Explicit verified widening required.',['min_years'=>1,'max_years'=>3,'verified'=>false]],
    ['Acquisition acceptance unavailable.',['transfer'=>true]],
    ['Acquisition acceptance unavailable.',['register'=>true]],
    ['Explicit restoration required.',[]],
] as [$message,$data]){
    $text=$refusal($message,$data);
    same(false,str_contains($text,'Enom TEST allows')||str_contains($text,'does not allow'),'Refusal names account setup rather than registrar limits: '.$message);
    expect(str_contains($text,'What you sell'),'Refusal names where to change the setup: '.$message);
}
same('This account is set up to sell .com for 1 to 3 years. Pick from 1 to 3, or change What you sell. Nothing was saved.',$refusal('Explicit verified widening required.',['min_years'=>1,'max_years'=>10,'verified'=>true]),'Unallowed years are named');
same('Transfers are not set up for .com on this account. Change sales under What you sell. Turn off Transfers. Nothing was saved.',$refusal('Acquisition acceptance unavailable.',['transfer'=>true]),'Unallowed transfers are named');
same('To turn sales back on, tick the box to confirm this account is set up for them. Check What you sell. Nothing was saved.',$refusal('Explicit restoration required.',[]),'Unconfirmed restore is named');
same('Customers still own .com domains here, so renewals must stay on. Nothing was saved.',$refusal('Retained holders require annual renewal support.',[]),'Renewal rule is named');
same('Prices for .com are missing or out of date. Use Sync prices on the TLD list, then try again. Nothing was saved.',$refusal('Catalogue publication evidence changed.',[]),'Stale prices are named');

$fee=['fee_percent'=>'3','fee_fixed'=>'0.30','reason'=>'Set the card fee'];
$sellPage=Livewire::actingAs($u)->test(WhatYouSell::class);
foreach(['3','4'] as $percent){$sellPage->mountAction('default_pricing')->setActionData([...$fee,'fee_percent'=>$percent])->callMountedAction()->assertNotified('Saved')->assertHasNoActionErrors();}
same('4.00',Paymenter\Extensions\Servers\Domains\Services\CardFee::read()['percent'],'The second save lands on the same page');

// Transfers off is not Missing: Sync can never fill it.
Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'transfer'])->delete();$grants=Staff::requireView();
$table=(string)TldFacts::accountsTable($t->fresh(),$grants);expect(str_contains($table,'>Off<'),'Off is shown as off');expect(!str_contains($table,'Use Sync prices'),'Off does not ask for a sync');
$t->update(['transfer_enabled'=>true]);$table=(string)TldFacts::accountsTable($t->fresh(),$grants);expect(str_contains($table,'Use Sync prices on the TLD list.'),'Transfers on with no price is still Missing');
// A synced row with no cost: the registrar does not sell it, so nothing is Missing and no sync is asked for.
$offered=Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'register'])->orderBy('term')->firstOrFail()->getRawOriginal();unset($offered['id']);Illuminate\Support\Facades\DB::table('domains_prices')->insert([...$offered,'action'=>'transfer','wholesale'=>null,'retail'=>null]);
$table=(string)TldFacts::accountsTable($t->fresh(),$grants);expect(str_contains($table,TldFacts::NOT_OFFERED)&&!str_contains($table,'Use Sync prices on the TLD list.'),'Registrar does not sell it: Not offered, no sync asked');
Price::where(['server_id'=>$t->server_id,'tld'=>'com','action'=>'transfer'])->delete();$t->update(['transfer_enabled'=>false]);

// Preserved bounds need not start with an accepted registration year.
$accepted=CatalogueAcceptances::save($binding,CatalogueAcceptances::load($binding)->revision,['com'=>['register'=>[2],'renew'=>[1],'transfer'=>[],'registry'=>true,'renewal_safe'=>true]]);
$t->update(['min_years'=>1,'max_years'=>10]);
$rates=[];foreach([['register',2,'6.00'],['renew',1,'8.00']] as [$action,$term,$cost]){$rates[]=new \Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action,$term,$cost,'synthetic',$now,$now->modify('+1 day'));}
$prices->import(new \Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($binding,'com',$rates,[2],true,true),$accepted,static fn()=>null,$now);
$t->update(['enabled'=>false,'register_enabled'=>false,'transfer_enabled'=>false,'renew_enabled'=>false]);$calls=$reads->calls;
// Native recovery is needed after an old staff stop or Don't sell then Sell; it keeps the existing writer guards.
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertActionVisible('restore_sales')->mountAction('restore_sales')
    ->assertActionMounted('restore_sales')->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded')->assertActionHidden('restore_sales');
same([true,true,false,true],array_values($t->fresh()->only(['enabled','register_enabled','transfer_enabled','renew_enabled'])),'Restore uses current accepted actions, including renewals');
same([1,10,true],[$t->fresh()->min_years,$t->fresh()->max_years,$t->fresh()->register_enabled],'Restoration uses an eligible later year without changing bounds');
same(2,$sellers->selection('com','register',2,$now)->term,'Restored registrations sell the accepted year');
same($calls,$reads->calls,'Restoring registration uses saved evidence only');
// Older saves could stop just one action; native visibility must keep each recovery reachable.
$flags=['enabled'=>true,'register_enabled'=>true,'transfer_enabled'=>false,'renew_enabled'=>true];
foreach(['renew_enabled'=>true,'register_enabled'=>true,'transfer_enabled'=>false,'enabled'=>true] as $off=>$visible){
    $t->fresh()->update([...$flags,$off=>false]);
    $page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id]);
    $visible?$page->assertActionVisible('restore_sales'):$page->assertActionHidden('restore_sales');
}
$t->fresh()->update($flags);Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('restore_sales');
// A broken saved acceptance must not take down the page or hide a stopped renewal.
$setting=App\Models\Server::findOrFail($t->server_id)->settings()->where('key',CatalogueAcceptances::KEY)->sole();$savedAcceptance=$setting->value;
$setting->value='invalid';$setting->save();$t->fresh()->update(['renew_enabled'=>false]);
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSuccessful()->assertActionVisible('restore_sales');
$setting->value=$savedAcceptance;$setting->save();$t->fresh()->update($flags);
echo "Terms guidance passed.\n";

// W6: Sync prices names the endings it syncs and where to add more.
config(['domains.enom_catalogue.enabled'=>true,'domains.enom_identity.environment'=>'test']);
Livewire::actingAs($u)->test(ListTlds::class)->mountAction('sync_prices_'.$t->server_id)->assertActionMounted('sync_prices_'.$t->server_id)
    ->assertMountedActionModalSee('Gets current costs from the Enom test account for the 1 TLD this account sells: .com.')
    ->assertMountedActionModalSee('What you sell')->assertMountedActionModalDontSee('all TLDs');
echo "Sync prices scope passed.\n";$nativeSuccessful=true;
