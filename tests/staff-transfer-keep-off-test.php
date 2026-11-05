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
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);$t=$tlds[0]->fresh();

// MariaDB JSON_EXTRACT returns JSON boolean text; SQLite returns integer 0/1.
// Replay both native database representations through the exact Eloquent record used by the form.
$page=new Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
$edit=new ReflectionMethod($page,'editFields');
$probe=new ReflectionProperty(Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::class,'probe');$probe->setValue(null,[StaffCatalogue::class=>$source]);
try{
    foreach(['false',0,false,'true',1,true] as $databaseValue){
        $row=Paymenter\Extensions\Servers\Domains\Models\OfferRow::hydrate([['id'=>$t->id,'server_id'=>$t->server_id,'tld'=>'com','longest'=>3,'transfer'=>$databaseValue]])->first();
        $fields=$edit->invoke($page,$row);$field=collect($fields)->first(fn($f)=>$f->getName()==='transfer');
        $expected=in_array($databaseValue,['false',0,false],true)?'Off':'On';
        same('Leave as is ('.$expected.')',$field->getOptions()['keep'],'Single-row Transfers matches the saved JSON boolean '.json_encode($databaseValue));
    }
}finally{$probe->setValue(null,null);}
$nativeSuccessful=true;echo "Transfers keep option agrees across JSON database representations.\n";
