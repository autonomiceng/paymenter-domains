<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;

function fixtureAccount(int $serverId, string $registrar, string $identity): array
{
    App\Models\Server::create(['id' => $serverId, 'name' => 'Synthetic registrar', 'extension' => 'Domains', 'type' => 'server', 'enabled' => true]);
    $account = Account::withVerifiedBindingWrite(static fn () => Account::create(['server_id' => $serverId, 'registrar_key' => $registrar, 'environment' => Environment::Test->value, 'stable_identity' => $identity]));
    // Generic arithmetic fixtures retain their known percentage rule; real connections cover the new default.
    App\Models\Setting::updateOrCreate(['key'=>'domains_pricing_default_rule','settingable_type'=>null,'settingable_id'=>null],
        ['type' => 'array', 'encrypted' => false, 'value' => \Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT]);
    return ['server_id' => $serverId, 'registrar_key' => $registrar, 'account_fingerprint' => $account->account_fingerprint];
}
