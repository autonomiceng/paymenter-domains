<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Each mutation retains its own genuine native invoice and payment.
require __DIR__.'/Support/operation-claims.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
// Payment reversal, authority generation, reservation, owner, paused processing, payload and cancellation
// each change during preflight from the one prepared claim fixture; the reset restores rows and fakes after each.
foreach (['reversal', 'generation', 'reservation', 'owner', 'pause', 'payload', 'cancel'] as $change) {
    $cases[$change] = static function () use ($change, $new, $preflight, $assertNoSend, $bindings, $user, $ports): void {
        $op = $new('changed-'.$change.'.example.com');
        $preflight->during = static function ($claimed, $domain) use ($change, $bindings, $user) {
            match ($change) {
                'reversal' => InvoiceTransaction::create(['invoice_id' => $claimed->invoice_id, 'amount' => '-0.01', 'fee' => '0.00', 'status' => 'succeeded']),
                'generation' => DB::table('domains_domains')->where('id', $domain->id)->update(['authority_generation' => 2]),
                'reservation' => DB::table('domains_domains')->where('id', $domain->id)->update(['reservation_name' => null]),
                'owner' => DB::table('services')->where('id', $domain->service_id)->update(['user_id' => $user->id + 999]),
                'pause' => $bindings->setProcessing($domain->server_id, false),
                'payload' => $claimed->fresh()->update(['payload' => $claimed->payload + ['changed' => true]]),
                'cancel' => App\Models\ServiceCancellation::create(['service_id' => $domain->service_id, 'reason' => 'Synthetic', 'type' => 'end_of_period']),
            };
        };
        $result = $assertNoSend($op); same('absent', $result->attempt['seal'], 'Authority changes block final seal');
        $preflight->during = null; if ($change === 'pause') {
            $bindings->setProcessing($op->server_id,true,syntheticActivation());
            $b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole());
            $q=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($b,[$op->domain->tld],'register');
            $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::republish($b,$op->domain->tld),$ports->readOffers($q,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
        }
    };
}
nativeFixtureCases($cases, compact('clock', 'preflight', 'writer', 'engine', 'claims', 'bindings', 'ports', 'user'));
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
