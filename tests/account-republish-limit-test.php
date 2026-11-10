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
same('1 more TLD goes back on sale in the background, about 25 a minute.',SettingsActions::republished(['failed'=>[],'pending'=>false,'left'=>1]),'The save says how many go back on sale later');
same(null,SettingsActions::republished(['failed'=>[],'pending'=>false,'left'=>0]),'Nothing left says nothing');
// Within the limit it is published at once.
same(['failed'=>[],'left'=>0],$commands->republishSold($binding->serverId),'Within the limit the TLD is published at once');
same($before,$price(),'.com is back on sale');
$nativeSuccessful=true;
echo "Account republish limit: ok\n";
