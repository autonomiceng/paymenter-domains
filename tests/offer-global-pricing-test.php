<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture proves global review, two independent projections and retry after one projection fails.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Price as NativePrice,Product};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Pricing,Projection,StaffCommands,UpgradeGate,Writer};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PricingRules,StaffSettingsReview as Review,StaffSettingsWrites};
require __DIR__.'/Support/offer-commerce.php';
$fixture=offerCommerceFixture();extract($fixture);[$second]=offerCommerceSecond($fixture);tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$writer=new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true);
$commands=new StaffCommands($writer,$bindings,static fn()=>throw new RuntimeException('Global save does not enqueue old account defaults'));
$writes=new class($native,$sellers,$bindings) extends StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$writes->commands=$commands;
$review=static fn(string $action,int $server=Review::ALL_ACCOUNTS,?int $tld=null)=>new Review($actor->id,$server,$tld,$action,Review::ticket($actor->id,$server,$tld,$action,StaffCommands::stamp($server,$tld)),'Synthetic global pricing review');
$data=static fn(string $price)=>['mode'=>'fixed','fixed_register'=>$price,'fixed_renew'=>$price,'fixed_transfer'=>$price,'fee_percent'=>'3.00','fee_fixed'=>'0.30'];
$save=static fn(string $price)=>$writes->run($review('default_pricing'),$data($price),$now);
$com=Tld::where('server_id',$binding->serverId)->where('tld','com')->sole();$net=Tld::where('server_id',$second->serverId)->where('tld','net')->sole();
// Retained account snapshots must never compete with the business default or leak back into new writes.
foreach([$binding,$second] as $b){$a=Account::where('server_id',$b->serverId)->sole();$d=OfferState::decode($a->catalogue_defaults);DB::table('domains_accounts')->where('id',$a->id)->update(['catalogue_defaults'=>OfferState::json([...$d,'pricing_rule'=>['mode'=>'fixed','fixed'=>['register'=>'99.00','renew'=>'99.00','transfer'=>'99.00']],'card_fee'=>['percent'=>'9.00','fixed'=>'9.00']])]);}
$ids=Product::orderBy('id')->pluck('id')->all();$calls=$reads->calls;
$result=$save('20.00');same([],$result['failed'],'Global save publishes current costs on every account');expect($result['published']>=2,'Both independent accounts publish');
foreach(['com','net'] as $name){same('$20.00',$storefront->offer($name,'register',1)['price'],'Every account uses the global form rule');}
same('20.00',(new PricingRules)->defaultRule()['fixed']['register'],'Only shared rule store changes');same(['percent'=>'3.00','fixed'=>'0.30'],CardFee::read(),'Only shared card fee is used');
// The F51 migration preserves saved legacy TLD overrides, even after their earlier conversion.
DB::table('domains_tlds')->where('id',$com->id)->update(['use_default_rule'=>false,'pricing_mode'=>'fixed','fixed_register'=>'12.00','fixed_renew'=>'13.00','fixed_transfer'=>'14.00']);
$commands->publish($com->id);$own=$com->fresh()->getRawOriginal();$save('30.00');
same('$12.00',$storefront->offer('com','register',1)['price'],'Saved TLD own rule survives global save');same('$30.00',$storefront->offer('net','register',1)['price'],'Other account follows new global default');
same($own,$com->fresh()->getRawOriginal(),'Global save leaves own-rule intent, retail and revisions untouched');
$commands->run($review('remove_override',$binding->serverId,$com->id),[]);
// A later account failure does not undo either the saved global rule or the first account's publication.
$fail=true;$plans=Product::where('server_id',$second->serverId)->get()->flatMap(fn($p)=>$p->plans()->pluck('id'))->all();
NativePrice::saving(static function($price)use(&$fail,$plans):void{if($fail&&in_array($price->plan_id,$plans,true)){throw new RuntimeException('Synthetic second-account projection failure');}});
$result=$save('40.00');same(['Synthetic second account #'.$second->serverId=>['.net']],$result['failed'],'Failure uses main account/TLD result shape');
same('$40.00',$storefront->offer('com','register',1)['price'],'Healthy account stays published');commerceReject(fn()=>$storefront->offer('net','register',1));
same('40.00',Pricing::rule($net->fresh())['fixed']['register'],'Failed account still adopts saved global rule');
$text=(new ReflectionMethod(SettingsActions::class,'published'))->invoke(null,$result);expect(str_contains($text,'Use Sync prices under What you sell, then save again.'),'Failure keeps main retry wording');
$fail=false;$result=$save('40.00');same([],$result['failed'],'Saving again retries failed account');same('$40.00',$storefront->offer('net','register',1)['price'],'Retry completes failed native publication');
// The same explicit all-accounts command must be fenced throughout the quiet conversion window.
$gate=new UpgradeGate;$gate->pause();$before=StaffCommands::stamp(Review::ALL_ACCOUNTS);commerceReject(fn()=>$save('50.00'));same($before,StaffCommands::stamp(Review::ALL_ACCOUNTS),'Quiet window changes no rule, fee, intent, projection or audit');$gate->resume();
commerceReject(fn()=>$writes->run($review('default_pricing',$binding->serverId),$data('50.00'),$now));
same($ids,Product::orderBy('id')->pluck('id')->all(),'All saves retain native product identities');same($calls,$reads->calls,'Global save and retry require no provider I/O');
$nativeSuccessful=true;echo "Offer global pricing: ok\n";
