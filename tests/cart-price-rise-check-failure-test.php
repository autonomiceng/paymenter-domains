<?php
// A price rise shows "Your cart changed" even when the domain check on cart load fails. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/offer-commerce.php';
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission,CartCheckoutRefresh,CartNotice,EligibilityUnconfirmed};
extract(offerCommerceFixture());$user=commerceUser();$cart=commerceCart($user);
$line=commerceAdd($cart,$storefront->offer('com','register',1)['product'],'risen-price-placeholder.com');
Illuminate\Support\Once::flush();app('request')->cookies->set('cart',$cart->ulid);
// The registrar check is not confirmed on this load; checkout runs it again before payment.
$admission=new class($native,$sellers,$eligibility,$clock) extends CartAdmission {
    public bool $display=false;
    public function refresh():void {if($this->display){throw new App\Exceptions\DisplayException('Synthetic checkout refusal');}throw new EligibilityUnconfirmed(null);}
};
CartCheckoutRefresh::boot($admission);
$listeners=(new ReflectionProperty(Livewire\EventBus::class,'listeners'))->getValue(app(Livewire\EventBus::class));
$mount=end($listeners['mount']);
$messages=static fn($component)=>collect(array_map(static fn($event)=>$event->serialize(),Livewire\store($component)->get('dispatched',[])))
    ->where('name','notify')->map(static fn($event)=>$event['params'][0]['message']??'')->values()->all();
$amount=number_format(App\Classes\Cart::items()->first()->price->total * $line->quantity,2,'.','');
foreach(['risen','unchanged','missing'] as $baseline){
    $risen=$baseline==='risen';
    Livewire\store($component=new App\Livewire\Cart)->set('dispatched',[]);
    // The customer last saw 1.00, so the current line price is a rise.
    session()->put('domains.cart_shown.'.$cart->ulid,$baseline==='missing'?[]:[$line->id=>['plan_id'=>(int)$line->plan_id,'quantity'=>(int)$line->quantity,'amount'=>$risen?'1.00':$amount]]);
    $mount($component);$notice=CartNotice::get($component);
    if($risen){
        same('Your cart changed',$notice['title']??null,'A price rise shows the cart changed notice when the check fails');
        same([$line->id=>'1.00'],$notice['was']??null,'The notice keeps the price the customer last saw');
        expect(!in_array('We couldn’t confirm the price and term for risen-price-placeholder.com with the registrar, so checkout is paused. Try again in a few minutes, or remove it from your cart.',$messages($component),true),'A price rise does not say the domain could not be checked');
    }else{
        expect(($notice['title']??null)!=='Your cart changed'&&($notice['was']??[])===[],'No price change shows no cart changed notice');
        same(['We couldn’t confirm your domain','We couldn’t confirm the price and term for risen-price-placeholder.com with the registrar, so checkout is paused. Try again in a few minutes, or remove it from your cart.',['risen-price-placeholder.com']],
            [$notice['title']??null,$notice['text']??null,$notice['names']??null],'A failed check with no price change says why checkout waits, in the cart notice');
        same([],$messages($component),'The reason is the cart notice, not a passing toast');
    }
}
$admission->display=true;
Livewire\store($component=new App\Livewire\Cart)->set('dispatched',[]);
session()->put('domains.cart_shown.'.$cart->ulid,[$line->id=>['plan_id'=>(int)$line->plan_id,'quantity'=>(int)$line->quantity,'amount'=>'1.00']]);
$mount($component);
same(['Synthetic checkout refusal'],$messages($component),'A displayed exception is the sole notice after a price change');
same(null,CartNotice::get($component),'A displayed exception adds no price-change notice');
same($amount,number_format((float)$component->total->total,2,'.',''),'A displayed exception still refreshes the total');
$nativeSuccessful=true;echo "Cart price rise with a failed check passed.\n";
