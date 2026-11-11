<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixed-rule repair dialog.
declare(strict_types=1);
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Models\Price;
use Paymenter\Extensions\Servers\Domains\Services\PricingRules;
$unreadablePricingCase='fixed';require __DIR__.'/Support/default-pricing-unreadable.php';
$nativeSuccessful=false;
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

$nativeSuccessful=true;echo "Unreadable shared pricing requires an explicit replacement.\n";
