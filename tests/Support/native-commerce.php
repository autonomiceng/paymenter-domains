<?php
// Native checkout helpers adapted from Enom 444f31c tests/enom-cart-order.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
use App\Models\{Cart, Product, User};
use Illuminate\Support\Facades\Auth;
Qirolab\Theme\Theme::set('default', 'default');
function commerceUser(): User
{
    return User::create(['first_name' => 'Test', 'last_name' => 'Owner', 'email' => 'owner-'.User::count().'@example.com', 'password' => 'test-password']);
}
function commerceCart(User $user): Cart
{
    Illuminate\Support\Once::flush(); Auth::login($user);
    return Cart::create(['user_id' => $user->id, 'currency_code' => 'USD']);
}
function commerceAdd(Cart $cart, Product $product, string $name, int $term = 1): App\Models\CartItem
{
    return $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->where('billing_period', $term)->sole()->id,
        'quantity' => 1, 'config_options' => [], 'checkout_config' => ['domain' => $name, 'epp' => 'discard-me', 'authority' => 'forged']]);
}
function commerceCheckout(User $user, Cart $cart)
{
    Illuminate\Support\Once::flush();
    $page=Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
    // Ordinary commerce cases supply the contact form; account-specific cases define their own properties.
    $required=['address','city','zip','country','phone'];
    return $user->properties()->whereIn('key',$required)->distinct()->count('key')===count($required) ? $page->call('checkout')
        : $page->call('checkout',['contact'=>commerceOrderContact($user),'save_account'=>false]);
}

function commerceOrderContact(User $user): array
{
    return array_replace(\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect($user)['registrant'],
        ['first_name'=>'Test','last_name'=>'Owner','email_address'=>'owner@example.com','address1'=>'1 Main Street',
        'city'=>'Seattle','state_province'=>'WA','postal_code'=>'98101','country'=>'US','phone'=>'+1.2065550100']);
}

/** Synthetic retained order from before checkout required valid contacts. Never a product admission path. */
function commerceLegacyCheckout(User $user, Cart $cart)
{
    $beforeOrderId = App\Models\Order::max('id');
    $legacy = \Paymenter\Extensions\Servers\Domains\Services\ContactDetails::collect($user);
    $contact = commerceOrderContact($user);
    Illuminate\Support\Once::flush();
    $page=Livewire\Livewire::actingAs($user)->withCookies(['cart'=>$cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits',false)
        ->call('checkout',['contact'=>$contact,'save_account'=>false]);
    expect(App\Models\Order::max('id') > $beforeOrderId, 'Legacy fixture must first complete a valid native checkout');
    foreach (\Paymenter\Extensions\Servers\Domains\Models\Operation::whereHas('domain.service',fn($q)=>$q->where('order_id',App\Models\Order::latest('id')->value('id')))->get() as $op) {
        \Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update(['payload'=>array_replace($op->payload,['contacts'=>$legacy,'setup_required'=>true])]));
    }
    return $page;
}
function commerceHosting(): Product
{
    $product = Product::create(['name' => 'Hosting', 'slug' => 'hosting', 'category_id' => App\Models\Category::firstOrFail()->id,
        'allow_quantity' => 'combined', 'stock' => 10]);
    $plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month']);
    $plan->prices()->create(['currency_code' => 'USD', 'price' => 5, 'setup_fee' => 0]);
    return $product;
}
