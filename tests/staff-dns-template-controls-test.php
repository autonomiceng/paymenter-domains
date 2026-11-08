<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
require __DIR__.'/Support/site-dns-default.php';
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes};
$make=fn(string $name)=>DB::transaction(fn()=>WatchProfile::create(['name'=>$name,'enabled'=>true,
    'nameservers'=>['ns1.spare.test','ns2.spare.test'],'website_endpoints'=>[],'email_endpoints'=>[],
    'edited_by'=>$manager->id,'reason'=>'Synthetic template setup']));
$write=function(WatchProfile $row,string $action)use($manager):void {
    (new Writes())->run($manager,'profile',(int)$row->id,$action,
        Writes::ticket((int)$manager->id,'profile',(int)$row->id,(int)$row->revision,$action),'Synthetic template change');
};
$errors=[];
$check=function(string $name,Closure $run)use(&$errors):void{try{$run();echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();}};
$check('Turn off and delete an unused On template',function()use($make,$write,$manager):void{
    $row=$make('Unused template');
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);
    $page->assertActionVisible('disable');
    expect($page->instance()->getAction('disable')->isConfirmationRequired(),'Turn off needs confirmation');
    expect($page->instance()->getAction('delete')->isConfirmationRequired(),'Delete needs confirmation');
    $page->callAction('disable')->assertNotified('DNS template turned off')->assertNoRedirect();
    same(false,$row->fresh()->enabled,'Turn off persists');
    $page->assertActionVisible('enable')->assertActionHidden('disable');
    $page->callAction('enable')->assertNotified('DNS template turned on')->assertNoRedirect();
    same(true,$row->fresh()->enabled,'Turn on persists in the same page');
    expect(str_contains($page->html(),'aria-checked="true"'),'The rendered switch updates without reload');
    $stale=$row->fresh();$write($stale,'disable');
    $before=WatchAudit::count();
    try{$write($stale,'delete');throw new RuntimeException('Stale deletion was accepted');}
    catch(DomainException $e){expect(str_contains($e->getMessage(),'changed'),'Stale deletion says to reload');}
    same($before,WatchAudit::count(),'Stale deletion has no audit');
    $write($row->fresh(),'enable');
    Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->callAction('delete')->assertNotified('DNS template deleted');
    same(null,WatchProfile::find($row->id),'Unused On template deleted');
    same(1,WatchAudit::where('subject_id',$row->id)->where('event','dns_profile_deleted')->count(),'Deletion retains one audit');
});
$check('Dependencies refuse turn off and delete with a reason',function()use($profile,$watch,$map,$make,$write,$manager):void{
    $row=$make('New domains template');siteDnsFixture($row->id);
    foreach(['disable','delete']as$action){
        $before=WatchAudit::count();$error=null;
        try{$write($row,$action);}catch(DomainException $e){$error=$e->getMessage();}
        expect($error!==null && str_contains($error,'new domains'),'Default refuses '.$action.' with the reason');
        same($before,WatchAudit::count(),'Default refusal rolls back audit');
        same(true,$row->fresh()->enabled,'Default stays on');
    }
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);
    foreach(['disable','delete']as$action){$page->assertActionVisible($action);same('true',$page->instance()->getAction($action)->getExtraAttributes()['aria-disabled']??null,'Blocked control stays visible');}
    $reason='Choose another template for new domains first.';
    $page->assertSee($reason);
    $dom=new DOMDocument();@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
    $buttons=$xpath->query('//button[normalize-space(.)="Delete"]');
    same(1,$buttons->length,'One blocked Delete stays visible');$button=$buttons->item(0);
    expect($button instanceof DOMElement,'Blocked Delete is a rendered button');
    expect(str_contains(' '.$button->getAttribute('class').' ',' domains-blocked '),'Blocked Delete is visibly dimmed');
    same('true',$button->getAttribute('aria-disabled'),'Blocked Delete says it cannot run');
    expect(str_contains($button->getAttribute('x-tooltip'),$reason),'Blocked Delete explains the reason in its tooltip');
    expect(str_contains($button->getAttribute('aria-label'),$reason),'Blocked Delete gives screen readers the reason');
    expect(!$button->hasAttribute('disabled')&&!$button->hasAttribute('wire:click'),'Blocked Delete stays focusable without a silent write handler');
    same($reason,$page->instance()->getAction('delete')->getTooltip(),'Delete uses the shared blocked-control tooltip');
    $switch=$xpath->query('//button[@role="switch"]')->item(0);
    expect($switch instanceof DOMElement && str_contains(' '.$switch->getAttribute('class').' ',' domains-blocked '),'The blocked monitoring switch is also dimmed');
    siteDnsFixture(null);
    // Existing watch, managed-domain and product assignments each block independently.
    $cases=[['domains_watch_names','ns_profile_id','id',$watch->id],['domains_managed_public_config','profile_id','domain_id',900001],['domains_watch_product_maps','expected_ns_profile_id','id',$map->id]];
    foreach($cases as[$table,$field,$key,$id]){
        if($table==='domains_managed_public_config'){DB::table($table)->insert(['domain_id'=>$id,'user_id'=>$manager->id,'profile_id'=>$row->id,'notice_preferences'=>'{}']);}
        else{DB::table($table)->where($key,$id)->update([$field=>$row->id]);}
        foreach(['disable','delete']as$action){
            $error=null;try{$write($row,$action);}catch(DomainException $e){$error=$e->getMessage();}
            expect($error!==null && str_contains($error,'assigned'),'Assignment refuses '.$action.' for '.$table);
            same(true,$row->fresh()->enabled,'Assigned template stays on');
            $error=null;try{DB::transaction(fn()=>$row->fresh()->update(['enabled'=>false,'revision'=>$row->revision+1]));}catch(DomainException $e){$error=$e->getMessage();}
            expect($error!==null && str_contains($error,'assigned'),'Model save keeps the same dependency guard');
        }
        if($table==='domains_managed_public_config'){DB::table($table)->where($key,$id)->delete();}
        else{DB::table($table)->where($key,$id)->update([$field=>null]);}
    }
    // The confirmation was opened before another domain selected the template.
    $mounted=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->mountAction('delete');
    DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$row->id]);
    $mounted->callMountedAction()->assertNotified('This template is assigned to domains. Choose another template for them first.')->assertNotNotified('DNS template deleted');
    expect(WatchProfile::find($row->id)!==null,'A new dependency prevents a mounted deletion');
    DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$profile->id]);
});
$check('One New domains use choice is shown',function()use($profile,$make,$manager):void{
    $other=$make('Other enabled template');siteDnsFixture($profile->id);
    $list=Livewire::actingAs($manager)->test(ListConfiguration::class)->assertSee('New domains use')->assertSee($profile->name);
    $column=$list->instance()->getTable()->getColumn('name');
    same('Used for new domains',$column->record($profile)->getDescriptionBelow(),'Chosen template marked');
    same(null,$column->record($other)->getDescriptionBelow(),'Other On template is not marked');
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$other->id])->assertSee('New domains use')->assertSee($profile->name)->assertDontSee('Site DNS default');
});
$check('Add and edit show done messages without losing page state',function()use($make,$manager):void{
    $list=Livewire::actingAs($manager)->test(ListConfiguration::class);
    $list->callAction('save',['name'=>'Added DNS template','nameservers'=>'ns1.added.test'."\n".'ns2.added.test'])
        ->assertNotified('DNS template added')->assertNoRedirect();
    $row=WatchProfile::where('name','Added DNS template')->sole();
    $list->assertCanSeeTableRecords([$row]);
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);
    $page->callAction('save',['name'=>'Edited DNS template','nameservers'=>'ns1.edited.test'."\n".'ns2.edited.test'])
        ->assertNotified('DNS template saved')->assertNoRedirect();
    same('Edited DNS template',$page->instance()->getTitle(),'Title updates after edit');
    $page->assertSee('ns1.edited.test');
});
$check('Read-only staff cannot change templates',function()use($make):void{
    $row=$make('Permission template');$viewer=staffUser([Writes::VIEW]);$error=null;
    try{(new Writes())->run($viewer,'profile',(int)$row->id,'delete',Writes::ticket((int)$viewer->id,'profile',(int)$row->id,(int)$row->revision,'delete'),'Synthetic permission refusal');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$error=$e->getStatusCode();}
    same(403,$error,'Deletion requires manage permission');expect($row->fresh()!==null,'Refused deletion preserves the template');
});
foreach($errors as$error){echo "FAIL $error\n";}
expect($errors===[],'DNS template control regressions: '.implode('; ',$errors));
$nativeSuccessful=true;echo "DNS template controls passed\n";
