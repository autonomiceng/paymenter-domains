<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/FakeRegistrar.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, Disposition, DriverResult, Environment, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, FoundationSchema, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
$reject = static function (callable $call): void {
    try { $call(); expect(false, 'Binding safety refusal escaped'); }
    catch (DomainException|InvalidArgumentException|Illuminate\Database\QueryException|LogicException $e) { /* expected */ }
};
$verifier = new class implements AccountVerifier {
    public int $calls = 0;
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string
    {
        same(0, DB::transactionLevel(), 'No provider verification under database locks'); $this->calls++;
        return match ($credentials['token'] ?? $credentials['api_key'] ?? '') {
            'private-rotation-credential' => 'account-b', 'A1', 'A2' => 'account-a', 'A3' => 'ACCOUNT-A', 'B1' => 'account-b',
            default => throw new RuntimeException('private-provider-detail'),
        };
    }
};
$factory = static fn (AccountBinding $b, array $credentials): FakeRegistrar => new FakeRegistrar($b,
    new Capabilities($b, []), new DriverResult($b, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unverified));
$registry = new RegistrarRegistry;
$registry->register('enom', ['uid', 'token'], $verifier, $factory);
$registry->register('namesilo', ['api_key'], $verifier, $factory);
$reject(static fn () => $registry->register('enom', ['token'], $verifier, $factory));
$reject(static fn () => $registry->register('other', ['token'], $verifier, $factory));
$bindings = new AccountBindings($registry);
