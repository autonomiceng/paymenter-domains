<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real reviewed staff save and native publication: a rule-only unit test cannot prove recovery.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,OfferState,Pricing,Query,StaffCommands};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;

extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
// A retained legacy override has no pricing_rule in intent and unreadable legacy fixed columns.
$t=Tld::findOrFail($tlds[0]->id);$intent=OfferState::decode($t->catalogue_intent);unset($intent['pricing_rule']);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent),
    'use_default_rule'=>false,'pricing_mode'=>'fixed','fixed_register'=>null,'fixed_renew'=>null,'fixed_transfer'=>null,
    'catalogue_published_revision'=>null]);
try{Pricing::own($t->fresh());expect(false,'The legacy fixture must have unreadable pricing');}
catch(InvalidArgumentException $error){same('Enter all three prices, at least $0.01 each.',$error->getMessage(),'The fixture reaches the reported legacy rule refusal');}
$staff=new StaffCommands($ports->writer(),$bindings,static fn()=>null);
$review=static fn()=>new Review($actor->id,$binding->serverId,$t->id,'override',
    Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id)),
    'Replace unreadable legacy pricing with reviewed fixed prices');
$prices=['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'12.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00'];
$before=StaffCommands::stamp($binding->serverId,$t->id);
try{$staff->run($review(),[...$prices,'fixed_register'=>null]);expect(false,'Replacement must still validate all three new prices');}
catch(DomainException|InvalidArgumentException){}
same($before,StaffCommands::stamp($binding->serverId,$t->id),'An invalid replacement changes no rule, publication or review state');
$result=$staff->run($review(),$prices);
same(1,$result['published'],'A staff save replaces the unreadable legacy rule and publishes');
same([],$result['failed'],'Recovery has no failed TLD');
same(['register'=>'12.00','renew'=>'14.00','transfer'=>'13.00'],Pricing::rule($t->fresh())['fixed'],'The new three prices replace the unreadable rule');
same($intent,array_diff_key(OfferState::decode($t->fresh()->catalogue_intent),['pricing_rule'=>true]),'Repair preserves sales intent and term stops');
$published=OfferState::decode($t->fresh()->catalogue_published_revision);
same(get_object_vars($ports->readOffers(new Query($binding,[$t->tld],'register'),Context::Staff)[0]->revision),$published,'The repaired offer has a current publication revision');
same('$12.00',$storefront->offer('com','register',1)['price'],'A previously sold TLD resumes at the reviewed registration price');
same('14.00',OfferState::money($t->fresh()->catalogue_retained_annual_retail,2),'The valid save also publishes annual renewal pricing');
same(0,$reads->calls,'Repair and publication resolve no provider');
echo "Reviewed Edit prices replaces unreadable legacy pricing and restores publication.\n";
$nativeSuccessful=true;
