<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{RuntimePorts,SiteDnsDefault};
extract(commerceFixture());tldPanel();pinStaffClock($now);$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$failures=[];$check=static function(bool $ok,string $why)use(&$failures):void{if(!$ok){$failures[]=$why;}};
App\Models\Setting::where('key',SiteDnsDefault::KEY)->delete();config(['domains.site_dns_default.nameservers'=>null]);
foreach(Account::all()as$a){
 $html=Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$a->server_id])->html();
 $check(str_contains(strip_tags($html),'New registrations have no site-default nameservers.'),'Account missing default names its registration consequence');
 if($a->registrar_key==='namesilo'){$check(str_contains(strip_tags($html),'NameSilo needs explicit nameservers before an order can be sent.'),'NameSilo names its explicit nameserver requirement');}
 foreach(AccountChecklist::setup($a,['label'=>'Active','reason'=>null])as$row){$check(trim((string)($row['help']??''))!=='','Independent help for '.$a->registrar_key.' '.$row['label']);}
}
// Routed progress must retain its own safe cause and time even without missing prices.
$worker=new \Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork($ports->writer(),$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock),static fn()=>throw new LogicException('No reads'),static fn()=>100);
$catalogue=new StaffCatalogue($ports,$clock,$worker,static fn()=>true);
$probe=new ReflectionProperty(CallerRouting::class,'probe');$probe->setValue(null,[StaffCatalogue::class=>$catalogue,\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::class=>$caps]);
$a=Account::firstOrFail();$failedAt=$now->getTimestamp()-60;
DB::table('domains_observation_queues')->where('server_id',$a->server_id)->update(['offer_work'=>json_encode(['local_failure'=>'storage_unavailable','local_failed_at'=>$failedAt]),'offer_feed_failed_at'=>$failedAt-3600]);
$rows=AccountChecklist::setup($a,['label'=>'Active','reason'=>null]);$prices=collect($rows)->firstWhere('label','TLD prices');
$check($prices['value']==='Sync failed'&&str_contains((string)$prices['cause'],'Database error'),'Routed account price row includes the mapped worker failure');
$tld=\Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$a->server_id)->firstOrFail();
$detail=Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld::class,['record'=>$tld->id]);
$check(str_contains($detail->html(),gmdate('Y-m-d\\TH:i:s',$failedAt).'+00:00'),'TLD failure time comes from the same worker result');
$a=Account::firstOrFail();$runtime=new RuntimePorts;$runtime->register($a->account_fingerprint,static fn()=>throw new RuntimeException('Synthetic broken composition'));app()->instance(RuntimePorts::class,$runtime);
$check($runtime->registered($a)&&!$runtime->accepted($a),'Fixture separates registration from composition');
$site=AccountChecklist::runtime($a);$check(str_contains($site['cause'],'Runtime registered')&&str_contains($site['help'],'API connection'),'Site setup reports registration rather than authenticated readiness');
// Staff without cost access can read the recovery instruction beside the actual row state.
DB::table('domains_sales_policies')->update(['server_id'=>null]);$reader=staffUser([Staff::VIEW]);Auth::setUser($reader);
$list=Livewire::actingAs($reader)->test(WhatYouSell::class);$record=$list->instance()->getTableRecords()->first();
expect($record!==null,'The real saved offer row exists');$status=$list->instance()->getTable()->getColumn('status')->record($record);
$check(trim((string)$status->getDescriptionBelow())!=='','Gray row renders a recovery description outside its tooltip');
// Grouped formula inputs must reach the explanation, independently of generic table help.
Auth::setUser($actor);$explanations=[['account'=>'Synthetic registrar','pricing'=>'Custom markup','formula'=>'Registrar cost plus USD 2.00','fee'=>'2.9% plus USD 0.30','fee_in_price'=>true,'tlds'=>['com','net']]];
$help=view('domains::admin.sell-explained',compact('explanations'))->render();
foreach(['Registrar cost plus USD 2.00','2.9% plus USD 0.30','com','net']as$text){$check(str_contains($help,$text),'Grouped formula includes '.$text);}
$warning=['cause'=>'custom_rule','tlds'=>['com'],'account'=>'Synthetic registrar'];$omitted=view('domains::admin.sell-note',compact('warning'))->render();
$check(str_contains($omitted,'left out')&&!str_contains($omitted,'has no price'),'Unreadable rule explains omitted prices without asserting absence');
expect($failures===[],implode('; ',$failures));$nativeSuccessful=true;echo "Native staff setup and price recovery guidance passed.\n";
