<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions;

$cases = [
    'Live Enom Purchase route acceptance is unavailable.' => 'This account can’t take orders on this site yet. Ask your site admin. Nothing was saved.',
    'Independent Live renewal review required.' => 'Tick the box to show you checked this on the live account. Nothing was saved.',
    'Live renewal deployment is unavailable.' => 'Live renewals are off for this account or site. Check the Renewals row on the account page. Nothing was saved.',
    'Independent Live management review required.' => 'Tick the box to show you checked this on the live account. Nothing was saved.',
    'Live management deployment is unavailable.' => 'Live changes are off for this account or site. Check the DNS, nameservers and contacts row on the account page. Nothing was saved.',
    'Enom TEST acquisition route acceptance is unavailable.' => 'This account can’t take orders on this site yet. Ask your site admin. Nothing was saved.',
    'Independent TEST renewal review required.' => 'Tick the box to show you checked this on the test account. Nothing was saved.',
    'TEST renewal deployment is unavailable.' => 'Test renewals are off for this account or site. Check the Renewals row on the account page. Nothing was saved.',
    'Independent TEST management review required.' => 'Tick the box to show you checked this on the test account. Nothing was saved.',
    'TEST management deployment is unavailable.' => 'Test changes are off for this account or site. Check the DNS, nameservers and contacts row on the account page. Nothing was saved.',
    'Enom TEST Purchase route acceptance is unavailable.' => 'This account can’t take orders on this site yet. Ask your site admin. Nothing was saved.',
];
foreach ($cases as $message => $guidance) {
    same($guidance, SettingsActions::refusal(new DomainException($message), 'acceptance'), 'Known refusal gives safe administrator guidance: '.$message);
}
same('Check this page, then try again. If it fails again, open System checks on the registrar account.', SettingsActions::refusal(new DomainException('Unrecognized private synthetic detail'), 'acceptance'), 'Unknown refusal deliberately uses generic guidance');
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
    'Audit archive too large. Changes held.',
] as $message) {
    same('Check this page, then try again. If it fails again, open System checks on the registrar account.', SettingsActions::refusal(new DomainException($message), 'acceptance'), 'Deliberate generic refusal: '.$message);
}
same('Check this page, then try again. If it fails again, open System checks on the registrar account.', SettingsActions::refusal(new DomainException('Audit entry too large. Changes held.'), 'seller'), 'Audit size stays generic elsewhere');
same('This was already submitted. Check this page, then try again if you need to.', SettingsActions::refusal(new DomainException('Review key used.'), 'tld_settings'), 'A used confirmation says so');
same('That is too many changes for one save. Save fewer at a time. Nothing was saved.', SettingsActions::refusal(new DomainException('Audit entry too large. Changes held.'), 'acceptance'), 'What you sell says how to fix a large save');
echo "Live and TEST refusal guidance and unknown fallback passed.\n";

foreach(['card_fee','default_rule','override'] as $action){
    same('This makes a price too large to save. Lower the markup or the card fee. Nothing was changed.',SettingsActions::refusal(new InvalidArgumentException('The calculated price is too large.'),$action),'Price overflow gives the correct remedy');
    same('No price can cover the card fee with this markup. Lower the markup or the card fee. Nothing was changed.',SettingsActions::refusal(new InvalidArgumentException('This percentage cannot cover the card fee. Lower the markup or card fee.'),$action),'Impossible fee gives the correct remedy');
}

same('Enter a card fee from 0% to 99.99% and a fixed amount from $0 to $100,000, with up to two decimal places. Nothing was changed.',SettingsActions::refusal(new InvalidArgumentException('Enter a card fee below 100% and a fixed amount from 0 to 100000.'),'card_fee'),'Card fee input refusal gives exact range and decimal guidance');
same('Check this page, then try again. If it fails again, open System checks on the registrar account.',SettingsActions::refusal(new InvalidArgumentException('Enter a card fee below 100% and a fixed amount from 0 to 100000.'),'default_rule'),'Card fee input refusal keeps the generic default pricing fallback');
