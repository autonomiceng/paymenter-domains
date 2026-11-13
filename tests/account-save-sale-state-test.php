<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Account-wide staff saves keep what the account sells on sale without waiting for the background turn, and an
// account with Orders on that sells nothing says so with its cause and fix.
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
same('Active',$staff(fn()=>RegistrarFacts::status($server,$account()))['label'],'An account that sells .com reads Active');

// Saving the same freshness changes nothing customers see.
$revision=$com->fresh()->catalogue_revision;
$writes->run($review('price_freshness'),['hours'=>48],$now);
same($revision,$com->fresh()->catalogue_revision,'An unchanged freshness save leaves the offer revision alone');
same($before,$price(),'An unchanged freshness save keeps .com for sale');

// A real freshness change republishes what the account sells at once, with no background turn.
$result=$writes->run($review('price_freshness'),['hours'=>72],$now);
same([],$result['failed']??null,'A freshness change publishes every TLD the account sells');
same($before,$price(),'A freshness change keeps .com for sale without a background turn');

// Orders off and back on puts back on sale what the account sold before.
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true],$now);
expect(!$account()->processing_enabled,'Orders paused');
$result=$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true],$now);
expect($account()->processing_enabled,'Orders resumed');
same([],$result['failed']??null,'Resuming Orders publishes every TLD the account sells');
same($before,$price(),'Resuming Orders keeps .com for sale without a background turn');
same('Active',$staff(fn()=>RegistrarFacts::status($server,$account()))['label'],'After resuming, the account reads Active again');

// With every sold offer waiting for publication, the account names the cause and the fix.
DB::table('domains_tlds')->where('id',$com->id)->increment('catalogue_revision');
commerceReject(fn()=>$price());
$status=$staff(fn()=>RegistrarFacts::status($server,$account()));
same(['Not for sale','warning'],[$status['label'],$status['tone']],'An account with Orders on that sells nothing does not read Active');
same('No TLDs are for sale. Prices not published for .com. Open Edit prices and save.',$status['reason'],'The head names the cause and the fix');
$row=AccountChecklist::notSelling($account(),$status['reason']);
same(['Sales','Not for sale','warning'],[$row['label'],$row['value'],$row['tone']],'System checks lists the account that sells nothing');
$note=(new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,'note'))->invoke(null,$status['reason'],null,$account(),null,$status['act']??false);
same(true,$note['act'],'The registrar list marks the reason as something staff must act on');

// The Edit prices preview shows the price saving publishes, not Not sold.
$rows=$staff(fn()=>(new StaffCatalogue($ports,$clock))->preview($com->fresh(),Pricing::rule($com->fresh())));
foreach($rows as $r){if(in_array($r['action'].':'.$r['term'],['register:1','transfer:1'],true)){same(null,$r['hold'],'Saving puts '.$r['action'].' '.$r['term'].' on sale, so the preview does not hold it');}}
$html=(string)$staff(fn()=>(new ReflectionMethod(SettingsActions::class,'preview'))->invoke(null,$com->id,Pricing::rule($com->fresh())));
expect(!str_contains($html,'<td>Not sold'),'The preview does not say Not sold for a row saving puts on sale');

// Saving and publishing puts it back on sale, and the account reads Active again.
$commands->publish($com->id);
same($before,$price(),'Save and publish restores .com');
same('Active',$staff(fn()=>RegistrarFacts::status($server,$account()))['label'],'The account reads Active once it sells again');
$nativeSuccessful=true;
echo "Account save sale state: ok\n";
