<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture covers the converted matrix and reviewed publication, not a parallel pricing stub.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Projection,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRules,StaffSettingsReview as Review};
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
    same(".invalid on Synthetic left out: custom pricing can't be read. To fix, open the .invalid TLD page and use Edit prices.",
        $xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Converted path retains the existing named warning and fix line');
}catch(InvalidArgumentException $error){$failures[]='Converted price matrix: '.$error->getMessage();}
foreach($failures as $failure){echo $failure."\n";}same([],$failures,'Converted readers and default save isolate unreadable own pricing');
same($before,$broken->fresh()->getRawOriginal(),'Converted unreadable rule and publication evidence stay unchanged');
same(0,$reads->calls,'Converted pricing needs no provider I/O');
echo "Converted pricing keeps unreadable TLD warnings while the shared default saves and publishes.\n";$nativeSuccessful=true;
