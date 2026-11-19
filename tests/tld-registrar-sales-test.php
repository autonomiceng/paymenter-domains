<?php
// Native command regression: Allow checkboxes are deliberate sales intent, including a stopped offer.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldRegistrar};
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
$enomFacts = OfferState::decode($t->fresh()->catalogue_facts);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
$ports->apply(Edit::override($binding,'com',new Intent(sell:false)),$revision());
$r=new Review($actor->id,$t->server_id,$t->id,'seller',Review::ticket($actor->id,$t->server_id,$t->id,'seller',StaffCommands::stamp($t->server_id,$t->id)),'Start sales pending registrar terms');
$commands->run($r,['seller'=>$t->server_id,'register'=>true,'transfer'=>false]);
$decision=$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0];
same([false,'unknown_facts'],[$decision->available,$decision->reason],'Sales waits on real registrar terms, rather than staying stopped or inventing evidence');
same(null,$t->fresh()->catalogue_facts,'Staff Allow cannot supply registrar facts');
// Enom still inherits Sell when NameSilo takes registration. An expired known requirement still blocks a switch back.
$other = $tlds[1];
$reviewSeller = static fn () => new Review($actor->id, $t->server_id, $t->id, 'seller',
    Review::ticket($actor->id, $t->server_id, $t->id, 'seller', StaffCommands::stamp($t->server_id, $t->id)),
    'Change registration seller');
$commands->run($reviewSeller(), ['seller'=>$other->server_id, 'register'=>true, 'transfer'=>false]);
$policy = DB::table('domains_sales_policies')->where('tld', 'com');
$workingSeller = (array) $policy->sole();
same($other->server_id, $workingSeller['server_id'], 'The other registrar takes new registrations');
$enomFacts['observedAt'] = $now->modify('-2 days')->format(DATE_ATOM);
$enomFacts['freshUntil'] = $now->modify('-1 day')->format(DATE_ATOM);
$enomFacts['requiresNameservers'] = true;
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts'=>OfferState::json($enomFacts)]);
$disabled = TldRegistrar::choices($t->id, true, false)[$t->server_id]['disabled'];
commerceReject(fn () => $commands->run($reviewSeller(), ['seller'=>$t->server_id, 'register'=>true, 'transfer'=>false]));
same($workingSeller, (array) $policy->sole(), 'Refused change preserves the working registrar and actions');
same(true, $disabled, 'The restricted registrar cannot be selected for registration');
// A newer observation lifts the nameserver hold and allows only transfers.
$enomFacts['observedAt'] = $now->format(DATE_ATOM);
$enomFacts['freshUntil'] = $now->modify('+1 day')->format(DATE_ATOM);
$enomFacts['requiresNameservers'] = false;
$enomFacts['terms']['register'] = [];
unset($enomFacts['term_evidence'], $enomFacts['renewal_families']);
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts'=>OfferState::json($enomFacts)]);
$choice = TldRegistrar::choices($t->id, false, true)[$t->server_id];
same(false, $choice['disabled'], 'Transfer-only terms allow a transfer-only switch');
same(null, $choice['reason'], 'Fresh acquisition terms need no optional longer-renewal approval');
$commands->run($reviewSeller(), ['seller'=>$t->server_id, 'register'=>false, 'transfer'=>true]);
same(true, $ports->readOffers(new Query($binding,['com'],'transfer'),Context::Staff)[0]->available, 'Only the supported transfer goes on sale');
same(false, $ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->available, 'Registration stays off');

// A fresh NameSilo TEST row that no longer matches the accepted protocol cannot supply a binding denial or a sale.
$siloFacts = OfferState::decode($other->fresh()->catalogue_facts);
$siloFacts['terms']['register'] = [];
DB::table('domains_tlds')->where('id', $other->id)->update(['catalogue_facts'=>OfferState::json($siloFacts)]);
same(false, TldRegistrar::choices($t->id, true, false)[$other->server_id]['disabled'], 'Superseded TEST evidence allows pending intent');
$commands->run($reviewSeller(), ['seller'=>$other->server_id, 'register'=>true, 'transfer'=>false]);
$siloBinding = AccountBindings::binding(Account::where('server_id',$other->server_id)->sole());
same('unknown_facts', $ports->readOffers(new Query($siloBinding,['com'],'register'),Context::Staff)[0]->reason, 'Pending TEST intent grants no sale');
same(0,$reads->calls,'Changing registrar makes no provider calls');
echo "TLD registrar sales passed.\n";$nativeSuccessful=true;
