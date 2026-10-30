<?php
// Native staff save and sync reproduction; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{PriceCatalogue,StaffSettingsReview as Review,StaffSettingsWrites};

// Only the external registrar wire is synthetic. Both save and sync use the real actions and services.
$wire=$GLOBALS['catalogueWire'];
$GLOBALS['catalogueWire']=static function($handle,array $options,array $form)use($wire):bool {
    $write=$options[CURLOPT_WRITEFUNCTION];
    $options[CURLOPT_WRITEFUNCTION]=static function($h,string $body)use($write):int {
        $changed=str_replace('<Price>10.0000</Price>','<Price>8.9500</Price>',$body);
        return $write($h,$changed)===strlen($changed)?strlen($body):0;
    };
    return $wire($handle,$options,$form);
};
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
    'domains.enom_test_sends_per_day'=>500,'domains.enom_test_sends_per_minute'=>100000]);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic pricing reproduction','type'=>'server','extension'=>'Domains','enabled'=>true]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction(setupAction('configure',$server->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token','reason'=>'Connect synthetic pricing fixture'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
$settings=app(StaffSettingsWrites::class);
$review=static fn(string $action,?int $tld=null)=>new Review($staff->id,$server->id,$tld,$action,
    Review::ticket($staff->id,$server->id,$tld,$action,Review::stamp($server->id,$tld)),'Set up synthetic pricing fixture');
$settings->run($review('acceptance'),['changes'=>['com'=>['terms'=>[1],'transfer'=>false]]]);
$settings->run($review('import'),[]);
$tld=Tld::where('server_id',$server->id)->where('tld','com')->sole();
$settings->run($review('tld_settings',$tld->id),['enabled'=>true,'register'=>true,'renew'=>true,'transfer'=>false,'min_years'=>1,'max_years'=>1,'verified'=>true]);
$edit=static fn()=>Livewire::actingAs($staff)->test(ViewTld::class,['record'=>$tld->id])->mountAction('override')->assertActionMounted('override');
$retail=static fn(string $action)=>PriceCatalogue::retail(Price::where(['server_id'=>$server->id,'tld'=>'com','action'=>$action,'term'=>1])->sole()->retail);
$edit()->setActionData(['pricing'=>'custom','mode'=>'markup_percent','markup'=>'15.00','round_up'=>'ending_99','reason'=>'Reproduce the saved walk rule'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices published');
same('10.99',$retail('register'),'Original saved rule reproduces the walk price');
$edit()->assertActionDataSet(['pricing'=>'custom','mode'=>'markup_percent','markup'=>'15.00','round_up'=>'ending_99'])
    ->setActionData(['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.00','round_up'=>'half','reason'=>'x'])
    ->callMountedAction()->assertHasActionErrors(['reason'=>'min'])
    ->assertActionDataSet(['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.00','round_up'=>'half'])
    ->setActionData(['reason'=>'Add one dollar and round up to half a dollar'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices published');
$edit()->assertActionDataSet(['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.00','round_up'=>'half'])->unmountAction();
same('10.00',$retail('register'),'Saving the exact walk rule immediately publishes ten dollars');
$history=Review::historyRows($server->fresh());$saved=end($history);
same('Save TLD override',$saved['action'],'Successful strategy save is retained in the settings audit');
same('1.00',$saved['input']['markup'],'Audit retains the submitted dollar markup');
Livewire::actingAs($staff)->test(ListTlds::class)->mountAction('sync_prices_'.$server->id)
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified(Filament\Notifications\Notification::make()->success()->title('Prices synced')
        ->body('1 TLD synced.'));
$edit()->assertActionDataSet(['pricing'=>'custom','mode'=>'markup_amount','markup'=>'1.00','round_up'=>'half'])->unmountAction();
same('10.00',$retail('register'),'Sync keeps the custom rule and publishes ten dollars');
same('10.00',$retail('renew'),'Sync also applies the saved rule to renewal');
// Changing account pricing leaves an own rule alone; explicitly choosing the default adopts it.
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction(setupAction('default_rule',$server->id))
    ->setActionData(['mode'=>'markup_amount','markup'=>'2.00','round_up'=>'half','reason'=>'Check account default and own pricing scopes'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
same('10.00',$retail('register'),'Changing the account default leaves the custom published price alone');
Livewire::actingAs($staff)->test(ListTlds::class)->mountAction('sync_prices_'.$server->id)
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices synced');
same('10.00',$retail('register'),'Sync still uses the custom rule after the account default changes');
$edit()->setActionData(['pricing'=>'default','reason'=>'Use the saved account default for this ending'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices published');
$edit()->assertActionDataSet(['pricing'=>'default','mode'=>'markup_amount','markup'=>'2.00','round_up'=>'half'])->unmountAction();
same('11.00',$retail('register'),'Choosing account default publishes its two dollar markup');
Livewire::actingAs($staff)->test(ListTlds::class)->mountAction('sync_prices_'.$server->id)
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified(Filament\Notifications\Notification::make()->success()->title('Prices synced')
        ->body('1 TLD synced.'));
// The message stays short and keeps what staff must act on.
same('3 TLDs synced. Not synced: .net. They keep their last cost. Left for the next sync: .a, .b, .c and 2 more. Sync prices again in a minute.',
    SettingsActions::syncSummary(['synced'=>['com','org','io'],'failed'=>['net'=>'catalogue_import_failed'],'deferred'=>['a','b','c','d','e']]),'Sync summary');
same('11.00',$retail('register'),'Sync applies the explicitly selected account default');
// Sync is one click from the two pages that ask for it.
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->assertSee('Sync prices')->assertDontSee('Open TLDs');
Price::where('server_id',$server->id)->update(['observed_at'=>now()->subDays(60)]);
Livewire::actingAs($staff)->test(ViewTld::class,['record'=>$tld->id])->mountAction('syncPrices')->callMountedAction()->assertHasNoActionErrors()->assertNotified('Prices synced');
echo "Walk rule saved through Edit prices, reopened, audited and synced: USD 10.99 to USD 10.00.\n";
echo "Required reason errors stay on the form; own and account-default pricing keep their scopes through sync.\n";
$nativeSuccessful=true;
