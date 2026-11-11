<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, PricingRule, PricingRules};
// Default-rule writes require an active catalogue generation, even in this arithmetic fixture.
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
$a = fixtureAccount(1, 'enom', 'account-a'); $b = fixtureAccount(2, 'namesilo', 'account-b');
foreach (['com', 'net', 'org'] as $ending) { Tld::create(tldColumns([...$a, 'tld' => $ending])); }
$override = Tld::create(tldColumns([...$a, 'tld' => 'io', 'use_default_rule' => false, 'pricing_mode' => 'markup_amount', 'markup' => '2.00']));
$other = Tld::create(tldColumns([...$b, 'tld' => 'com']));
$override->refresh(); $other->refresh();
$rules = new PricingRules;
same('30.00', $rules->rule($other)['markup'], 'Account starts with standard default');
saveDefaultRule([...PricingRule::DEFAULT, 'markup' => '50']);
same('50.00', $rules->rule(Tld::where('server_id', 1)->where('tld', 'com')->firstOrFail())['markup'], 'Followers read the business rule');
same('50.00', $rules->rule($other)['markup'], 'Every account shares the rule');
same('2.00', $rules->rule($override)['markup'], 'Per-TLD override unaffected');
// Read snapshots are supplied by a list, never retained as authority by the rule service.
DB::beginTransaction();
try {
    $server = Server::findOrFail(1);
    fixtureBusinessChoice('domains_pricing_default_rule',['mode'=>'markup_fee_percent','markup'=>'30','round_up'=>'none']);
    $custom = $override->fresh(); $custom->pricing_mode = 'markup_fee_percent';
    $default = Tld::where('server_id', 1)->where('tld', 'com')->firstOrFail();
    $snapshot = ['percent' => '7.00', 'fixed' => '0.70'];
    same($snapshot, $rules->rule($custom, false, $snapshot)['card_fee'], 'Custom rule uses the supplied account fee');
    same($snapshot, $rules->defaultRule(false, $snapshot)['card_fee'], 'Default rule uses the supplied account fee');
    same($snapshot, $rules->rule($default, false, $snapshot)['card_fee'], 'Default follower forwards the account fee');
    $fresh = ['percent' => '3.00', 'fixed' => '0.40'];
    \Paymenter\Extensions\Servers\Domains\Services\CardFee::saveLocked($fresh);
    same($fresh, $rules->rule($custom, true)['card_fee'], 'Locked custom rule reads the current fee after a list snapshot');
    same($fresh, $rules->rule($default, true)['card_fee'], 'Locked default follower reads the current fee after a list snapshot');
} finally { DB::rollBack(); }
same(0, App\Models\Invoice::count(), 'Rule publication creates no financial records');
$nativeSuccessful = true;
echo "Shared rule checks passed.\n";
