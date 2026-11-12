<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';require __DIR__.'/Support/site-dns-default.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch;
use Paymenter\Extensions\Servers\Domains\Models\{WatchProfile,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DnsTemplateUsage,SiteDnsDefault,StaffWatchWrites as Writes};
$errors=[];$check=function(string $name,Closure $run)use(&$errors):void{try{$run();echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();}};
$make=fn()=>DB::transaction(fn()=>WatchProfile::create(['name'=>'Damaged template','enabled'=>false,'nameservers'=>['ns1.example.net','ns2.example.net'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$manager->id,'reason'=>'Synthetic retained template']));
$check('Unused unreadable template can be deleted from the real page',function()use($make,$manager):void{
 $row=$make();DB::table('domains_watch_profiles')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);
 $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);$page->assertActionVisible('delete')->assertActionDisabled('save');
 $blocked=$page->instance()->getAction('enable');expect(str_contains($blocked->getTooltip()??'','read'),'Unreadable Turn on explains saved data failure');
 $page->callAction('delete')->assertNotified('DNS template deleted');same(null,WatchProfile::find($row->id),'Unreadable unused template removed');
 same(1,WatchAudit::where('subject_type','dns_profile')->where('subject_id',$row->id)->where('event','dns_profile_deleted')->count(),'Deletion retains the audit');
});
$check('Unreadable saved site default cannot be deleted',function()use($make,$manager):void{
 $row=$make();siteDnsFixture($row->id);DB::table('domains_watch_profiles')->where('id',$row->id)->update(['enabled'=>true,'nameservers'=>'["bad host"]']);
 $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);expect(str_contains($page->instance()->getAction('delete')->getTooltip()??'','new domains'),'Unreadable saved reference remains guarded');
 $page->callAction('delete')->assertNotNotified('DNS template deleted');expect(WatchProfile::find($row->id)!==null,'Unreadable saved default is retained');siteDnsFixture(null);
});
$check('New domains Missing has one sentence boundary',function()use($manager,$profile):void{
 siteDnsFixture(null);$page=Livewire::actingAs($manager)->test(ListConfiguration::class);$page->assertSee('Default nameservers are Missing. New domains have no nameservers to use. '.SiteDnsDefault::ASK);expect(!str_contains(strip_tags($page->html()),'Missing..'),'Missing default never doubles punctuation');
 siteDnsFixture($profile->id);$next=Livewire::actingAs($manager)->test(ListConfiguration::class);// the named default is in the card.
 same((int)$profile->id,$next->instance()->defaultId,'The card holds the selected default');
 $next->assertSee($profile->name)->assertSee('Default nameservers');
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
 Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertDontSee('Ended with its hosting service')->assertActionDoesNotExist('resume');
 WatchAudit::create(['subject_type'=>'watch','subject_id'=>$watch->id,'user_id'=>$watch->user_id,'after_revision'=>'2','event'=>'discovery_retired','data'=>[],'created_at'=>now()]);
 Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertSee('Ended with its hosting service');
 DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'paused']);Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertActionVisible('resume')->assertDontSee('Ended with its hosting service');
});
$check('Stale mounted Turn on and Turn off give the shared unreadable guidance',function()use($make,$manager):void{
 $guidance='Saved DNS records cannot be read. Ask your site admin to restore this template.';
 foreach(['enable'=>[false,'DNS template turned on'],'disable'=>[true,'DNS template turned off']]as$action=>[$on,$done]){
  $row=$make();DB::table('domains_watch_profiles')->where('id',$row->id)->update(['enabled'=>$on]);
  $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->mountAction($action);
  // The saved records become unreadable after the confirmation opened.
  DB::table('domains_watch_profiles')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);$revision=(int)WatchProfile::find($row->id)->revision;
  $page->callMountedAction()->assertNotified($guidance)->assertNotNotified($done);
  same($on,(bool)WatchProfile::find($row->id)->enabled,'Unreadable '.$action.' leaves the template unchanged');
  same($revision,(int)WatchProfile::find($row->id)->revision,'Unreadable '.$action.' keeps the revision');
 }
});
$check('A returning watch shows its unusable saved template and how to change it',function()use($manager,$make,$watch):void{
 $held=DB::table('domains_watch_names')->where('id',$watch->id)->first();$row=$make();
 try{
  DB::table('domains_watch_names')->where('id',$watch->id)->update(['ns_profile_id'=>$row->id,'state'=>'retired']);
  same(null,DnsTemplateUsage::refusal((int)$row->id),'A stopped watch lets staff turn the template off');
  // Discovery returns the stopped watch with its saved choice; nothing replaces it silently.
  DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>'active']);
  foreach(['off'=>[false,'["ns1.example.net","ns2.example.net"]','This template is off. Choose another in Change DNS template.'],
   'unreadable'=>[true,'["bad host"]','Saved DNS records cannot be read. Choose another in Change DNS template.']]as$case=>[$on,$names,$text]){
   DB::table('domains_watch_profiles')->where('id',$row->id)->update(['enabled'=>$on,'nameservers'=>$names]);
   $page=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$watch->id])->assertSee('Damaged template')->assertSee($text);
   $page->assertActionVisible('profile');
   same((int)$row->id,(int)$watch->fresh()->ns_profile_id,'The '.$case.' saved choice is kept');
  }
 }finally{DB::table('domains_watch_names')->where('id',$watch->id)->update(['state'=>$held->state,'ns_profile_id'=>$held->ns_profile_id]);}
});
foreach($errors as$e){echo "FAIL $e\n";}expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Native template recovery and lifecycle wording passed.\n";
