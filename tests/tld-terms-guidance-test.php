<?php
// Edit terms and Sync prices say what this account allows; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();pinStaffClock($now);$t=$tlds[0];
$permissions=[Staff::VIEW,Staff::POLICY,Staff::COSTS];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));
// The demo account: .com for 1 to 3 years with annual renewal; transfers are off.
$binding=CatalogueAcceptances::binding(Account::where('server_id',$t->server_id)->sole());
CatalogueAcceptances::save($binding,CatalogueAcceptances::load($binding)->revision,['com'=>['register'=>[1,2,3],'renew'=>[1],'transfer'=>[],'registry'=>true,'renewal_safe'=>true]]);
$t->update(['transfer_enabled'=>false]);$t=$t->fresh();
$before=$t->only(['min_years','max_years','enabled','register_enabled','transfer_enabled','renew_enabled']);

// W8: the dialog uses plain words, shows the allowed years and does not offer transfers.
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('tld_settings')->assertActionMounted('tld_settings')
    ->assertMountedActionModalDontSee('Enom TEST allows')->assertMountedActionModalDontSee('does not allow')
    ->assertMountedActionModalSee('This account is set up to sell .com for 1 to 3 years. Change years under What you sell.')
    ->assertMountedActionModalSee('Transfers are not set up for .com on this account. Change sales under What you sell.')
    ->assertMountedActionModalDontSee('Widening')->assertMountedActionModalDontSee('acceptance')->assertMountedActionModalDontSee('holders')
    ->assertFormFieldDisabled('transfer');
$page->setActionData(['min_years'=>1,'max_years'=>10,'enabled'=>true,'register'=>true,'transfer'=>true,'renew'=>true,'verified'=>true,'reason'=>'Offer ten years'])
    ->callMountedAction()->assertHasActionErrors(['max_years'=>'max']);
same($before,$t->fresh()->only(array_keys($before)),'Refused terms change nothing');

// A refusal from the writer names the real reason.
$t->update(['max_years'=>2]);
Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('tld_settings')
    ->setActionData(['min_years'=>1,'max_years'=>3,'verified'=>false,'reason'=>'Offer three years'])->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified(\Filament\Notifications\Notification::make()->danger()->title('Change refused')->body('To add years, tick the box to confirm they match this account’s setup. Check What you sell. Nothing was saved.'));
same(2,$t->fresh()->max_years,'Unconfirmed longer terms are not saved');
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

// Minimum above maximum: the dialog stays open with the real limit.
$t->update(['max_years'=>3]);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('tld_settings')->setActionData(['min_years'=>3,'max_years'=>1,'reason'=>'Swap the years'])
    ->callMountedAction()->assertHasActionErrors(['max_years'])->assertActionMounted('tld_settings')->assertNotNotified();
same(['The minimum is above the maximum. Pick from 1 to 3.'],$page->errors()->get('mountedActions.0.data.max_years'),'The real limit is shown');same(3,(int)$page->get('mountedActions.0.data.min_years'),'The input is kept');
same(['Pick from 1 to 3, with the minimum no more than the maximum. Nothing was saved.'],[$refusal('Valid term bounds required.',[])],'A late refusal names the same limit');
same(1,$t->fresh()->min_years,'Swapped years save nothing');

// Saving with nothing changed writes nothing and says so.
$account=fn()=>App\Models\Server::find($t->server_id);$rows=fn()=>[count(Review::historyRows($account())),Illuminate\Support\Facades\DB::table(Review::KEYS)->count()];$audit=$rows();
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->mountAction('tld_settings')->setActionData(['reason'=>'Nothing changes here'])->callMountedAction()
    ->assertNotified(\Filament\Notifications\Notification::make()->info()->title('Nothing to save')->body('These terms are the same as before.'))->assertSet('mountedActions',[]);
same($audit,$rows(),'No history row and no used review for a save with no change');

// A real save updates the page, and the next save works with no reload.
$page->mountAction('tld_settings')->setActionData(['max_years'=>2,'reason'=>'Sell two years'])->callMountedAction()->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Change recorded'))->assertSee('1 to 2 years')->assertSee('Terms changed');
$page->mountAction('tld_settings')->setActionData(['max_years'=>1,'reason'=>'Sell one year'])->callMountedAction()->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Change recorded'));
same(1,$t->fresh()->max_years,'The second save on one page lands');$page->assertSee('1 year');$t->update(['max_years'=>2]);
$fee=['fee_percent'=>'3','fee_fixed'=>'0.30','reason'=>'Set the card fee'];
$accountPage=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id]);
foreach(['3','4'] as $percent){$accountPage->mountAction(setupAction('card_fee',$t->server_id))->setActionData([...$fee,'fee_percent'=>$percent])->callMountedAction()->assertNotified('Saved')->assertHasNoActionErrors();}
expect(str_contains($accountPage->html(),'4%'),'The account page shows the second save');

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
$t->update(['register_enabled'=>false]);$calls=$reads->calls;
$ticket=Review::ticket($u->id,$t->server_id,$t->id,'tld_settings',Review::stamp($t->server_id,$t->id));
(new StaffSettingsWrites($native,$sellers,$bindings,true))->run(new Review($u->id,$t->server_id,$t->id,'tld_settings',$ticket,'Restore the supported two-year registration'),
    ['min_years'=>1,'max_years'=>10,'enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'verified'=>true],$now);
same([1,10,true],[$t->fresh()->min_years,$t->fresh()->max_years,$t->fresh()->register_enabled],'Restoration uses an eligible later year without changing bounds');
same(2,$sellers->selection('com','register',2,$now)->term,'Restored registrations sell the accepted year');
same($calls,$reads->calls,'Restoring registration uses saved evidence only');
echo "Edit terms guidance passed.\n";

// W6: Sync prices names the endings it syncs and where to add more.
config(['domains.enom_catalogue.enabled'=>true,'domains.enom_identity.environment'=>'test']);
Livewire::actingAs($u)->test(ListTlds::class)->mountAction('sync_prices_'.$t->server_id)->assertActionMounted('sync_prices_'.$t->server_id)
    ->assertMountedActionModalSee('Gets current costs from the Enom test account for the 1 TLD this account sells: .com.')
    ->assertMountedActionModalSee('What you sell')->assertMountedActionModalDontSee('all TLDs');
echo "Sync prices scope passed.\n";$nativeSuccessful=true;
