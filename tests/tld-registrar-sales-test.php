<?php
// Native command regression: Allow checkboxes are deliberate sales intent, including a stopped offer.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,OfferState,Projection,Query,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsReview as Review};
extract(commerceFixture());tldPanel();pinStaffClock($now);
$t=$tlds[0];$binding=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$revision=static fn()=>$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->revision;
$ports->apply(Edit::override($binding,'com',new Intent(sell:false,stops:['register','transfer'])),$revision());
$ports->apply(Edit::seller($binding,'com',[]),$revision());
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$r=new Review($actor->id,$t->server_id,$t->id,'seller',Review::ticket($actor->id,$t->server_id,$t->id,'seller',StaffCommands::stamp($t->server_id,$t->id)),'Resume sales on this registrar');
$commands->run($r,['seller'=>$t->server_id,'register'=>true,'transfer'=>true]);
foreach(['register','transfer'] as $action){
    $offer=$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
    same(true,$offer->available,'Allow '.$action.' makes the stopped TLD purchasable: '.$offer->reason);
}
$registration=$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0];
$nativePrice=$native->product($t->fresh(),'register')->plans()->where('billing_period',1)->sole()->prices()->where('currency_code','USD')->sole()->price;
same(Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($registration->retail),Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($nativePrice),'The native order price matches the now-sellable offer');
// Replay the actual inherited-Off state, as well as the explicit stop above.
$defaults=Account::where('server_id',$t->server_id)->value('catalogue_defaults');
$ports->apply(Edit::override($binding,'com',new Intent(sell:null)),$revision());
$r=new Review($actor->id,$t->server_id,$t->id,'seller',Review::ticket($actor->id,$t->server_id,$t->id,'seller',StaffCommands::stamp($t->server_id,$t->id)),'Start inherited stopped sales');
$commands->run($r,['seller'=>$t->server_id,'register'=>false,'transfer'=>true]);
same(true,$ports->readOffers(new Query($binding,['com'],'transfer'),Context::Staff)[0]->available,'Allow transfers starts the inherited-Off TLD');
same(false,$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->available,'Unchecked registrations stay off');
same($defaults,Account::where('server_id',$t->server_id)->value('catalogue_defaults'),'Starting one TLD never changes account defaults');
// Missing independent terms stay missing; this intent change must not invent sales evidence.
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
$ports->apply(Edit::override($binding,'com',new Intent(sell:false)),$revision());
$r=new Review($actor->id,$t->server_id,$t->id,'seller',Review::ticket($actor->id,$t->server_id,$t->id,'seller',StaffCommands::stamp($t->server_id,$t->id)),'Start sales pending registrar terms');
$commands->run($r,['seller'=>$t->server_id,'register'=>true,'transfer'=>false]);
$decision=$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0];
same([false,'unknown_facts'],[$decision->available,$decision->reason],'Sales waits on real registrar terms, rather than staying stopped or inventing evidence');
same(null,$t->fresh()->catalogue_facts,'Staff Allow cannot supply registrar facts');
same(0,$reads->calls,'Changing registrar makes no provider calls');
echo "TLD registrar sales passed.\n";$nativeSuccessful=true;
