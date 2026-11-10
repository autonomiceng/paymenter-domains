<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A staff save publishes a bounded number of sold TLDs at once and says how many go back on sale in the background.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,RegistrarFacts,SettingsActions,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,Pricing,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{Rdap,RenewalAcceptance,RuntimeComposition,RuntimePorts,StaffSettingsReview as Review,StaffSettingsWrites,TransferPrecheck,WriteAcceptance,WriteFeatures};
$fixture=offerCommerceFixture();extract($fixture);tldPanel();pinStaffClock($now);
// Orders needs shared workers and an accepted runtime; this one is offline and makes no provider calls.
$offline=static fn()=>throw new RuntimeException('No provider calls');$runtime=new RuntimePorts;
$runtime->register($binding->fingerprint,static fn()=>RuntimeComposition::make($binding,$bindings,$clock,new WriteFeatures,new WriteAcceptance($binding),
    new RenewalAcceptance($binding),new TransferPrecheck(new Rdap($offline)),new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($binding,$offline),
    new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Transfer($binding,$offline),$offline,new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,$offline)));
app()->instance(RuntimePorts::class,$runtime);
config(['cache.default'=>'database','session.driver'=>'database','queue.default'=>'database',
    'queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
if(!Illuminate\Support\Facades\Schema::hasTable('cache_locks')){Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {
    $t->string('key')->primary();$t->string('owner');$t->integer('expiration');});}
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$writer=new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true);
$commands=new StaffCommands($writer,$bindings,static fn()=>null);
$writes=new class($native,$sellers,$bindings) extends StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$writes->commands=$commands;
$review=static fn(string $action)=>new Review($actor->id,$binding->serverId,null,$action,Review::ticket($actor->id,$binding->serverId,null,$action,Review::stamp($binding->serverId,null)),'Synthetic account save');
$server=App\Models\Server::findOrFail($binding->serverId);$account=static fn()=>Account::where('server_id',$binding->serverId)->sole();
$com=Tld::where('server_id',$binding->serverId)->where('tld','com')->sole();
$price=static fn()=>$storefront->offer('com','register',1)['price'];$before=$price();
$probe=new ReflectionProperty(CallerRouting::class,'probe');
$staff=static function(Closure $read)use($probe,$ports,$clock){$probe->setValue(null,[StaffCatalogue::class=>new StaffCatalogue($ports,$clock)]);try{return $read();}finally{$probe->setValue(null,null);}};
$com=Tld::where('server_id',$binding->serverId)->where('tld','com')->sole();
DB::table('domains_tlds')->where('id',$com->id)->increment('catalogue_revision');
commerceReject(fn()=>$price());
// Past the limit the TLD is left for the background turn, and the save says so.
same(['failed'=>[],'left'=>1],$commands->republishSold($binding->serverId,0),'A TLD past the limit is left for the background turn');
commerceReject(fn()=>$price());
same('1 more TLD goes back on sale in the background, up to 25 a minute.',SettingsActions::republished(['failed'=>[],'pending'=>false,'left'=>1]),'The save says how many go back on sale later');
same(null,SettingsActions::republished(['failed'=>[],'pending'=>false,'left'=>0]),'Nothing left says nothing');
// Within the limit it is published at once.
same(['failed'=>[],'left'=>0],$commands->republishSold($binding->serverId),'Within the limit the TLD is published at once');
same($before,$price(),'.com is back on sale');
// One failed publication names its TLD and does not stop a healthy sibling.
$sibling=$com->fresh()->replicate();$sibling->tld='net';$sibling->catalogue_revision++;$sibling->save();
foreach(Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$binding->serverId)->where('tld','com')->get() as $price){$copy=$price->replicate();$copy->tld='net';$copy->save();}
$policy=Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->sole()->replicate();$policy->tld='net';
Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::withPolicyWrite(static fn()=>$policy->save());
DB::table('domains_tlds')->where('id',$com->id)->increment('catalogue_revision');
$attempted=[];
$failing=new StaffCommands(new Writer(new Projection($native),$clock,static function($command)use(&$attempted):void{
    $attempted[]=$command->tld();if($command->tld()==='com'){throw new DomainException('Synthetic publication refusal');}
},static fn()=>true),$bindings,static fn()=>null);
same(['failed'=>['com'],'left'=>0],$failing->republishSold($binding->serverId),'A failure is recorded without abandoning siblings');
expect(($attempted[0]??null)==='com'&&in_array('net',array_slice($attempted,1),true),'The sibling is published after the first TLD fails');
$published=Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($sibling->fresh()->catalogue_published_revision);
same($sibling->fresh()->catalogue_revision,$published['offer'],'The healthy sibling publication is current');
same('Prices for .com are not published, so it is not for sale. Open Edit prices on the TLD and save.',SettingsActions::republished(['failed'=>['com'],'pending'=>false,'left'=>0]),'The failure names the TLD and repair');
same('Prices are not published yet, so TLDs are not for sale until the next background run. Open Edit prices on a TLD and save to publish it now.',SettingsActions::republished(['failed'=>[],'pending'=>true]),'Pending publication explains the wait and immediate repair');
// A saved setting without the installed catalogue must say publication is still pending.
$unavailable=new class($native,$sellers,$bindings) extends StaffSettingsWrites {protected function catalogue():?StaffCommands{return null;}};
$result=$unavailable->run($review('price_freshness'),['hours'=>72],$now);
same(['failed'=>[],'pending'=>true],$result,'Missing catalogue leaves publication pending');
expect(SettingsActions::republished($result)!==null,'The save explains that prices are not published yet');
$nativeSuccessful=true;
echo "Account republish limit: ok\n";
