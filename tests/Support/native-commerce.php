<?php
// Native checkout helpers adapted from Enom 444f31c tests/enom-cart-order.php.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Cart, Product, User};
use Illuminate\Support\Facades\Auth;
Qirolab\Theme\Theme::set('default', 'default');
function commerceUser(): User
{
    return User::create(['first_name' => 'Test', 'last_name' => 'Owner', 'email' => 'owner-'.User::count().'@example.test', 'password' => 'test-password']);
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
    return Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false)->call('checkout');
}
function commerceHosting(): Product
{
    $product = Product::create(['name' => 'Hosting', 'slug' => 'hosting', 'category_id' => App\Models\Category::firstOrFail()->id,
        'allow_quantity' => 'combined', 'stock' => 10]);
    $plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month']);
    $plan->prices()->create(['currency_code' => 'USD', 'price' => 5, 'setup_fee' => 0]);
    return $product;
}
