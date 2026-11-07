<?php
// Synthetic cURL and account data only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/enom-test-counter.php';

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand};
use Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimit;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;

$command = ReadCommand::tryFrom('GetAllDomains');
expect($command instanceof ReadCommand, 'Nameserver-filtered account domains have a typed read command');
$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
$client = new Client($binding, 'synthetic-uid', 'SYNTHETIC-TOKEN');
$clock = $GLOBALS['enomTestCallClock'];
$context = static fn (int $limit = 1) => new RequestContext($binding, RequestOrigin::Background,
    $clock->now()->modify('+30 seconds'), new RequestBudget($limit), $clock);
$parameters = ['NameServer' => 'ns1.example.com'];
$events = [];
config(['domains.provider_send_guard' => static function ($registrar, $environment, $named) use (&$events): void {
    $events[] = [$registrar, $environment, $named];
}]);
resetWire(['chunks' => ['<interface-response><Command>GetAllDomains</Command><ErrCount>0</ErrCount><Done>true</Done><GetAllDomains><DomainDetail><DomainName>example.com</DomainName></DomainDetail></GetAllDomains></interface-response>']]);
$before = EnomTestSendLimit::usage('synthetic-uid')['day'];
$reply = $client->read($command, $parameters, $context());
same('success', $reply->status, 'Filtered domain read is admitted');
same([['enom', 'test', 'GetAllDomains']], $events, 'Physical read passes through the send guard');
same(1, count($GLOBALS['enomWire']['forms']), 'Read sends once without retries');
same($before + 1, EnomTestSendLimit::usage('synthetic-uid')['day'], 'Read uses the normal TEST call limit');

$nativeSuccessful = true;
echo "Nameserver-filtered account domain read passed.\n";
