<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

require __DIR__.'/operation-fixture.php';
require_once __DIR__.'/core-database.php';

$export = static function (string $path, bool $funded) use ($tlds, $reads): void {
    $booted = (new ReflectionProperty(Illuminate\Database\Eloquent\Model::class, 'booted'))->getValue();
    exportCoreDatabase(Illuminate\Support\Facades\DB::connection()->getPdo(), $path, array_keys($booted), [
        'funded' => $funded,
        'tlds' => array_map(static fn ($tld) => $tld->getRawOriginal(), $tlds),
        'reads' => $reads->calls,
        'config' => [
            'domains.namesilo_account.environment' => config('domains.namesilo_account.environment'),
            'domains.namesilo_test.accounts' => config('domains.namesilo_test.accounts'),
        ],
    ]);
};
$export((string) getenv('PAYMENTER_TEST_OPERATION_EXPORT'), false);

// Use the same catalogue refresh, native checkout and successful transaction as reconciliation.
activeCommerceFacts($clock->now(), $clock->now()->modify('+1 day'), $ports);
$order = $paidRegistration(fundedRegistrationFixtureName());
$invoice = App\Models\Invoice::findOrFail($order->invoice_id);
same('paid', $invoice->status, 'Seed contains a funded native invoice');
$transaction = $invoice->transactions()->sole();
same('succeeded', $transaction->getRawOriginal('status'), 'Seed contains a successful native transaction');
$export((string) getenv('PAYMENTER_TEST_FUNDED_EXPORT'), true);
$nativeSuccessful = true;
