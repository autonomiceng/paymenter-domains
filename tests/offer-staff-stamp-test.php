<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture: background publication and price or terms reads never make a staff save look like someone else's change.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Facts, FactsObserved, Intent, Query, StaffCommands};
extract(offerCommerceFixture());
$q = new Query($binding, ['com'], 'register');
$rev = static fn () => offerCommerceAsStaff(static fn () => $ports->readOffers($q, Context::Staff)[0]->revision);
$stamp = StaffCommands::stamp($binding->serverId);
$ports->apply(Edit::republish($binding, 'com'), $rev());
same($stamp, StaffCommands::stamp($binding->serverId), 'Background publication does not look like a staff change');
$clock->instant = $now->modify('+1 minute');
$ports->apply(new FactsObserved($binding, 'com', new Facts(['register' => [1, 3], 'renew' => [1], 'transfer' => [1]],
    [], true, false, false, 'synthetic', $clock->now(), $now->modify('+1 day'))), $rev());
same($stamp, StaffCommands::stamp($binding->serverId), 'A newer terms read does not look like a staff change');
// A staff choice still does. Account defaults are guarded by their own revision when saved.
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 2, transfers: true)), $rev());
expect($stamp !== StaffCommands::stamp($binding->serverId), 'A TLD choice is a staff change');
$nativeSuccessful = true;
