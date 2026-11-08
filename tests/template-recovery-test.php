<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';require __DIR__.'/Support/site-dns-default.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes};
$errors=[];$check=function(string $name,Closure $run)use(&$errors):void{try{$run();echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();}};
$make=fn()=>DB::transaction(fn()=>WatchProfile::create(['name'=>'Damaged template','enabled'=>false,'nameservers'=>['ns1.example.net','ns2.example.net'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic retained template']));
$check('Unused unreadable template can be deleted from the real page',function()use($make,$manager):void{
 $row=$make();DB::table('domains_watch_profiles')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);
 $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);$page->assertActionVisible('delete')->assertActionDisabled('save');
 $blocked=$page->instance()->getAction('enable');expect(str_contains($blocked->getTooltip()??'','read'),'Unreadable Turn on explains saved data failure');
 $page->callAction('delete')->assertNotified('DNS template deleted');same(null,WatchProfile::find($row->id),'Unreadable unused template removed');
 same(1,WatchAudit::where('subject_type','dns_profile')->where('subject_id',$row->id)->where('event','dns_profile_deleted')->count(),'Deletion retains the audit');
});
$check('New domains Missing has one sentence boundary',function()use($manager,$profile):void{
 siteDnsFixture(null);$page=Livewire::actingAs($manager)->test(ListConfiguration::class);expect(!str_contains($page->instance()->getSubheading(),'..'),'Missing default never doubles punctuation');
 siteDnsFixture($profile->id);$next=Livewire::actingAs($manager)->test(ListConfiguration::class);expect(str_contains($next->instance()->getSubheading(),$profile->name),'Named default survives rendering');
});
$check('Linked references have truthful retained-history guidance',function()use($manager,$make,$watch):void{
 $row=$make();DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$row->id,'state'=>'linked']);
 $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);$text=$page->instance()->getAction('delete')->getTooltip()??'';
 expect(str_contains($text,'retained')&&!str_contains($text,'Choose another template for them'),'No impossible reassignment instruction for a retained linked reference');
 $page->callAction('delete')->assertNotNotified('DNS template deleted');expect(WatchProfile::find($row->id)!==null,'Linked history stays referenced');
 DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'active']);
});
$check('Stopped causes are based on retained audit evidence',function()use($manager,$watch):void{
 DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'retired']);
 $page=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id]);$page->assertDontSee('Ended with its hosting service');
 WatchAudit::create(['subject_type'=>'watch','subject_id'=>$watch->id,'user_id'=>$watch->user_id,'actor_id'=>$manager->id,'after_revision'=>'1','event'=>'staff_retire','reason'=>'Legacy staff stop','data'=>[],'created_at'=>now()]);
 Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertDontSee('Ended with its hosting service')->assertActionHidden('resume');
 WatchAudit::create(['subject_type'=>'watch','subject_id'=>$watch->id,'user_id'=>$watch->user_id,'after_revision'=>'2','event'=>'discovery_retired','data'=>[],'created_at'=>now()]);
 Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertSee('Ended with its hosting service');
 DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertActionVisible('resume')->assertDontSee('Ended with its hosting service');
});
foreach($errors as$e){echo "FAIL $e\n";}expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Native template recovery and lifecycle wording passed.\n";
