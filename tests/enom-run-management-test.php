<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Offline production transport with namespace-local cURL fakes; no sockets.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/enom-run-budget.php';
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
$actions=['lock'=>['locked'=>false],'nameservers'=>['nameservers'=>['ns3.example.com','ns4.example.com']]];
$holdings=[];foreach($actions as $action=>$payload){$holdings[$action]=$holding(placeholderComFixtureName('run-native-'.$action.'-placeholder.com'));}
foreach($actions as $action=>$payload){
    [$d,$original]=$holdings[$action];
    $state->name=$d->domain;Cache::flush();$state->locked=true;$state->ns=['ns1.example.com','ns2.example.com'];
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
    $policy=runPolicy('management_'.$action,60,120,$binding->fingerprint);
    \Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudgetSchema::install();
    \Paymenter\Extensions\Servers\Domains\Services\EnomTestLegacyBudgetSchema::install();
    config(['domains.enom_test_run_policy'=>$policy]);
    $runClock=$clock; // Share the management fixture's clock with the paired monotonic fixture.
    if($action==='lock'){$clock->instant=new DateTimeImmutable('2026-10-18T23:59:59Z');}
    \Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudget::prepare($policy,60);
    \Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudget::activate($policy);
    config(['domains.provider_send_guard'=>static function():void{
        $permit=\Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudget::pendingPermit();
        expect(is_string($permit),'Real management host guard sees original permit');
        \Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudget::assertPermit($permit);
        runAdvance('+2 seconds');
    }]);
    $baseline = EnomTestCalls::usage()['used'];
    for ($i = 1; $i <= 5; $i++) {
        $state->during = static function () use ($op): void { Cache::put('domains-write-slot:'.$op->account_fingerprint, true, 25); };
        same('queued', $engine->run($op->id, $driver)->state, 'Native mutation reservation survives a pre-seal deferral');
        same($baseline + $i, EnomTestCalls::usage()['used'], 'Deferred preparation consumes only the physical read');
        same($i, count($GLOBALS['enomWire']['forms']), 'Only the preparation read reaches fake cURL');
        Cache::flush();
    }
    same('uncertain', $engine->run($op->id, $driver)->state, 'Sixth native preparation seals once');
    for ($i = 1; $i < 20; $i++) { $engine->reconcile($op->id, $driver); }
    same(47, count($GLOBALS['enomWire']['forms']), 'Physical lifetime maximum for '.$action);
    same($baseline + 47, EnomTestCalls::usage()['used'], 'Native physical attempt lifetime maximum for '.$action);
    same(1, count(array_filter($GLOBALS['enomWire']['forms'], static fn ($form) => in_array($form['Command'], ['SetRegLock', 'ModifyNS'], true))), 'Exactly one physical mutation');
    $engine->reconcile($op->id, $driver); $engine->run($op->id, $driver);
    same(47, count($GLOBALS['enomWire']['forms']), 'At the lifetime limit, no more physical sends');
    same($baseline + 47, EnomTestCalls::usage()['used'], 'At the lifetime limit, no more native attempts');
    \Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudget::disable($policy);
    \Paymenter\Extensions\Servers\Domains\Services\EnomTestRunBudget::close($policy);
    config(['domains.enom_test_run_policy'=>null,'domains.provider_send_guard'=>null]);
    app()->offsetUnset(Runtime::class);
    config($previousPolicy);
}
$nativeSuccessful = true;
echo "Version 2 real management engine: midnight, financial seal, deferrals, original mutation permit and 47-attempt bound passed.\n";
