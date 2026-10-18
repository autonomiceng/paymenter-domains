<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;

function fixtureAccount(int $serverId, string $registrar, string $identity): array
{
    App\Models\Server::create(['id' => $serverId, 'name' => 'Synthetic registrar', 'extension' => 'Domains', 'type' => 'server', 'enabled' => true]);
    $account = Account::withVerifiedBindingWrite(static fn () => Account::create(['server_id' => $serverId, 'registrar_key' => $registrar, 'environment' => Environment::Test->value, 'stable_identity' => $identity]));
    return ['server_id' => $serverId, 'registrar_key' => $registrar, 'account_fingerprint' => $account->account_fingerprint];
}
