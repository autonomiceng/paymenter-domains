<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real encrypted models and native Filament rendering are the affected boundaries.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\{Tld,Price};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
// The edit form needs one saved seller, not cart listeners or a second registrar account.
extract(commerceFixture(true,active:true,registrars:['enom']));tldPanel();pinStaffClock($now);
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();

$defaults=OfferState::decode(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultTransfers'=>true])]);

// The form reads the saved TLD, including seller permission and staff stops. Projected JSON aliases
// can be stale and differ between MariaDB and SQLite; neither may override the saved setting.
$page=new Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
$edit=new ReflectionMethod($page,'editFields');
$probe=new ReflectionProperty(Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::class,'probe');$probe->setValue(null,[StaffCatalogue::class=>$source]);
try{
    $original=OfferState::decode($t->catalogue_intent);
    $cases=[
        'own off'=>[false,true,true,[],'Off'],
        'own on'=>[true,false,true,[],'On'],
        'default on'=>[null,true,true,[],'On'],
        'default off'=>[null,false,true,[],'Off'],
        'seller off'=>[true,true,false,[],'Off'],
        'staff stop'=>[true,true,true,['transfer'],'Off'],
    ];
    foreach($cases as $name=>[$own,$inherited,$seller,$stops,$expected]){
        DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json([...$original,'transfers'=>$own,'stops'=>$stops])]);
        DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultTransfers'=>$inherited])]);
        DB::table('domains_sales_policies')->where('tld','com')->update(['transfer_enabled'=>$seller]);
        same($own,OfferState::decode($t->fresh()->catalogue_intent)['transfers'],'The fixture persists the own transfer choice for '.$name);
        foreach(['false',0,false,'true',1,true,'null',null] as $databaseValue){
            $row=Paymenter\Extensions\Servers\Domains\Models\OfferRow::hydrate([['id'=>$t->id,'server_id'=>$t->server_id,'tld'=>'com','longest'=>3,'transfer'=>$databaseValue]])->first();
            $fields=$edit->invoke($page,$row);$field=collect($fields)->first(fn($f)=>$f->getName()==='transfer');
            same('Leave as is ('.$expected.')',$field->getOptions()['keep'],'Single-row Transfers uses '.$name.' despite projected '.json_encode($databaseValue));
        }
    }
}finally{$probe->setValue(null,null);}
$nativeSuccessful=true;echo "Transfers keep option agrees with saved settings across projected JSON representations.\n";
