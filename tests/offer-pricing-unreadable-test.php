<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture covers the converted matrix and reviewed publication, not a parallel pricing stub.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PricingRule,PricingRules,StaffSettingsReview as Review};
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);
$broken=$tlds[0]->fresh()->replicate();$intent=OfferState::decode($broken->catalogue_intent);unset($intent['pricing_rule']);
$broken->fill(['tld'=>'invalid','catalogue_intent'=>OfferState::json($intent),'use_default_rule'=>false,'pricing_mode'=>'fixed','fixed_register'=>null,'fixed_renew'=>null,'fixed_transfer'=>null])->save();
$before=$broken->fresh()->getRawOriginal();$failures=[];
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$review=static fn()=>new Review($actor->id,Review::ALL_ACCOUNTS,null,'default_pricing',Review::ticket($actor->id,Review::ALL_ACCOUNTS,null,'default_pricing',StaffCommands::stamp(Review::ALL_ACCOUNTS)), 'Change the default while retaining unreadable own pricing');
try{
    $result=$commands->run($review(),['mode'=>'fixed','fixed_register'=>'20.00','fixed_renew'=>'20.00','fixed_transfer'=>'20.00','fee_percent'=>'2.9','fee_fixed'=>'0.3']);
    same([],$result['failed'],'Unreadable own rule does not prevent healthy converted publication');
    same('20.00',(new PricingRules)->defaultRule()['fixed']['register'],'Converted global default saves');
    same('$20.00',$storefront->offer('com','register',1)['price'],'Healthy converted follower publishes');
}catch(InvalidArgumentException $error){$failures[]='Converted global save: '.$error->getMessage();}
try{
    $matrix=$source->matrix(collect([$tlds[0]->fresh(),$broken->fresh()]),1);
    same([$broken->id],array_column($matrix['skipped'],'id'),'Converted matrix identifies unreadable own pricing');
    same([$tlds[0]->id],array_column($matrix['rows'],'id'),'Healthy converted row remains readable');
    $html=view('domains::admin.sell-note',['warning'=>$matrix['warnings'][0]])->render();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    same(".invalid on Synthetic has prices left out: its custom pricing can't be read. Open .invalid and use Edit prices.",
        $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Converted path retains the existing named warning and fix line');
}catch(InvalidArgumentException $error){$failures[]='Converted price matrix: '.$error->getMessage();}
foreach($failures as $failure){echo $failure."\n";}same([],$failures,'Converted readers and default save isolate unreadable own pricing');
same($before,$broken->fresh()->getRawOriginal(),'Converted unreadable rule and publication evidence stay unchanged');
// A readable saved fee rule must still veto a new fee that makes its price impossible.
$saved=$tlds[0]->fresh()->replicate();$own=[...PricingRule::DEFAULT,'mode'=>'markup_fee_percent','markup'=>'100.00','card_fee'=>CardFee::DEFAULT];
$savedIntent=[...OfferState::decode($saved->catalogue_intent),'pricing_rule'=>$own];
$saved->fill(['tld'=>'savedfee','catalogue_intent'=>OfferState::json($savedIntent),'use_default_rule'=>false])->save();
$stamp=StaffCommands::stamp(Review::ALL_ACCOUNTS);$refused=false;
try{$commands->run($review(),['mode'=>'fixed','fixed_register'=>'30.00','fixed_renew'=>'30.00','fixed_transfer'=>'30.00','fee_percent'=>'50.00','fee_fixed'=>'0.30']);}
catch(InvalidArgumentException $error){same('This percentage cannot cover the card fee. Lower the markup or card fee.',$error->getMessage(),'Readable saved rule rejects the conflicting fee');$refused=true;}
expect($refused,'Readable saved fee rule must reject an incompatible new fee');
same($stamp,StaffCommands::stamp(Review::ALL_ACCOUNTS),'Conflicting fee rolls back shared settings, TLDs and audit');
same('$20.00',$storefront->offer('com','register',1)['price'],'Rejected fee leaves healthy publication unchanged');
echo "Readable saved fee rule rejects the conflicting new fee without changing shared state.\n";
// Saved intent bypasses the legacy reader: invalid own content must still be isolated.
$savedIntent['pricing_rule']['markup']='invalid';$saved->fill(['catalogue_intent'=>OfferState::json($savedIntent)])->save();
$savedBefore=$saved->fresh()->getRawOriginal();
try{$result=$commands->run($review(),['mode'=>'fixed','fixed_register'=>'25.00','fixed_renew'=>'25.00','fixed_transfer'=>'25.00','fee_percent'=>'3.00','fee_fixed'=>'0.40']);}
catch(InvalidArgumentException $error){throw new RuntimeException('Malformed saved fee rule must not reject the shared default save: '.$error->getMessage(),0,$error);}
same([],$result['failed'],'Malformed saved fee rule is skipped rather than published');
same('25.00',(new PricingRules)->defaultRule()['fixed']['register'],'Malformed saved fee rule does not roll back shared pricing');
same(['percent'=>'3.00','fixed'=>'0.40'],CardFee::read(),'Malformed saved fee rule does not roll back the shared fee');
same('$25.00',$storefront->offer('com','register',1)['price'],'Healthy follower publishes despite malformed saved fee rule');
same($savedBefore,$saved->fresh()->getRawOriginal(),'Malformed saved fee rule retains its intent and publication evidence');
$matrix=$source->matrix(collect([$tlds[0]->fresh(),$saved->fresh()]),1);
same([$saved->id],array_column($matrix['skipped'],'id'),'Malformed saved fee rule retains its TLD warning');
same([$tlds[0]->id],array_column($matrix['rows'],'id'),'Healthy matrix row remains readable beside malformed saved fee rule');
$html=view('domains::admin.sell-note',['warning'=>$matrix['warnings'][0]])->render();
$dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
same(".savedfee on Synthetic has prices left out: its custom pricing can't be read. Open .savedfee and use Edit prices.",
    $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Malformed saved fee rule retains the named warning and repair action');
echo "Malformed saved fee rule keeps its TLD warning while shared pricing and healthy publication succeed.\n";
same(0,$reads->calls,'Converted pricing needs no provider I/O');
echo "Converted pricing keeps unreadable TLD warnings while the shared default saves and publishes.\n";$nativeSuccessful=true;
