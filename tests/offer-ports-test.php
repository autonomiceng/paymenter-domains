<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferPorts;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomTestSendLimit, EnomTestSendLimitSchema, Installation, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\OfferTransport;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, Transport};

expect(is_file(dirname(__DIR__).'/Domains/Catalogue/OfferPorts.php'), 'Production offer ports compose verified credentials and our admitted read allowance');
(new Installation)->install();
$registry = new RegistrarRegistry;
$registry->register('enom', ['uid', 'token'], new class implements AccountVerifier {
    public function verify(Environment $environment, array $credentials): string { return $credentials['uid']; }
}, static fn () => throw new LogicException('No provider reader may be resolved.'));
$bindings = new AccountBindings($registry);
$server = Server::create(['name' => 'Synthetic offer account', 'extension' => 'Domains', 'type' => 'server', 'enabled' => true]);
$binding = $bindings->configure($server->id, 'enom', Environment::Test, ['uid' => 'offer-fixture', 'token' => 'synthetic-first']);
$clock = new class implements Clock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-02T20:00:00Z'); }
};
app()->instance(Clock::class, $clock);
$ports = new OfferPorts($bindings, $clock);
config([EnomTestSendLimit::PER_DAY => 100, EnomTestSendLimit::PER_MINUTE => 60]);
same(25, $ports->allowance($binding, RequestOrigin::Background), 'Our catalogue allowance also reserves minute headroom');
DB::table(EnomTestSendLimitSchema::ACCOUNTS)->insertOrIgnore(['account' => EnomTestSendLimit::account('offer-fixture')]);
DB::table(EnomTestSendLimitSchema::SENDS)->insert(['account' => EnomTestSendLimit::account('offer-fixture'), 'command' => 'Check', 'sent_at' => $clock->now()->getTimestamp() - 120]);
same(25, $ports->allowance($binding, RequestOrigin::Interactive), 'Manual sync shares catalogue headroom instead of consuming checkout capacity');
$revision = $ports->revision($binding);
same(5, $ports->feed($binding)->priceReads(), 'The real Enom factory composes the bounded five-read feed without sending');
$wire = new class implements Transport {
    public int $calls = 0;
    public function send(Environment $environment, ReadCommand $command, array $parameters, string $uid, string $token, int $timeoutMs): string
    { $this->calls++; return 'synthetic response'; }
};
$transport = new OfferTransport($bindings, $binding, $revision, $wire);
same('synthetic response', $transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-first', 1000), 'A current offer transport reaches only the explicit fake wire');
$bindings->configure($server->id, 'enom', Environment::Test, ['uid' => 'offer-fixture', 'token' => 'synthetic-second']);
expect($revision !== $ports->revision($binding), 'Verified rotation changes the offer credential revision without changing account identity');
try { $bindings->offerCredentials($binding, $revision); throw new LogicException('Old revision accepted'); }
catch (DomainException) {}
try { $transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-first', 1000); throw new LogicException('Old transport sent'); }
catch (DomainException) {}
same(1, $wire->calls, 'Same-identity rotation refuses the old transport before another physical read');
$rows = array_fill(0, 29, ['account' => EnomTestSendLimit::account('offer-fixture'), 'command' => 'Check', 'sent_at' => $clock->now()->getTimestamp() - 120]);
DB::table(EnomTestSendLimitSchema::SENDS)->insert($rows);
same(0, $ports->allowance($binding, RequestOrigin::Interactive), 'Our catalogue share counts other origins and leaves checkout headroom');
config([EnomTestSendLimit::PER_DAY => 'bad']);
same(0, $ports->allowance($binding, RequestOrigin::Background), 'Unreadable local allowance fails closed');
echo "offer ports: ok\n";
$nativeSuccessful = true;
