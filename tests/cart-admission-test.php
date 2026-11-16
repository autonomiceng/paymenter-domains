<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$cartAdmissionCase='basics';
require __DIR__.'/Support/cart-admission.php';

$nativeSuccessful=false;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
// A Domains money hold gates only carts containing a domain, through the real native checkout.
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$domain=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('user_id',$user->id)->firstOrFail();
$holdId=DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>hash('sha256','synthetic_cart_hold'),'domain_id'=>$domain->id,
    'generation'=>$domain->authority_generation,'code'=>'account_money_hold','first_seen'=>1,'last_seen'=>1]);
$before=Order::count();$cart=commerceCart($user);commerceAdd($cart,$product,'money-held-placeholder.com');
commerceCheckout($user,$cart);same($before,Order::count(),'Account hold refuses a new domain order');
$cart=commerceCart($user);commerceAdd($cart,$hosting,'hosting');commerceCheckout($user,$cart)->assertHasNoErrors();
same($before+1,Order::count(),'Account hold allows a hosting-only cart');
DB::table('domains_obligation_alerts')->where('id',$holdId)->update(['resolved_at'=>1]);
$cart=commerceCart($user);commerceAdd($cart,$product,'money-released-placeholder.com');commerceCheckout($user,$cart)->assertHasNoErrors();
same($before+2,Order::count(),'Staff release permits domain orders again');

$nativeSuccessful=true;
