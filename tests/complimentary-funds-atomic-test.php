<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/acquisition-evidence-fixture.php';
require __DIR__.'/Support/operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionResult;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,ComplimentaryCosts,FundsWaits,NativeDomainGuard,HolderPricing};
(new NativeDomainGuard(new HolderPricing($prices),$clock))->boot();
$staff=commerceUser();$staff->update(['role_id'=>App\Models\Role::create(['name'=>'Funding closure','permissions'=>['admin.domains.fund','admin.domains.manage','admin.domains.costs']])->id]);
ComplimentaryAccounts::set($user->id,$staff,true,'Cover test terms',$clock);
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
foreach(['register','transfer']as$action){
    $cart=commerceCart($user);$name=placeholderComFixtureName('comp-atomic-'.$action.'-placeholder.com');
    commerceAdd($cart,$native->product($tlds[0],$action),$name);commerceCheckout($user,$cart)->assertHasNoErrors()->assertRedirect();
    echo 'Checkout complete: '.$action."\n";
    $op=Domain::where('domain',$name)->sole()->operations()->sole();
    fixtureAcquisitionFundsWait($op, $now);
    $op->refresh();
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

// A later cost hold may remove the retry schedule while the original wait is still in force.
$name = placeholderComFixtureName('comp-atomic-cost-approval-placeholder.com');
$cart = commerceCart($user);
commerceAdd($cart, $native->product($tlds[0], 'register'), $name);
commerceCheckout($user, $cart)->assertHasNoErrors()->assertRedirect();
$op = Domain::where('domain', $name)->sole()->operations()->sole();
fixtureAcquisitionFundsWait($op, $now);
DB::transaction(static function () use ($op): void {
    $current = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
    $current->update(['safe_code' => 'registrar_price_increased',
        'policy' => [...$current->policy, 'complimentary_cost_review' => ['amount' => '10.01', 'usual' => '10.00']]]);
    AcquisitionResult::schedule($current, null, null);
});
$held = $op->fresh();
app()->instance(Clock::class, $clock);
$clock->instant = $now->modify('+2 days');
expect(ComplimentaryCosts::awaitingApproval($held), 'Cost approval is available while the original wait is open');
$clock->instant = $now->modify('+3 days');
$offered = ComplimentaryCosts::awaitingApproval($held);
$inserted = false;
DB::listen(static function ($query) use (&$inserted): void {
    if (str_starts_with($query->sql, 'insert into "domains_complimentary_approvals"')) { $inserted = true; }
});
$refused = false;
try { ComplimentaryCosts::approve($held->id, $staff, '10.01', 'Reviewed registrar cost', $clock); }
catch (DomainException) { $refused = true; }
$current = $held->fresh();
$continuation = AcquisitionResult::continuation($current);
same([false, true, false, true, $now->getTimestamp(), null],
    [$offered, $refused, $inserted, $current->getRawOriginal() === $held->getRawOriginal(),
        $continuation->shortfallAt?->getTimestamp(), $continuation->lane],
    'An elapsed funds wait blocks the cost approval offer, insert and release');
FundsWaits::sweep($account, $clock->now());
$closed = $held->fresh();
same(['rejected', null], [$closed->state, AcquisitionResult::continuation($closed)->shortfallAt],
    'Terminal closure clears a shortfall without a retry schedule');
echo "Complimentary funds closure is atomic and retryable\n";$nativeSuccessful=true;
