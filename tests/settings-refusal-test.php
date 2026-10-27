<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions;

$cases = [
    'Review the live Enom Purchase route and request interval.' => 'Check the box confirming the live Enom Purchase route and request interval, then try again. Nothing was saved.',
    'Live Enom Purchase route acceptance is unavailable.' => 'Live Enom Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
    'Independent Live renewal review required.' => 'Check the box confirming the live Extend route, request interval and USD balance. Nothing was saved.',
    'Live renewal deployment is unavailable.' => 'Live renewal is unavailable for this account or deployment. Reload the account page and check the Renewals row. Nothing was saved.',
    'Independent Live management review required.' => 'Check the box confirming the supported live management actions, endpoint and request interval. Nothing was saved.',
    'Live management deployment is unavailable.' => 'Live management is unavailable for this account or deployment. Reload the account page and check the DNS, nameservers and contacts row. Nothing was saved.',
    'Review the Enom TEST Purchase / TP_CreateOrder route and request interval before accepting acquisition terms.' => 'Check the box confirming the Enom TEST Purchase / TP_CreateOrder route and request interval, then try again. Nothing was saved.',
    'Enom TEST acquisition route acceptance is unavailable.' => 'Enom TEST acquisition acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
    'Independent TEST renewal review required.' => 'Check the box confirming the TEST Extend route, request interval and USD balance. Nothing was saved.',
    'TEST renewal deployment is unavailable.' => 'TEST renewal is unavailable for this account or deployment. Reload the account page and check the Renewals row. Nothing was saved.',
    'Independent TEST management review required.' => 'Check the box confirming the supported TEST management actions, endpoint and request interval. Nothing was saved.',
    'TEST management deployment is unavailable.' => 'TEST management is unavailable for this account or deployment. Reload the account page and check the DNS, nameservers and contacts row. Nothing was saved.',
    'Review the Enom TEST Purchase route and request interval before accepting registration terms.' => 'Check the box confirming the Enom TEST Purchase route and request interval, then try again. Nothing was saved.',
    'Enom TEST Purchase route acceptance is unavailable.' => 'Enom TEST Purchase acceptance is unavailable on this deployment; contact the deployment administrator. Nothing was saved.',
];
foreach ($cases as $message => $guidance) {
    same($guidance, SettingsActions::refusal(new DomainException($message), 'acceptance'), 'Known refusal gives safe administrator guidance: '.$message);
}
same('Reload and check current permissions, saved facts and acceptance.', SettingsActions::refusal(new DomainException('Unrecognized private synthetic detail'), 'acceptance'), 'Unknown refusal deliberately uses generic guidance');
// Confirmation, stale-record and audit refusals deliberately share safe reload guidance.
foreach ([
    'Explicit notification check confirmation required.',
    'Current notification check alert required.',
    'Explicit notification confirmation required.',
    'Current notification claim required.',
    'Explicit processing confirmation required.',
    'Independent history verification required.',
    'History action unsupported.',
    'Integer history limits required.',
    'TLD binding mismatch.',
    'TLD required.',
    'Valid term bounds required.',
    'Explicit verified widening required.',
    'Retained holders require annual renewal support.',
    'Explicit restoration required.',
    'Unsupported settings action.',
    'Repair must preserve all holder renewal acceptance.',
    'Independent term verification required.',
    'Holder renewal acceptance cannot be inferred.',
    'Unknown settings action.',
    'TLD account changed.',
    'Verified in-flight review required.',
    'Audit unavailable. Changes held.',
    'Audit entry too large. Changes held.',
    'Audit archive too large. Changes held.',
] as $message) {
    same('Reload and check current permissions, saved facts and acceptance.', SettingsActions::refusal(new DomainException($message), 'acceptance'), 'Deliberate generic refusal: '.$message);
}
echo "Live and TEST refusal guidance and unknown fallback passed.\n";

foreach(['card_fee','default_rule','override'] as $action){
    same('This makes a price too large to save. Lower the markup or the card fee. Nothing was changed.',SettingsActions::refusal(new InvalidArgumentException('The calculated price is too large.'),$action),'Price overflow gives the correct remedy');
    same('No price can cover the card fee with this markup. Lower the markup or the card fee. Nothing was changed.',SettingsActions::refusal(new InvalidArgumentException('This percentage cannot cover the card fee. Lower the markup or card fee.'),$action),'Impossible fee gives the correct remedy');
}
