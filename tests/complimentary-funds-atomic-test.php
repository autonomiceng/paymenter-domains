<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,FundsWaits,NativeDomainGuard,HolderPricing};
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$staff=commerceUser();$staff->update(['role_id'=>App\Models\Role::create(['name'=>'Funding closure','permissions'=>['admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Cover test terms',$clock);
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
foreach(['register','transfer']as$action){
    $cart=commerceCart($user);$name=placeholderComFixtureName('comp-atomic-'.$action.'-placeholder.com');
    commerceAdd($cart,$native->product($tlds[0],$action),$name);commerceCheckout($user,$cart)->assertHasNoErrors()->assertRedirect();
    echo 'Checkout complete: '.$action."\n";
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    $op->update(['state'=>'held','safe_code'=>'registrar_balance_insufficient','policy'=>[...$op->policy,'funds_wait_at'=>$now->format(DATE_ATOM)]]);
    $account=Account::where('server_id',$op->server_id)->sole();$before=[$op->getRawOriginal(),$op->domain->getRawOriginal(),$nativeMoney()];
    $fail=(int)$op->service_id;
    DB::listen(static function($q)use(&$fail):void {
        if($fail&&str_starts_with($q->sql,'update "services"')&&in_array('cancelled',$q->bindings,true)&&(int)end($q->bindings)===$fail){$fail=0;throw new RuntimeException('Transient native closure');}
    });
    FundsWaits::sweep($account,$now->modify('+3 days'));
    same(0,$fail,'Native terminal projection was attempted: '.$action);
    same($before,[$op->fresh()->getRawOriginal(),$op->domain->fresh()->getRawOriginal(),$nativeMoney()],'Native failure rolls back the whole closure: '.$action);
    FundsWaits::sweep($account,$now->modify('+3 days'));
    same(['rejected','cancelled','paid'],[$op->fresh()->state,App\Models\Service::findOrFail($op->service_id)->status,App\Models\Invoice::findOrFail($op->invoice_id)->status],'Retry closes only the operation and service: '.$action);
    $closed=$nativeMoney();FundsWaits::sweep($account,$now->modify('+3 days'));same($closed,$nativeMoney(),'Repeat invents no payment or credit: '.$action);
}
echo "Complimentary funds closure is atomic and retryable\n";$nativeSuccessful=true;
