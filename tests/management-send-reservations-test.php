<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Offline production transport with namespace-local cURL fakes; no sockets.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/WireLimit.php';
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1);
});
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCalls, EnomTestCallSchema, EnomTestManagement, Rdap, RenewalAcceptance, Runtime, TransferPrecheck, WriteAcceptance, WriteFeatures};

app()->instance(Clock::class, $clock);
EnomTestCallSchema::install();
$nativePolicy = ['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true,
    'domains.enom_writes.management' => true, 'domains.enom_test_daily_call_cap' => 1000];
$previousPolicy = array_map(static fn ($key) => config($key), array_combine(array_keys($nativePolicy), array_keys($nativePolicy)));
foreach (['lock' => ['locked' => false], 'nameservers' => ['nameservers' => ['ns3.example.com', 'ns4.example.com']]] as $action => $payload) {
    [$d, $original] = $holding(placeholderComFixtureName('bound-native-'.$action.'-placeholder.com'));
    config($nativePolicy);
    $binding = $original->capabilities()->binding;
    $setting = $product->server->settings()->firstOrNew(['key' => EnomTestManagement::KEY]);
    $setting->encrypted = true; $setting->type = 'string';
    $setting->value = json_encode(['fingerprint' => $binding->fingerprint, ...EnomTestManagement::protocol()]);
    $setting->save();
    app()->instance(Runtime::class, new Runtime);
    app()->instance(ReadLimiter::class, new WireLimit(1000));
    $factory = RuntimeFactory::make($binding, $bindings, $clock, new WriteFeatures(management: true),
        new WriteAcceptance($binding), new RenewalAcceptance($binding), new TransferPrecheck(new Rdap(static fn () => null)),
        static fn () => ['uid' => 'account-enom', 'token' => 'synthetic-offline-token'],
        managementAccepted: true, testManagement: true);
    ['management' => $engine, 'management_protocol' => $driver] = $factory();
    Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire([
        'reply' => static function (array $form) use ($exchange): string {
            $command = $form['Command'];
            unset($form['Command'], $form['UID'], $form['PW'], $form['ResponseType']);
            return $exchange('https://resellertest.enom.com/interface.asp', $command, $form, 25000);
        },
    ]);
    $state->apply = false;
    $op = $engine->request($user, $d->id, $action, $payload, hash('sha256', 'native-bound-'.$action), $driver);
    $baseline = EnomTestCalls::usage()['used'];
    for ($i = 1; $i <= 5; $i++) {
        $state->during = static function () use ($op): void { Cache::put('domains-write-slot:'.$op->account_fingerprint, true, 25); };
        same('queued', $engine->run($op->id, $driver)->state, 'Native mutation reservation survives a pre-seal deferral');
        same($baseline + 2 * $i, EnomTestCalls::usage()['used'], 'Deferred preparation reserves a read and an unsent mutation');
        same($i, count($GLOBALS['enomWire']['forms']), 'Only the preparation read reaches fake cURL');
        Cache::flush();
    }
    same('uncertain', $engine->run($op->id, $driver)->state, 'Sixth native preparation seals once');
    for ($i = 1; $i < 20; $i++) { $engine->reconcile($op->id, $driver); }
    same(47, count($GLOBALS['enomWire']['forms']), 'Physical lifetime maximum for '.$action);
    same($baseline + 52, EnomTestCalls::usage()['used'], 'Native reservation lifetime maximum for '.$action);
    same(1, count(array_filter($GLOBALS['enomWire']['forms'], static fn ($form) => in_array($form['Command'], ['SetRegLock', 'ModifyNS'], true))), 'Exactly one physical mutation');
    $engine->reconcile($op->id, $driver); $engine->run($op->id, $driver);
    same(47, count($GLOBALS['enomWire']['forms']), 'At the lifetime limit, no more physical sends');
    same($baseline + 52, EnomTestCalls::usage()['used'], 'At the lifetime limit, no more native reservations');
    app()->offsetUnset(Runtime::class);
    config($previousPolicy);
}
$nativeSuccessful = true;
echo "Management native lifetime bound: both actions use at most 47 physical sends and 52 reservations; at-limit refusal passed.\n";
