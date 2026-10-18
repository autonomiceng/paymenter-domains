<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
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
same(0, App\Models\Invoice::count(), 'Rule publication creates no financial records');
$nativeSuccessful = true;
echo "Per-account rule and republication checks passed.\n";
