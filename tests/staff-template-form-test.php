<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The DNS template form names a bad line under its box, keeps what was typed, and refuses a name another template has.
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\ListConfiguration as ListTemplates;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
$count=WatchProfile::count();
$page=Livewire::actingAs($manager)->test(ListTemplates::class)->mountAction('save');
$page->setActionData(['name'=>'New template','nameservers'=>"ns1.new.test\nnot a host!!"])->callMountedAction()
    ->assertHasActionErrors(['nameservers'])->assertActionMounted('save');
same('Line 2: "not a host!!" is not a hostname.',$page->errors()->first('mountedActions.0.data.nameservers'),'The bad line is named under its box');
same("ns1.new.test\nnot a host!!",$page->get('mountedActions.0.data.nameservers'),'The template dialog keeps what was typed');
$page->setActionData(['name'=>'New template','nameservers'=>"ns1.new.test\nns2.new.test",'website_endpoints_ips'=>'192.0.2.300'])->callMountedAction()
    ->assertHasActionErrors(['website_endpoints_ips'])->assertActionMounted('save');
same($count,WatchProfile::count(),'A bad line saves nothing');
// A second template with the same name, in any case, is refused under Name.
$page=Livewire::actingAs($manager)->test(ListTemplates::class)->mountAction('save');
$page->setActionData(['name'=>' synthetic  DEFAULTS ','nameservers'=>"ns1.new.test\nns2.new.test"])->callMountedAction()
    ->assertHasActionErrors(['name'])->assertActionMounted('save');
same('A DNS template named "synthetic  DEFAULTS" already exists. Choose another name.',$page->errors()->first('mountedActions.0.data.name'),'The duplicate name is refused under Name');
same($count,WatchProfile::count(),'A duplicate name saves nothing');
$refused=false;try{Writes::requireUniqueName('Synthetic defaults');}catch(DomainException){$refused=true;}expect($refused,'The save refuses a duplicate name too');
// Names that only look different are the same name: a zero-width space, a soft hyphen or fullwidth letters.
foreach(["Synthetic\u{200B} defaults","Synthetic defaults\u{200B}","Synthe\u{00AD}tic defaults","Ｓｙｎｔｈｅｔｉｃ　ｄｅｆａｕｌｔｓ"] as $lookalike){
    $refused=false;try{Writes::requireUniqueName($lookalike);}catch(DomainException){$refused=true;}expect($refused,'A look-alike name is refused: '.json_encode($lookalike));
}
Writes::requireUniqueName('Synthetic defaults',(int)$profile->id);
expect(true,'A template keeps its own name on edit');
$nativeSuccessful=true;echo "Staff template form passed\n";
