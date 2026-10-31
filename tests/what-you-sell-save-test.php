<?php
// Saving What you sell leaves other endings selling; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,NativeCatalogue,PublicationEligibility,StaffSettingsReview as Review,StaffSettingsWrites,Storefront};

config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_test_sends_per_day'=>500,'domains.enom_test_sends_per_minute'=>100000,'domains.hidden_endings'=>['biz']]);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic picker save','type'=>'server','extension'=>'Domains','enabled'=>true]);
$GLOBALS['catalogueList']=['com','net','org'];$GLOBALS['catalogueMaximum']=['com'=>3];
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction(setupAction('configure',$server->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token','reason'=>'Connect synthetic picker'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
$settings=app(StaffSettingsWrites::class);$front=app(Storefront::class);$account=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($account);
$review=static fn(string $action,?int $tld=null)=>new Review($staff->id,$server->id,$tld,$action,Review::ticket($staff->id,$server->id,$tld,$action,Review::stamp($server->id,$tld)),in_array($action,Review::NO_REASON,true)?'':'Set up synthetic picker');
$write=static fn(string $action,array $data=[],?int $tld=null)=>$settings->run($review($action,$tld),$data);

// .com is on sale for 1 year.
$write('acceptance',['changes'=>['com'=>['terms'=>[1],'transfer'=>false]]]);$write('import',['ending'=>'com']);
$com=Tld::where(['server_id'=>$server->id,'tld'=>'com'])->sole();
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$com->id);$write('publish',[],$com->id);
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$com->id);
same(['com'],array_column($front->endings(),'tld'),'.com is on sale');
$cart=commerceCart(commerceUser());$line=commerceAdd($cart,app(NativeCatalogue::class)->product($com->fresh(),'register'),placeholderComFixtureName('picker-cart-placeholder.com'));same(1,$cart->items()->count(),'A customer has .com in the cart');

// Adding .net does not stop .com selling, and no read runs to keep it selling.
$before=count($GLOBALS['catalogueCalls']);
$write('acceptance',['changes'=>['net'=>['terms'=>[1],'transfer'=>false]]]);
same($before,count($GLOBALS['catalogueCalls']),'The write alone makes no calls');
same(['com'],array_column($front->endings(),'tld'),'.com stays on sale after adding .net');
PublicationEligibility::require($com->fresh(),'register',1);
same(1,$cart->items()->count(),'The .com cart line survives');

// Widening .com keeps its 1-year sale, with no price yet for the new years. Those stay closed until prices arrive.
$write('acceptance',['changes'=>['com'=>['terms'=>[1,2],'transfer'=>false]]]);
same(['com'],array_column($front->endings(),'tld'),'.com stays on sale after adding a year');PublicationEligibility::require($com->fresh(),'register',1);
try{PublicationEligibility::require($com->fresh(),'register',2);throw new LogicException('Unpriced year must stay closed.');}catch(DomainException){}
same(2,$com->fresh()->max_years,'Ticked years reach the TLD range');
same(true,$com->fresh()->register_enabled,'Registration stays on');

// Narrowing drops the removed year's evidence; re-adding it needs a fresh read.
$write('import',['ending'=>'com']);PublicationEligibility::require($com->fresh(),'register',2);
$write('acceptance',['changes'=>['com'=>['terms'=>[1],'transfer'=>false]]]);PublicationEligibility::require($com->fresh(),'register',1);
$write('acceptance',['changes'=>['com'=>['terms'=>[1,2],'transfer'=>false]]]);
try{PublicationEligibility::require($com->fresh(),'register',2);throw new LogicException('A removed year needs a fresh read.');}catch(DomainException){}

// Turning an ending off stops it at once and takes its cart lines. Other endings carry on.
$write('acceptance',['changes'=>['net'=>null]]);same(['com'],array_column($front->endings(),'tld'),'.com carries on after .net is removed');same(1,$cart->items()->count(),'The .com cart line survives another ending going away');
$write('acceptance',['changes'=>['com'=>null]]);same([],$front->endings(),'.com stops at once');same(0,$cart->items()->count(),'Turning .com off takes its cart lines');

// Refusals, nothing saved.
$revision=CatalogueAcceptances::load($binding)->revision;
foreach([
    [['changes'=>['biz'=>['terms'=>[1],'transfer'=>false]]],'This TLD is hidden.','Hidden TLDs cannot be added by hand'],
    [['changes'=>['org'=>['terms'=>[],'transfer'=>false]]],'Choose at least one year.','An ending needs a year'],
    [['changes'=>[]],'Nothing to save.','An empty save is refused'],
] as [$data,$message,$why]){tldRefusal(fn()=>$write('acceptance',$data),$message);same($revision,CatalogueAcceptances::load($binding)->revision,$why);}
foreach(['This TLD is hidden.'=>"That TLD isn't offered here. Nothing was saved.",'Choose at least one year.'=>'Tick at least one year. Nothing was saved.','Nothing to save.'=>'Nothing changed. Nothing was saved.',
    'Enom TEST Purchase route acceptance is unavailable.'=>'This account can’t take orders on this site yet. See System checks. Nothing was saved.',
    'Enom TEST acquisition route acceptance is unavailable.'=>'This account can’t take orders on this site yet. See System checks. Nothing was saved.',
    'Live Enom Purchase route acceptance is unavailable.'=>'This account can’t take orders on this site yet. See System checks. Nothing was saved.',
    'Acceptance exceeds encrypted settings capacity.'=>'That is more TLDs than one account can save. Turn some off. Nothing was saved.'] as $error=>$text){
    same($text,SettingsActions::refusal(new DomainException($error),'acceptance'),'Plain refusal: '.$error);
}

// Hiding a TLD on sale closes new orders. It stays saved, and a later save keeps what its renewals rest on.
$write('acceptance',['changes'=>['org'=>['terms'=>[1],'transfer'=>true]]]);$write('import',['ending'=>'org']);
$org=Tld::where(['server_id'=>$server->id,'tld'=>'org'])->sole();
$write('tld_settings',['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true],$org->id);$write('publish',[],$org->id);
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$org->id);
same(['org'],array_column($front->endings(),'tld'),'.org is on sale');
config(['domains.hidden_endings'=>['biz','.ORG']]);Cache::flush();
same([],$front->endings(),'A hidden TLD leaves the store');
tldRefusal(fn()=>PublicationEligibility::require($org->fresh(),'register',1),'Acquisition acceptance unavailable.');
tldRefusal(fn()=>$write('acceptance',['changes'=>['org'=>['terms'=>[1],'transfer'=>false]]]),'This TLD is hidden.');
$renewal=Price::where(['server_id'=>$server->id,'tld'=>'org','action'=>'renew','term'=>1])->sole();
$write('acceptance',['changes'=>['net'=>['terms'=>[1],'transfer'=>false]]]);$accepted=CatalogueAcceptances::load($binding);
same(['register'=>[],'renew'=>[1],'transfer'=>[],'registry'=>false,'renewal_safe'=>true],$accepted->endings['org'],'Renewals stay open');
same($accepted->revision,$renewal->fresh()->eligibility['catalogue_revision'],'The renewal price stays good');
same(false,$org->fresh()->register_enabled,'The save turns registration off');
echo "What you sell save passed.\n";$nativeSuccessful=true;
