<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native reviewed writes and their visible history.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,StaffCommands};
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
$fixture = offerCommerceFixture(); extract($fixture);
[$netBinding, $netOffer] = offerCommerceSecond($fixture);
$actor = staffUser(array_keys(Staff::permissions())); Auth::setUser($actor);
$t = $tlds[0];
$save = static function (array $sell, $target = null) use ($actor, $t): void {
    $t = $target ?? $t;
    $r = new Review($actor->id, $t->server_id, null, 'acceptance',
        Review::ticket($actor->id, $t->server_id, null, 'acceptance', StaffCommands::stamp($t->server_id)), '');
    app(StaffSettingsWrites::class)->run($r, ['sell' => $sell]);
};
$save(['terms' => ['com' => ['transfer' => false]]]);
$history = TldFacts::history($t->server_id, $t->id);
same('.com: transfers off', $history[0]['reason'], 'Transfers-only history names only the changed field');
$intent = OfferState::decode($t->fresh()->catalogue_intent);
same(true, $intent['sell'], 'Transfer edit preserves sell'); same(3, $intent['longest'], 'Transfer edit preserves term');
// A choice-only save has its TLD in account history too, including a single-field no-op.
foreach (['skip' => "Don't sell", 'sell' => 'Sell'] as $choice => $label) {
    $net = Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id', $netBinding->serverId)->where('tld', 'net')->sole();
    $save(['choices' => ['net' => $choice]], $net);
    $history = TldFacts::history($net->server_id, null);
    same('.net: '.$label, $history[0]['reason'], 'Choice history keeps its TLD and only its changed choice');
    expect(!str_contains($history[0]['reason'], 'not recorded'), 'Current saves retain exact scope');
}
$save(['terms' => ['com' => ['transfer' => false]]]);
$history = TldFacts::history($t->server_id, null);
same('No sales settings changed', $history[0]['change'], 'No-op does not claim a sales change');
same('', $history[0]['reason'], 'No-op invents no changed fields');
$nativeSuccessful = true; echo "Sales history records only actual changes with the TLD.\n";
