<?php
// Adapted from Enom tests/enom-native-app.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
// Laravel may render an uncaught exception with status zero; require explicit suite completion.
$nativeSuccessful = false;
register_shutdown_function(static function () use (&$nativeSuccessful): void { if (!$nativeSuccessful) { exit(1); } });

$fixture = (string) getenv('PAYMENTER_TEST_FIXTURE');
expect($fixture !== '' && realpath($fixture) === $fixture && !file_exists($fixture.'/.env')
    && !file_exists($fixture.'/bootstrap/cache/config.php'), 'Runner-created clean fixture required');
$marker = json_decode(file_get_contents($fixture.'/.domains-fixture.json'), true, 8, JSON_THROW_ON_ERROR);
same('ee729f7d99ac18942625b79f3f93d8d8b21255e8', $marker['commit'] ?? null, 'Pinned Paymenter source');
same('fd6bdeb5557b3af0976d5688d92081abc6f7c3235806a6c4b77f4b8bca9186be',
    hash_file('sha256', $fixture.'/composer.lock'), 'Pinned native dependency lock');
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'APP_URL' => 'http://domains-tests.invalid', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array', 'TELEMETRY_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$loader = require $fixture.'/vendor/autoload.php';
$loader->addPsr4('App\\', $fixture.'/app/', true);
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\', dirname(__DIR__).'/Domains/', true);
$app = require $fixture.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['hashing.bcrypt.rounds' => 4, 'audit.enabled' => false, 'settings.mail_disabled' => true,
    'settings.default_currency' => 'USD', 'settings.tax_enabled' => false, 'settings.theme' => 'default']);
same('sqlite', config('database.default'), 'Native database isolation');
same(':memory:', config('database.connections.sqlite.database'), 'Native database is memory only');
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

// Public-data updater also uses an explicit fake: native runtime tests must never resolve DNS.
$app->instance(\Paymenter\Extensions\Servers\Domains\Services\PslTransport::class,
    new \Paymenter\Extensions\Servers\Domains\Services\PslTransport(static function (): never {
        throw new \Paymenter\Extensions\Servers\Domains\Services\PslFailure('transport_failed');
    }));
