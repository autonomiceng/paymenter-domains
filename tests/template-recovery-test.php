<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-templates.php';require __DIR__.'/Support/site-dns-default.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration,ViewConfiguration};
use Paymenter\Extensions\Servers\Domains\Models\{DnsTemplate,DomainAudit};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault, DnsTemplateWrites as Writes};
$errors=[];$check=function(string $name,Closure $run)use(&$errors):void{try{$run();echo "PASS $name\n";}catch(Throwable $e){$errors[]=$name.': '.$e->getMessage();}};
$make=fn()=>DB::transaction(fn()=>DnsTemplate::create(['name'=>'Damaged template','enabled'=>false,'nameservers'=>['ns1.example.net','ns2.example.net'],'edited_by'=>$manager->id,'reason'=>'Synthetic retained template']));
$check('Unused unreadable template can be deleted from the real page',function()use($make,$manager):void{
 $row=$make();DB::table('domains_dns_templates')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);
 $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);$page->assertActionVisible('delete')->assertActionDisabled('save');
 $blocked=$page->instance()->getAction('enable');expect(str_contains($blocked->getTooltip()??'','read'),'Unreadable Turn on explains saved data failure');
 $page->callAction('delete')->assertNotified('DNS template deleted');same(null,DnsTemplate::find($row->id),'Unreadable unused template removed');
 same(1,DomainAudit::where('subject_type','dns_profile')->where('subject_id',$row->id)->where('event','dns_profile_deleted')->count(),'Deletion retains the audit');
});
$check('Unreadable saved site default cannot be deleted',function()use($make,$manager):void{
 $row=$make();siteDnsFixture($row->id);DB::table('domains_dns_templates')->where('id',$row->id)->update(['enabled'=>true,'nameservers'=>'["bad host"]']);
 $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id]);expect(str_contains($page->instance()->getAction('delete')->getTooltip()??'','new domains'),'Unreadable saved reference remains guarded');
 $page->callAction('delete')->assertNotNotified('DNS template deleted');expect(DnsTemplate::find($row->id)!==null,'Unreadable saved default is retained');siteDnsFixture(null);
});
$check('New registrations reflect the current site choice',function()use($manager,$profile):void{
 siteDnsFixture(null);
 $page=Livewire::actingAs($manager)->test(ListConfiguration::class);
 $page->assertSee(SiteDnsDefault::ASK);
 siteDnsFixture($profile->id);
 Livewire::actingAs($manager)->test(ListConfiguration::class)->assertSee($profile->name);
});
$check('Stale mounted Turn on and Turn off give the shared unreadable guidance',function()use($make,$manager):void{
 $guidance='Saved DNS records cannot be read. Ask your site admin to restore this template.';
 foreach(['enable'=>[false,'DNS template turned on'],'disable'=>[true,'DNS template turned off']]as$action=>[$on,$done]){
  $row=$make();DB::table('domains_dns_templates')->where('id',$row->id)->update(['enabled'=>$on]);
  $page=Livewire::actingAs($manager)->test(ViewConfiguration::class,['record'=>$row->id])->mountAction($action);
  // The saved records become unreadable after the confirmation opened.
  DB::table('domains_dns_templates')->where('id',$row->id)->update(['nameservers'=>'["bad host"]']);$revision=(int)DnsTemplate::find($row->id)->revision;
  $page->callMountedAction()->assertNotified($guidance)->assertNotNotified($done);
  same($on,(bool)DnsTemplate::find($row->id)->enabled,'Unreadable '.$action.' leaves the template unchanged');
  same($revision,(int)DnsTemplate::find($row->id)->revision,'Unreadable '.$action.' keeps the revision');
 }
});
foreach($errors as$e){echo "FAIL $e\n";}expect($errors===[],implode('; ',$errors));$nativeSuccessful=true;echo "Native template recovery and lifecycle wording passed.\n";
