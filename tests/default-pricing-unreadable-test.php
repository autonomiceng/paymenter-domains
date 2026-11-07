<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture exercises the real dialog, publication and sell-note callout together.
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRules,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();pinStaffClock($now);
$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));
$broken=$tlds[0]->replicate();$broken->fill(['tld'=>'invalid','use_default_rule'=>false,'pricing_mode'=>'fixed','fixed_register'=>null,'fixed_renew'=>null,'fixed_transfer'=>null,'catalogue_intent'=>json_encode(array_replace(json_decode($tlds[0]->catalogue_intent,true),['pricing_rule'=>['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>null,'renew'=>null,'transfer'=>null]]]),JSON_THROW_ON_ERROR)])->save();
foreach(Price::where(['server_id'=>$broken->server_id,'tld'=>'com'])->get() as $price){$copy=$price->replicate();$copy->tld='invalid';$copy->save();}
$savedTld=$broken->fresh()->getRawOriginal();
$savedPrices=static fn()=>Price::where(['server_id'=>$broken->server_id,'tld'=>'invalid'])->orderBy('id')->get()->map(fn($p)=>$p->getRawOriginal())->all();
$before=$savedPrices();
$warning=".invalid on Synthetic has no price: its custom pricing can't be read. Open .invalid and use Edit prices.";
$assertWarning=static function($page)use($warning):void{
    $dom=new DOMDocument;@$dom->loadHTML($page->html());$xpath=new DOMXPath($dom);
    same($warning,$xpath->evaluate('normalize-space(//*[@data-price-omission])'),'Unreadable TLD keeps its name and fix line');
};
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->set('account',WhatYouSell::ALL);
$assertWarning($page);
$page->mountAction('default_pricing')->assertActionMounted('default_pricing');
$assertWarning($page);
$page->setActionData(['mode'=>'markup_amount','markup'=>'2.00','round_up'=>'none','reason'=>'Update the business default despite an unreadable own rule'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved')->assertSet('mountedActions',[]);
same('markup_amount',(new PricingRules)->defaultRule()['mode'],'Default rule saves');
same('2.00',(new PricingRules)->defaultRule()['markup'],'Entered default markup saves');
foreach($tlds as $tld){same('8.00',$prices->current($tld->server_id,$tld->account_fingerprint,'com','register',1,'USD',$now)->retail,'Healthy default followers publish');}
$assertWarning($page);
$assertWarning(Livewire::actingAs($staff)->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->set('account',WhatYouSell::ALL));
same($savedTld,$broken->fresh()->getRawOriginal(),'Unreadable own rule is never replaced by a guessed rule');
same($before,$savedPrices(),'Unreadable TLD keeps every saved price and revision');
// An unreadable fee-mode override must not block the shared save's own-rule fee preflight either.
$bad=json_decode($broken->fresh()->catalogue_intent,true);$bad['pricing_rule']=['mode'=>'markup_fee_percent','markup'=>'-1.00','round_up'=>'none','fixed'=>['register'=>null,'renew'=>null,'transfer'=>null]];$broken->fresh()->update(['catalogue_intent'=>json_encode($bad,JSON_THROW_ON_ERROR)]);$savedTld=$broken->fresh()->getRawOriginal();
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->filterTable('for_sale',false)->assertSuccessful()->set('account',WhatYouSell::ALL);
$page->mountAction('default_pricing')->assertActionMounted('default_pricing')
    ->setActionData(['markup'=>'3.00','fee_percent'=>'3.10','reason'=>'Keep malformed own fee pricing on its repair path'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved')->assertSet('mountedActions',[]);
same('3.00',(new PricingRules)->defaultRule()['markup'],'Malformed own fee rule does not block saving');
$assertWarning($page);
same($savedTld,$broken->fresh()->getRawOriginal(),'Malformed own fee rule is never guessed');
same($before,$savedPrices(),'Malformed own fee rule retains saved prices and revisions');
same(0,$reads->calls,'Opening and saving use no registrar I/O');
echo "Default pricing opens and saves while preserving the named unreadable-TLD warning and prices.\n";$nativeSuccessful=true;
