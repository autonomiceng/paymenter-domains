<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CredentialFeed, OfferPorts, Projection, Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, EnomTestCallLimit, EnomTestSendLimit, EnomTestSendLimitSchema, Installation, NativeCatalogue, PriceCatalogue, PricingRules, RegistrarRegistry};
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
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>false]);
same(0, $ports->allowance($binding, RequestOrigin::Background), 'Identity-only deployment cannot admit automatic offer reads');
try { $ports->feed($binding); throw new LogicException('Catalogue-off feed constructed'); } catch (DomainException) {}
config(['domains.enom_catalogue.enabled'=>true]);
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
foreach (['domains.enom_catalogue.enabled'=>false,'domains.enom_identity.environment'=>'live'] as $key=>$disabled) {
    $saved=config($key);config([$key=>$disabled]);
    try { $transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-first', 1000); throw new LogicException('Disabled deployment sent'); } catch (DomainException) {}
    same(0,$wire->calls,'Physical offer sends recheck catalogue and identity deployment opt-ins');config([$key=>$saved]);
}
same('synthetic response', $transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-first', 1000), 'A current offer transport reaches only the explicit fake wire');
$bindings->configure($server->id, 'enom', Environment::Test, ['uid' => 'offer-fixture', 'token' => 'synthetic-second']);
expect($revision !== $ports->revision($binding), 'Verified rotation changes the offer credential revision without changing account identity');
try { $bindings->offerCredentials($binding, $revision); throw new LogicException('Old revision accepted'); }
catch (DomainException) {}
try { $transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-first', 1000); throw new LogicException('Old transport sent'); }
catch (DomainException) {}
same(1, $wire->calls, 'Same-identity rotation refuses the old transport before another physical read');
$currentGeneration = 4;
$capturedGeneration = $currentGeneration;
$generationGuard = static function () use (&$currentGeneration, $capturedGeneration): void {
    if ($currentGeneration !== $capturedGeneration) { throw new DomainException('Synthetic generation changed.'); }
};
$transport = new OfferTransport($bindings, $binding, $ports->revision($binding), $wire, $generationGuard);
$transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-second', 1000);
$currentGeneration++;
$refusedGeneration = false;
try { $transport->send(Environment::Test, ReadCommand::Tlds, [], 'offer-fixture', 'synthetic-second', 1000); }
catch (DomainException) { $refusedGeneration = true; }
same(true, $refusedGeneration, 'Generation fence is rechecked for every physical read in a group');
same(2, $wire->calls, 'A changed generation refuses before a second physical wire send');
config([EnomTestSendLimit::PER_DAY => 'bad']);
same(0, $ports->allowance($binding, RequestOrigin::Background), 'Unreadable local allowance fails closed');
// The factory's worker must reserve our cumulative minute cap for manual Sync as well as daily work.
config([EnomTestSendLimit::PER_DAY => 1000, EnomTestSendLimit::PER_MINUTE => 60]);
$normalMinute = new AccountReadBudget($clock);
$lease = $normalMinute->reserve($binding, RequestOrigin::Interactive, 25);
$normalMinute->release($binding, RequestOrigin::Interactive, $lease);
DB::table(EnomTestSendLimitSchema::SENDS)->insert(array_fill(0, 25,
    ['account' => EnomTestSendLimit::account('offer-fixture'), 'command' => 'Check', 'sent_at' => $clock->now()->getTimestamp() - 5]));
$attempts = 0;
config(['domains.provider_send_guard' => static function () use (&$attempts): never {
    $attempts++; throw new RuntimeException('Synthetic no-send boundary');
}]);
$writer = new Writer(new Projection(new NativeCatalogue(new PriceCatalogue(new PricingRules))), $clock,
    static function (): void {}, static fn () => false);
$worker = $ports->worker($writer);
foreach ([RequestOrigin::Background, RequestOrigin::Interactive] as $origin) {
    $worker->sync($binding, $origin); $worker->tick(1);
    same(25, (int) DB::table('domains_read_budgets')->where('server_id', $binding->serverId)->value('used'), 'Catalogue '.$origin->value.' cannot reserve beyond our 25-read account minute ceiling');
    same(null, $worker->status($binding)['tried_at'], 'Exhausted catalogue minute defers before any feed attempt');
}
same(0, $attempts, 'Minute admission refuses both origins before reaching the no-send wire guard');
$lease = $normalMinute->reserve($binding, RequestOrigin::Interactive, 1);
$normalMinute->release($binding, RequestOrigin::Interactive, $lease);
same(26, (int) DB::table('domains_read_budgets')->where('server_id', $binding->serverId)->value('used'), 'Ordinary interactive checkout retains its normal allowance above the catalogue ceiling');
config(['domains.provider_send_guard' => null]);
// Our catalogue share counts catalogue sends only; the whole day still stops at our cap.
$account = EnomTestSendLimit::account('offer-fixture');
$reset = static function (int $day, int $older) use ($account, $clock): void {
    DB::table(EnomTestSendLimitSchema::SENDS)->where('account', $account)->delete();
    config([EnomTestSendLimit::PER_DAY => $day, EnomTestSendLimit::PER_MINUTE => 100000]);
    // Rows written before catalogue sends were marked, including old catalogue reads, count as other work.
    DB::table(EnomTestSendLimitSchema::SENDS)->insert(array_map(static fn (int $i) => ['account' => $account,
        'command' => $i % 2 ? 'GetTLDList' : 'Check', 'sent_at' => $clock->now()->getTimestamp() - 120], range(1, $older)));
};
// The real feed's read scope, driven without a provider send.
$scope = (new ReflectionProperty(CredentialFeed::class, 'scope'))->getValue($ports->feed($binding));
$sends = static function (int $count, bool $catalogue) use ($scope): int {
    $admit = static fn () => EnomTestSendLimit::admit(Environment::Test, 'offer-fixture', 'GetTLDList');
    for ($sent = 0; $sent < $count; $sent++) {
        try { $catalogue ? $scope($admit) : $admit(); } catch (EnomTestCallLimit) { return $sent; }
        same(0, DB::transactionLevel(), 'A physical send happens after the atomic admission transaction');
    }
    return $sent;
};
$reset(1000, 909);
same(25, $ports->allowance($binding, RequestOrigin::Interactive), 'Catalogue reads still go at 909 of 1000 sends when none are catalogue sends');
same(10, $sends(10, true), 'Catalogue sends go at 909 of 1000 total sends');
same(919, EnomTestSendLimit::usage('offer-fixture')['day'], 'Each catalogue send counts once toward the whole day');
same(25, $ports->allowance($binding, RequestOrigin::Interactive), 'Catalogue reads still go at 919 of 1000 sends with 10 catalogue sends');
$reset(100, 50);
same(30, $sends(31, true), 'Catalogue sends stop once they reach our 30% share');
same(0, $ports->allowance($binding, RequestOrigin::Interactive), 'No catalogue allowance once catalogue sends reach our share');
same(20, $sends(25, false), 'Other work still goes after the catalogue share is used, up to our cap');
$reset(100, 95);
same(5, $ports->allowance($binding, RequestOrigin::Interactive), 'Catalogue allowance also stops at the room left under our cap');
same(5, $sends(6, true), 'Catalogue sends stop at our cap even under their share');
same([0, 0], [$sends(1, false), $ports->allowance($binding, RequestOrigin::Interactive)], 'Everything stops at our cap');
same(100, EnomTestSendLimit::usage('offer-fixture')['day'], 'Refused sends are not counted');
echo "offer ports: ok\n";
$nativeSuccessful = true;
