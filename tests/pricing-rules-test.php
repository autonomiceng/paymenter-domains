<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, PricingRule, PricingRules};
FoundationSchema::install();
$a = fixtureAccount(1, 'enom', 'account-a'); $b = fixtureAccount(2, 'namesilo', 'account-b');
foreach (['com', 'net', 'org'] as $ending) { Tld::create([...$a, 'tld' => $ending]); }
$override = Tld::create([...$a, 'tld' => 'io', 'use_default_rule' => false, 'pricing_mode' => 'markup_amount', 'markup' => '2.00']);
$other = Tld::create([...$b, 'tld' => 'com']);
$override->refresh(); $other->refresh();
$rules = new PricingRules;
same('30.00', $rules->rule($other)['markup'], 'Account starts with standard default');
$visited = [];
$result = $rules->saveDefaultRule(1, [...PricingRule::DEFAULT, 'markup' => '50'], static function (Tld $tld) use (&$visited): void {
    $visited[] = $tld->tld;
    if ($tld->tld === 'net') { throw new RuntimeException('Synthetic publication failure'); }
});
same(['com', 'net', 'org'], $visited, 'Default republication continues past failing ending');
same(['published' => 2, 'failed' => ['.net'], 'failure_types' => ['.net' => RuntimeException::class]], $result, 'Partial failure reported');
same('50.00', $rules->rule(Tld::where('server_id', 1)->where('tld', 'com')->firstOrFail())['markup'], 'Shared account default');
same('30.00', $rules->rule($other)['markup'], 'Other account unaffected');
same('2.00', $rules->rule($override)['markup'], 'Per-TLD override unaffected');
// Read persisted settings directly: an unbound server cannot use defaultRule().
$unbound = Server::create(['id' => 3, 'name' => 'Unbound registrar', 'extension' => 'Domains', 'type' => 'server', 'enabled' => true]);
$unbound->settings()->create(['key' => 'domains_pricing_default_rule', 'type' => 'array', 'encrypted' => false,
    'value' => [...PricingRule::DEFAULT, 'markup' => '17.00']]);
// An orphaned default-rule ending makes "does not republish" meaningful; Tld::create() refuses an unbound server.
DB::table('domains_tlds')->insert(['server_id' => 3, 'registrar_key' => 'enom', 'account_fingerprint' => str_repeat('0', 64),
    'tld' => 'com', 'use_default_rule' => true, 'created_at' => now(), 'updated_at' => now()]);
same(1, Tld::where('server_id', 3)->where('use_default_rule', true)->count(), 'Unbound server has a default-rule ending');
$storedDefaults = static fn (): array => DB::table('settings')->where('key', 'domains_pricing_default_rule')->orderBy('id')->get()
    ->map(static fn (object $setting): array => (array) $setting)->all();
$rejectDefaultSave = static function (int $serverId, array $rule, string $exception, string $message, string $label) use ($rules, $storedDefaults): void {
    $before = $storedDefaults(); $called = false; $caught = null;
    try {
        $rules->saveDefaultRule($serverId, $rule, static function (Tld $tld) use (&$called): void { $called = true; });
    } catch (Throwable $error) { $caught = [$error::class, $error->getMessage()]; }
    same([$exception, $message], $caught, $label.' throws the expected rejection');
    same(false, $called, $label.' does not republish');
    same($before, $storedDefaults(), $label.' preserves stored defaults');
};
$replacement = [...PricingRule::DEFAULT, 'markup' => '75.00'];
$rejectDefaultSave(1, [...$replacement, 'round_up' => 'bogus'], InvalidArgumentException::class,
    'Choose a pricing mode and rounding rule.', 'Invalid default rule');
DB::transaction(static function () use ($rejectDefaultSave, $replacement): void {
    // Check before the outer transaction ends, so rollback cannot hide a write.
    $rejectDefaultSave(1, $replacement, LogicException::class,
        'Default republication requires independent transactions.', 'Open transaction');
});
$rejectDefaultSave(3, $replacement, DomainException::class, 'Bound Domains account required.', 'Unbound server');
same(0, App\Models\Invoice::count(), 'Rule publication creates no financial records');
$nativeSuccessful = true;
echo "Per-account rule and republication checks passed.\n";
