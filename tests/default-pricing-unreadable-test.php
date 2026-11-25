<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native unreadable pricing repair dialogs and reload.
declare(strict_types=1);
// Native fixture exercises the real dialog, publication and sell-note callout together.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRules,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();pinStaffClock($now);
$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));
$broken=$tlds[0]->replicate();$intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($broken->catalogue_intent);unset($intent['pricing_rule']);$broken->catalogue_intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json($intent);$broken->fill(['tld'=>'invalid','use_default_rule'=>false,'pricing_mode'=>'fixed','fixed_register'=>null,'fixed_renew'=>null,'fixed_transfer'=>null])->save();
foreach(Price::where(['server_id'=>$broken->server_id,'tld'=>'com'])->get() as $price){$copy=$price->replicate();$copy->tld='invalid';$copy->save();}
$savedTld=$broken->fresh()->getRawOriginal();
$savedPrices=static fn()=>Price::where(['server_id'=>$broken->server_id,'tld'=>'invalid'])->orderBy('id')->get()->map(fn($p)=>$p->getRawOriginal())->all();
$before=$savedPrices();
$warning=".invalid on Synthetic has prices left out: its custom pricing can't be read. Open .invalid and use Edit prices.";
$assertWarning=static function($page)use($warning):void{
    $dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
    same($warning,$xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Unreadable TLD keeps its name and fix line');
};
// Every case starts from the same unreadable .invalid own rule beside healthy default followers. The reset restores
// rows, container, auth and listeners before the next case.
// Pin each case to the prepared read and Carbon clocks explicitly, whatever a reset restores.
$pinned=[$clock->now(),Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->instant,$mutable,$immutable]=$pinned;Carbon\Carbon::setTestNow($mutable);Carbon\CarbonImmutable::setTestNow($immutable);};
$fixed=compact('staff','tlds','prices','now','reads','broken','savedTld','savedPrices','before','assertWarning','pin');

$cases['fixed']=static function()use($fixed):void{extract($fixed);$pin();
    $page=Livewire::actingAs($staff)->withQueryParams(['account'=>WhatYouSell::ALL])->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->assertSet('account',WhatYouSell::ALL);
    $assertWarning($page);
    $page->mountAction('default_pricing')->assertActionMounted('default_pricing');
    $assertWarning($page);
    $page->setActionData(['mode'=>'markup_amount','markup'=>'2.00','round_up'=>'none','reason'=>'Update the business default despite an unreadable own rule'])
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved')->assertSet('mountedActions',[]);
    same('markup_amount',(new PricingRules)->defaultRule()['mode'],'Default rule saves');
    same('2.00',(new PricingRules)->defaultRule()['markup'],'Entered default markup saves');
    foreach($tlds as $tld){same('8.00',$prices->current($tld->server_id,$tld->account_fingerprint,'com','register',1,'USD',$now)->retail,'Healthy default followers publish');}
    $assertWarning($page);
    same($savedTld,$broken->fresh()->getRawOriginal(),'Unreadable own rule is never replaced by a guessed rule');
    same($before,$savedPrices(),'Unreadable TLD keeps every saved price and revision');
    // A native dialog is required to prove field defaults and required validation protect saved pricing.
    fixtureBusinessChoice(PricingRules::KEY,['mode'=>'markup_percent','markup'=>'invalid','round_up'=>'none']);
    $beforeRule=fixtureBusinessRaw(PricingRules::KEY);
    $beforePrices=Price::orderBy('id')->get()->map(fn($price)=>$price->getRawOriginal())->all();
    $page=Livewire::actingAs($staff)->withQueryParams(['account'=>WhatYouSell::ALL])->test(WhatYouSell::class)
        ->mountAction('default_pricing')->assertActionMounted('default_pricing');
    $data=$page->instance()->mountedActions[0]['data'];
    foreach(['mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer'] as $field){
        same(null,$data[$field]??null,'Unreadable saved pricing leaves '.$field.' for staff to enter');
    }
    $page->setActionData(['reason'=>'Repair unreadable shared pricing'])->callMountedAction()->assertHasActionErrors(['mode'=>'required']);
    same($beforeRule,fixtureBusinessRaw(PricingRules::KEY),'Submitting without a replacement preserves the saved rule');
    same($beforePrices,Price::orderBy('id')->get()->map(fn($price)=>$price->getRawOriginal())->all(),'Submitting without a replacement preserves all prices');
    $page->setActionData(['mode'=>'markup_amount','markup'=>'4.00','round_up'=>'none'])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
    same('4.00',(new PricingRules)->defaultRule()['markup'],'An explicit valid replacement repairs shared pricing');
};
$cases['persistence']=static function()use($fixed):void{extract($fixed);$pin();
    // The reload is independent of the dialog renders; arrange the same committed native writer state.
    app(StaffSettingsWrites::class)->run(pricingReview($staff->id),pricingForm(['mode'=>'markup_amount','markup'=>'2.00','round_up'=>'none']),$now);
    $assertWarning(Livewire::actingAs($staff)->withQueryParams(['account'=>WhatYouSell::ALL])->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->assertSet('account',WhatYouSell::ALL));
    same('markup_amount',(new PricingRules)->defaultRule()['mode'],'Reload retains saved default mode');
    same('2.00',(new PricingRules)->defaultRule()['markup'],'Reload retains saved default markup');
    foreach($tlds as $tld){same('8.00',$prices->current($tld->server_id,$tld->account_fingerprint,'com','register',1,'USD',$now)->retail,'Reload retains healthy default followers on both accounts');}
    same($savedTld,$broken->fresh()->getRawOriginal(),'Reload retains the original unreadable own rule');
    same($before,$savedPrices(),'Reload retains every saved price and revision');
    same(0,$reads->calls,'Reload uses no registrar I/O');
};
$cases['fee']=static function()use($fixed):void{extract($fixed);$pin();
    // Reach the same committed amount-pricing state without replaying the other dialog matrix.
    app(StaffSettingsWrites::class)->run(pricingReview($staff->id),pricingForm(['mode'=>'markup_amount','markup'=>'2.00','round_up'=>'none']),$now);
    // An unreadable fee-mode override must not block the shared save's own-rule fee preflight either.
    $broken->fresh()->update(['pricing_mode'=>'markup_fee_percent','markup'=>'-1.00']);$savedTld=$broken->fresh()->getRawOriginal();
    $page=Livewire::actingAs($staff)->withQueryParams(['account'=>WhatYouSell::ALL])->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->assertSet('account',WhatYouSell::ALL);
    $page->mountAction('default_pricing')->assertActionMounted('default_pricing')
        ->setActionData(['markup'=>'3.00','fee_percent'=>'3.10','reason'=>'Keep malformed own fee pricing on its repair path'])
        ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved')->assertSet('mountedActions',[]);
    same('3.00',(new PricingRules)->defaultRule()['markup'],'Malformed own fee rule does not block saving');
    $assertWarning($page);
    same($savedTld,$broken->fresh()->getRawOriginal(),'Malformed own fee rule is never guessed');
    same($before,$savedPrices(),'Malformed own fee rule retains saved prices and revisions');
    same(0,$reads->calls,'Opening and saving use no registrar I/O');
};
nativeFixtureCases($cases, compact('clock','reads','staff','broken','tlds','prices','native','sellers','bindings','ports','offers'));
echo "Default pricing preserves unreadable own rules and exact native rows.\n";$nativeSuccessful=true;
