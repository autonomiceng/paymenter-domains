<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Staff settings say what they did: Orders off keeps the TLDs chosen to sell, a partial price publish says so in its title,
// and a read-only connection check asks without the warning triangle.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$account=static fn()=>Account::where('server_id',$binding->serverId)->sole();
$probe=new ReflectionProperty(CallerRouting::class,'probe');
$sells=static function()use($probe,$ports,$clock,$account):array{$probe->setValue(null,[StaffCatalogue::class=>new StaffCatalogue($ports,$clock)]);
    try{$row=array_column(AccountChecklist::setup($account(),['label'=>'Active','reason'=>null]),null,'label')['What you sell'];return [$row['value'],$row['cause']];}finally{$probe->setValue(null,null);}};
same(['.com','1 TLD for sale'],$sells(),'Fixture: .com is for sale');
// Orders off pauses sales. The chosen TLD stays on the row, paused, instead of reading as never chosen.
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['processing_enabled'=>false]);
same(['.com','1 TLD is chosen. Sales are paused while Orders is off.'],$sells(),'Orders off shows the chosen TLD as paused');
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['processing_enabled'=>true]);
same(['.com','1 TLD for sale'],$sells(),'Orders on shows it for sale again');
// A Default pricing save that could not publish every TLD says so in its title as well as its body.
same('Saved. Some prices not published',SettingsActions::savedTitle('default_pricing',['published'=>3,'failed'=>['Demo'=>['bible']],'skipped'=>[]],$account(),true),'A partial publish is named in the title');
same('Saved',SettingsActions::savedTitle('default_pricing',['published'=>3,'failed'=>[],'skipped'=>[]],$account(),true),'A full publish just says Saved');
// Test connection only reads the balance, so its confirm has a plain icon. Orders keeps the warning.
$stamp=Review::stamp($binding->serverId,null);
same(['heroicon-o-signal','gray'],[SettingsActions::setup($binding->serverId,$stamp,'probe')->getModalIcon(),SettingsActions::setup($binding->serverId,$stamp,'probe')->getModalIconColor()],'The connection check has a plain icon');
expect(SettingsActions::setup($binding->serverId,$stamp,'processing')->getModalIcon()!=='heroicon-o-signal','Orders keeps the warning icon');
$nativeSuccessful=true;
echo "Staff settings feedback: ok\n";
