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
// Refusals belong under the relevant field and keep every typed value and saved row unchanged.
$before=[WatchProfile::count(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),$profile->fresh()->getRawOriginal()];
$hosts=implode("\n",array_map(fn($i)=>'ns'.$i.'.new.test',range(1,14)));
foreach([['nameservers',''],['nameservers','   '],['nameservers',$hosts],['nameservers',implode("\n",array_fill(0,101,'ns1.new.test'))],
    ['website_endpoints',implode("\n",array_fill(0,101,'WEB.new.test.'))],['email_endpoints_suffix',implode("\n",array_fill(0,101,'mail.new.test'))],
    ['website_endpoints_ips',implode("\n",array_fill(0,101,'192.0.2.1'))]] as [$field,$value]){
    $page=Livewire::actingAs($manager)->test(ListTemplates::class)->mountAction('save');
    $data=['name'=>'Valid new template','nameservers'=>'ns1.new.test',$field=>$value];
    $page->setActionData($data)->callMountedAction()->assertHasActionErrors([$field])->assertActionMounted('save');
    same($value,$page->get('mountedActions.0.data.'.$field),'Refused list input stays in its field');
    same($before,[WatchProfile::count(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),$profile->fresh()->getRawOriginal()],'Refused lists change no profile or audit');
}
// The save independently refuses a duplicate with a current, valid review ticket.
$writes=new Writes();$ticket=Writes::ticket($manager->id,'profile',0,0,'save');
$refused=false;try{$writes->run($manager,'profile',0,'save',$ticket,'Synthetic duplicate refusal',['name'=>$profile->name,'nameservers'=>'ns1.new.test']);}catch(DomainException){$refused=true;}
expect($refused,'The write refuses a duplicate name');
same($before,[WatchProfile::count(),\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),$profile->fresh()->getRawOriginal()],'Duplicate write changes no profile or audit');
$other=DB::transaction(fn()=>WatchProfile::create(['name'=>'Other template','nameservers'=>['ns1.other.test'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic other']));
$refused=false;try{Writes::requireUniqueName($other->name,(int)$profile->id);}catch(DomainException){$refused=true;}
expect($refused,'Editing to another template name is refused while excluding the current ID');
Livewire::actingAs($manager)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\ViewConfiguration::class,['record'=>$profile->id])
    ->mountAction('save')->setActionData(['name'=>$other->name,'nameservers'=>'ns1.new.test'])->callMountedAction()->assertHasActionErrors(['name'])->assertActionMounted('save');
// RoutingProfile admits up to 100 raw hosts and at most 13 normalized distinct nameservers.
$duplicates=implode("\n",array_fill(0,100,'NS1.new.test.'));
Livewire::actingAs($manager)->test(ListTemplates::class)->callAction('save',['name'=>'Normalized template','nameservers'=>$duplicates,'website_endpoints'=>$duplicates,'website_endpoints_ips'=>implode("\n",array_fill(0,100,'192.0.2.1'))])->assertHasNoActionErrors();
same(['ns1.new.test'],WatchProfile::where('name','Normalized template')->sole()->nameservers,'Duplicate hostnames normalize exactly as save');
$nativeSuccessful=true;echo "Staff template form passed\n";
