<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-templates.php';
require __DIR__.'/Support/site-dns-default.php';
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{EditConfiguration,ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicConfig,WatchName,WatchProductMap,DnsTemplate,DomainAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DnsTemplateUsage,SiteDnsDefault,DnsTemplateWrites as Writes};
$make=fn(string $name)=>DB::transaction(fn()=>DnsTemplate::create(['name'=>$name,'enabled'=>true,
    'nameservers'=>['ns1.spare.test','ns2.spare.test'],
    'edited_by'=>$manager->id,'reason'=>'Synthetic template setup']));
$write=function(DnsTemplate $row,string $action)use($manager):void {
    (new Writes())->run($manager,(int)$row->id,$action,
        Writes::ticket((int)$manager->id,(int)$row->id,(int)$row->revision,$action),'Synthetic template change');
};
$errors=[];
/** The line a template's name cell carries under its name: the mark that says it supplies new domains, or null. */
function tplMark($column,$record): ?string
{
    $text=trim(html_entity_decode(strip_tags((string)$column->record($record)->formatState($column->getState())),ENT_QUOTES));
    $rest=str_starts_with($text,(string)$record->name)?trim(substr($text,strlen((string)$record->name))):$text;
    return $rest===''?null:$rest;
}
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
    $before=DomainAudit::count();
    try{$write($stale,'delete');throw new RuntimeException('Stale deletion was accepted');}
    catch(DomainException $e){expect(str_contains($e->getMessage(),'changed'),'Stale deletion says to reload');}
    same($before,DomainAudit::count(),'Stale deletion has no audit');
    $write($row->fresh(),'enable');
    Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->callAction('delete')->assertNotified('DNS template deleted');
    same(null,DnsTemplate::find($row->id),'Unused On template deleted');
    same(1,DomainAudit::where('subject_id',$row->id)->where('event','dns_profile_deleted')->count(),'Deletion retains one audit');
});
$check('Dependencies refuse turn off and delete with a reason',function()use($profile,$make,$write,$manager):void{
    $row=$make('New domains template');siteDnsFixture($row->id);
    foreach(['disable','delete']as$action){
        $before=DomainAudit::count();$error=null;
        try{$write($row,$action);}catch(DomainException $e){$error=$e->getMessage();}
        expect($error!==null && str_contains($error,'new domains'),'Default refuses '.$action.' with the reason');
        same($before,DomainAudit::count(),'Default refusal rolls back audit');
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
    expect($switch instanceof DOMElement && $switch->getAttribute('aria-disabled') === 'true' && !$switch->hasAttribute('disabled') && !$switch->hasAttribute('wire:click'),'The blocked monitoring switch stays focusable and cannot send a write');
    siteDnsFixture(null);
});
$check('One New domains use choice is shown',function()use($profile,$make,$manager):void{
    $other=$make('Other enabled template');siteDnsFixture($profile->id);
    $list=Livewire::actingAs($manager)->test(ListConfiguration::class)->assertSee($profile->name);
    $column=$list->instance()->getTable()->getColumn('name');
    same('Used for new domains',tplMark($column,$profile),'Chosen template marked');
    same(null,tplMark($column,$other),'Other On template is not marked');
    $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$other->id])->assertSee($profile->name);
});
$check('Add and edit show done messages without losing page state',function()use($make,$manager):void{
    $list=Livewire::actingAs($manager)->test(ListConfiguration::class);
    $list->callAction('save',['name'=>'Added DNS template','nameservers'=>'ns1.added.test'."\n".'ns2.added.test'])
        ->assertNotified('DNS template added')->assertNoRedirect();
    $row=DnsTemplate::where('name','Added DNS template')->sole();
    $list->assertCanSeeTableRecords([$row]);
    Livewire::actingAs($manager)->test(EditConfiguration::class,['record'=>$row->id])
        ->set('data.name','Edited DNS template')->set('data.nameservers',"ns1.edited.test\nns2.edited.test")->call('save')
        ->assertRedirect(\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::getUrl('view',['record'=>$row]));
    same(['ns1.edited.test','ns2.edited.test'],$row->fresh()->nameservers,'The edited template keeps the saved nameservers');
});
$check('Read-only staff cannot change templates',function()use($make):void{
    $row=$make('Permission template');$viewer=staffUser([Writes::VIEW]);$error=null;
    try{(new Writes())->run($viewer,(int)$row->id,'delete',Writes::ticket((int)$viewer->id,(int)$row->id,(int)$row->revision,'delete'),'Synthetic permission refusal');}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$error=$e->getStatusCode();}
    same(403,$error,'Deletion requires manage permission');expect($row->fresh()!==null,'Refused deletion preserves the template');
});
$check('Default template saves require both default permissions',function()use($make):void{
    $default=$make('Protected default');$ordinary=$make('Ordinary edit');siteDnsFixture($default->id);
    $rights=['admin.domains.view',Writes::VIEW,Writes::MANAGE];
    $full=staffUser([...$rights,'admin.domains.manage-policy',SiteDnsDefault::MANAGE]);
    $input=fn(DnsTemplate $row)=>['name'=>$row->name,'nameservers'=>"ns1.changed.test\nns2.changed.test"];
    // Each default right is required independently, even with all template editing rights.
    foreach([[],['admin.domains.manage-policy'],[SiteDnsDefault::MANAGE]]as$extra){
        $editor=staffUser([...$rights,...$extra]);$before=[$default->fresh()->getRawOriginal(),DomainAudit::count(),DB::table('domains_dns_template_claims')->count()];$status=null;
        try{(new Writes())->run($editor,(int)$default->id,'save',Writes::ticket((int)$editor->id,(int)$default->id,(int)$default->revision,'save'),'DNS template saved',$input($default));}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$status=$e->getStatusCode();}
        same(403,$status,'Default template save needs both default permissions');
        same($before,[$default->fresh()->getRawOriginal(),DomainAudit::count(),DB::table('domains_dns_template_claims')->count()],'Permission refusal preserves the template, audit and claim');
        Livewire::actingAs($editor)->test(ViewConfiguration::class,['record'=>$default->id])->assertActionHidden('save');
    }
    $editor=staffUser($rights);
    $page=Livewire::actingAs($editor)->test(ViewConfiguration::class,['record'=>$ordinary->id])->assertActionVisible('save');
    Livewire::actingAs($editor)->test(EditConfiguration::class,['record'=>$ordinary->id])->set('data.nameservers',$input($ordinary)['nameservers'])->call('save')->assertHasNoErrors();
    same(['ns1.changed.test','ns2.changed.test'],$ordinary->fresh()->nameservers,'Template-only staff can edit an ordinary template');
    $mounted=Livewire::actingAs($editor)->test(EditConfiguration::class,['record'=>$ordinary->id])->set('data.nameservers',$input($ordinary)['nameservers']);
    $ticket=Writes::ticket((int)$editor->id,(int)$ordinary->id,(int)$ordinary->fresh()->revision,'save');
    SiteDnsDefault::save($full,$ordinary->id);$before=[$ordinary->fresh()->getRawOriginal(),DomainAudit::count(),DB::table('domains_dns_template_claims')->count()];
    $mounted->call('save')->assertForbidden();
    $status=null;try{(new Writes())->run($editor,(int)$ordinary->id,'save',$ticket,'DNS template saved',$input($ordinary));}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$status=$e->getStatusCode();}
    same(403,$status,'A ticket issued before the template became default is refused at the writer');
    same($before,[$ordinary->fresh()->getRawOriginal(),DomainAudit::count(),DB::table('domains_dns_template_claims')->count()],'A template selected after the editor opened cannot be saved by template-only staff');
    Livewire::actingAs($full)->test(EditConfiguration::class,['record'=>$ordinary->id])
        ->set('data.nameservers',"ns1.authorized.test\nns2.authorized.test")->call('save')->assertHasNoErrors();
    same(['ns1.authorized.test','ns2.authorized.test'],$ordinary->fresh()->nameservers,'Fully authorized staff can edit the default template');
    siteDnsFixture(null);
});
$check('Default label lookup is constant per table render',function()use($profile,$make,$manager):void{
 $count=0;DB::listen(static function($q)use(&$count):void{if(str_contains($q->sql,'"domains_business_settings"')){$count++;}});
 foreach([1,25,100]as$size){$page=Livewire::actingAs($manager)->test(ListConfiguration::class);$column=$page->instance()->getTable()->getColumn('name');$count=0;
  for($i=0;$i<$size;$i++){tplMark($column,$profile);}expect($count<=1,'Default lookup stays constant for '.$size.' rows');}
 siteDnsFixture(null);$page=Livewire::actingAs($manager)->test(ListConfiguration::class);$column=$page->instance()->getTable()->getColumn('name');$count=0;
 for($i=0;$i<100;$i++){same(null,tplMark($column,$profile),'Missing default has no row marker');}expect($count<=1,'Missing default is cached within this render');
 siteDnsFixture($profile->id);$page=Livewire::actingAs($manager)->test(ListConfiguration::class);same('Used for new domains',tplMark($page->instance()->getTable()->getColumn('name'),$profile),'Next request sees changed default');
});
$check('Unusable defaults stay protected and retired native rows cannot replace them',function()use($make,$write,$manager):void{
    $row=$make('Retained default');siteDnsFixture($row->id);$stored=DB::table('domains_dns_templates')->where('id',$row->id)->value('nameservers');
    $marker=fn()=>tplMark(Livewire::actingAs($manager)->test(ListConfiguration::class)->instance()->getTable()->getColumn('name'),$row);
    $retained='This template is saved for new domains but cannot be used. Choose another template for new domains first.';
    try{
        DB::table('domains_dns_templates')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);
        same($retained,SiteDnsDefault::usesRefusal((int)$row->id),'An unreadable saved default is not called the live default');
        same('Saved for new domains but cannot be used',$marker(),'The list marks the unusable saved default it protects');
        DB::table('domains_dns_templates')->where('id',$row->id)->update(['nameservers'=>$stored]);
        same('Used for new domains',$marker(),'A healthy default keeps its marker');
        // A retired native duplicate cannot change the owned reference.
        $duplicate=DB::table('settings')->insertGetId(['key'=>SiteDnsDefault::KEY,'value'=>'opaque','type'=>'string','encrypted'=>true,'settingable_type'=>null,'settingable_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
        foreach(['disable','delete']as$action){
            $error=null;try{$write($row->fresh(),$action);}catch(DomainException $e){$error=$e->getMessage();}
            expect($error!==null && $error!==SiteDnsDefault::RETAINED,'The owned usable default still refuses '.$action);
            expect(DnsTemplate::find($row->id)?->enabled===true,'The duplicate reference keeps the template after '.$action);
        }
        same(null,SiteDnsDefault::refusal(),'A retired native row has no settings authority');
        same((int)$row->id,SiteDnsDefault::profile()?->id,'The owned reference still selects the template for new domains');
        expect(SiteDnsDefault::uses((int)$row->id),'The owned reference still protects the template');
        DB::table('settings')->where('id',$duplicate)->delete();
    }finally{DB::table('domains_dns_templates')->where('id',$row->id)->update(['nameservers'=>$stored]);siteDnsFixture(null);}
});
foreach($errors as$error){echo "FAIL $error\n";}
expect($errors===[],'DNS template control regressions: '.implode('; ',$errors));
$nativeSuccessful=true;echo "DNS template controls passed\n";
